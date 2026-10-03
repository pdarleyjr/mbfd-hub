import hashlib
import contextlib
import importlib.util
import io
import json
from email.parser import BytesParser
from email.policy import default
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("ingest", Path(__file__).with_name("ingest.py"))
ingest = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ingest)


class SogIngestionTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        source = self.root / "canonical.pdf"
        source.write_bytes(b"canonical fixture bytes")
        source_sha = hashlib.sha256(source.read_bytes()).hexdigest()
        entries = [{"id": f"IDENTITY-{i}", "title": f"Current identity {i}", "semantic_pages": [i+1]}
                   for i in range(347)]
        entries[0] = {"id": "800.P02", "title": "Waxing Apparatus", "semantic_pages": [1, 3]}
        entries[1] = {"id": "800.P03", "title": "Air Tech Duties", "semantic_pages": [2]}
        aliases = [{"source_record_id": f"record-{i}", "legacy_id": str(i), "source_title": f"Legacy subject {i}",
                    "current_ids": [entries[i]["id"]]} for i in range(130)]
        aliases[0].update(legacy_id="800.01", source_title="Waxing of Apparatus")
        aliases[1].update(legacy_id="800.01", source_title="Air Tech Duties")
        self.documents = []
        for i in range(32):
            self.documents.append({"title": f"SOG asset {i}", "source_path": "canonical.pdf", "page_count": 856 if i==0 else 1,
                "metadata": {"asset_id": f"ASSET-{i}", "review_edition": ingest.SOG_EDITION, "admin_only":False,
                    "canonical_sha256":source_sha, "source_sha256":source_sha, "artifact_pdf":f"asset-{i}.pdf",
                    "primary_entries":entries if i==0 else [], "subject_aliases":aliases if i==0 else []}})
        self.manifest = {"manuals":[
            {"slug":"sogs", "version_label":ingest.SOG_EDITION, "sections":[{"documents":self.documents}]},
            {"slug":"review-controls", "sections":[{"documents":[{"source_path":"hidden-unavailable.pdf"}]}]},
        ]}

    def build(self):
        manifest_path = self.root / "import-manifest.json"
        manifest_path.write_text(json.dumps(self.manifest), encoding="utf-8")
        manifest_sha = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
        def pages(_path):
            count = self.documents[self.calls]["page_count"]
            self.calls += 1
            return [(page, f"Extracted canonical physical page {page}.") for page in range(1, count+1)]
        self.calls = 0
        with patch.object(ingest,"SOG_MANIFEST_SHA256",manifest_sha), patch.object(ingest,"extract_text_from_pdf",pages):
            return ingest.build_sog_corpus(manifest_path)

    def test_final_tail_and_unicode_text_are_preserved(self):
        text = "Policy — café\n" * 200 + "FINAL-TAIL"
        chunks = ingest.chunk_sog_text(text)
        restored = chunks[0] + "".join(chunk[200:] for chunk in chunks[1:])
        self.assertEqual(restored,text)
        self.assertTrue(chunks[-1].endswith("FINAL-TAIL"))

    def test_same_old_number_keeps_distinct_subjects_and_noncontiguous_physical_pages(self):
        records, receipt = self.build()
        self.assertEqual((receipt["pages"],receipt["primary_ids"],receipt["subject_aliases"]),(887,347,130))
        asset = [record for record in records if record["metadata"]["asset_id"]=="ASSET-0"]
        for page in (1,3):
            text = "\n".join(record["metadata"]["text"] for record in asset if record["metadata"]["page"]==page)
            self.assertIn("800.01: Waxing of Apparatus (record-0)",text)
            self.assertNotIn("800.01: Air Tech Duties",text)
        page_two = next(record for record in asset if record["metadata"]["page"]==2)
        self.assertIn("800.01: Air Tech Duties (record-1)",page_two["metadata"]["text"])
        self.assertNotIn("Waxing of Apparatus",page_two["metadata"]["text"])
        self.assertTrue(page_two["metadata"]["url"].endswith("ASSET-0?page=2"))
        self.assertTrue(all(record["namespace"]==ingest.SOG_NAMESPACE for record in records))
        self.assertEqual(len({record["id"] for record in records}),len(records))
        self.assertEqual(records,self.build()[0])

    def test_hidden_controls_cannot_be_added_to_sog_manual(self):
        self.documents[0]["metadata"]["admin_only"] = True
        with self.assertRaisesRegex(ValueError,"Hidden review controls"):
            self.build()

    def test_changed_canonical_bytes_are_rejected(self):
        (self.root / "canonical.pdf").write_bytes(b"tampered bytes")
        with self.assertRaisesRegex(ValueError,"source hash differs"):
            self.build()

    def test_source_path_cannot_escape_the_staged_directory(self):
        self.documents[0]["source_path"] = "../outside.pdf"
        with self.assertRaisesRegex(ValueError,"escapes the staged directory"):
            self.build()

    def test_uncertain_first_upsert_preserves_pending_ids_before_the_request(self):
        receipt = self.root / "upsert-receipt.json"
        records = [{"id":f"deterministic-fixture-{i}", "namespace":ingest.SOG_NAMESPACE,
                    "metadata":{"text":"canonical text"}} for i in range(2160)]
        prepared = self.root / "reviewed.ndjson"
        prepared.write_bytes(("\r\n".join(json.dumps(record) for record in records) + "\r\n").encode())
        def fail_response(_url, _body, ndjson=False):
            on_disk = json.loads(receipt.read_text())
            self.assertEqual(on_disk["status"],"UPSERTING")
            self.assertEqual(on_disk["pending_ids"],[record["id"] for record in records[:10]])
            self.assertEqual(on_disk["mutations"],[])
            raise TimeoutError("fixture response lost")
        with patch.object(ingest,"build_sog_corpus",return_value=(records,{"chunks":2160})), \
             patch.object(ingest,"SOG_CORPUS_SHA256",hashlib.sha256(prepared.read_bytes()).hexdigest()), \
             patch.object(ingest,"CF_API_TOKEN","fixture-secret"), \
             patch.object(ingest,"get_embeddings",return_value=[[0.0]*1024]*10), \
             patch.object(ingest,"cloudflare_post",side_effect=fail_response):
            with self.assertRaises(TimeoutError):
                ingest.ingest_sogs("unused-manifest",receipt,apply=True,prepared_corpus=prepared)
        on_disk = json.loads(receipt.read_text())
        self.assertEqual(on_disk["pending_ids"],[record["id"] for record in records[:10]])
        self.assertEqual(receipt.with_suffix(".ndjson").read_bytes(),prepared.read_bytes())

    def test_apply_binds_exact_prepared_bytes_count_and_extraction_before_provider_calls(self):
        records = [{"id":f"fixture-{i}", "namespace":ingest.SOG_NAMESPACE,
                    "metadata":{"text":"canonical text"}} for i in range(2160)]
        prepared = self.root / "reviewed.ndjson"
        prepared.write_bytes(("\r\n".join(json.dumps(record) for record in records) + "\r\n").encode())
        expected = hashlib.sha256(prepared.read_bytes()).hexdigest()
        cases = [
            (None,records,expected,"prepared-corpus"),
            (prepared,records,"changed-hash","corpus bytes differ"),
            (prepared,records[:-1],expected,"2160"),
            (prepared,[dict(records[0],metadata={"text":"extractor drift"})]+records[1:],expected,"extraction differs"),
        ]
        for index,(corpus,extracted,checksum,message) in enumerate(cases):
            with self.subTest(message=message), \
                 patch.object(ingest,"build_sog_corpus",return_value=(extracted,{"chunks":len(extracted)})), \
                 patch.object(ingest,"SOG_CORPUS_SHA256",checksum), \
                 patch.object(ingest,"get_embeddings") as embeddings, \
                 patch.object(ingest,"cloudflare_post") as provider:
                output = self.root / f"rejected-{index}.json"
                with self.assertRaisesRegex(ValueError,message):
                    ingest.ingest_sogs("unused-manifest",output,apply=True,prepared_corpus=corpus)
                embeddings.assert_not_called()
                provider.assert_not_called()
                self.assertFalse(output.exists())

    def test_receipt_replacement_syncs_complete_bytes_and_preserves_previous_state_on_failure(self):
        output = self.root / "atomic-receipt.json"
        previous = {"status":"PREPARED"}
        ingest.write_receipt(output,previous,exclusive=True)
        following = {"status":"UPSERTING","pending_ids":["uncertain-id"]}
        def fail_replace(temporary,destination):
            self.assertEqual(json.loads(temporary.read_bytes()),following)
            self.assertEqual(json.loads(destination.read_bytes()),previous)
            raise OSError("fixture atomic replacement failed")
        with patch.object(ingest.os,"fsync",wraps=ingest.os.fsync) as sync, \
             patch.object(ingest.os,"replace",side_effect=fail_replace):
            with self.assertRaises(OSError):
                ingest.write_receipt(output,following)
            sync.assert_called_once()
        self.assertEqual(json.loads(output.read_bytes()),previous)
        self.assertEqual(json.loads(output.with_name(output.name+".pending").read_bytes()),following)

    def test_v2_upsert_matches_locked_wrangler_multipart_file_format(self):
        body = json.dumps({"id":"fixture-id","namespace":ingest.SOG_NAMESPACE,
                           "values":[0.0]*1024,"metadata":{"text":"Exact café policy — text"}})
        response = io.BytesIO(b'{"success":true,"result":{"mutationId":"fixture-mutation"}}')
        response.status = 200
        def capture(request, timeout):
            self.assertEqual(timeout,120)
            content_type = request.get_header("Content-type")
            self.assertTrue(content_type.startswith("multipart/form-data; boundary="))
            message = BytesParser(policy=default).parsebytes(
                f'Content-Type: {content_type}\r\n\r\n'.encode() + request.data)
            parts = list(message.iter_parts())
            self.assertEqual(len(parts),1)
            self.assertEqual(parts[0].get_param("name",header="content-disposition"),"vectors")
            self.assertEqual(parts[0].get_filename(),"vectors.ndjson")
            self.assertEqual(parts[0].get_content_type(),"application/x-ndjson")
            self.assertEqual(parts[0].get_payload(decode=True),body.encode("utf-8"))
            return response
        with patch.object(ingest,"urlopen",side_effect=capture):
            status,result = ingest.cloudflare_post(ingest.CF_VECTORIZE_URL+"/upsert",body,ndjson=True)
        self.assertEqual(status,200)
        self.assertEqual(result["result"]["mutationId"],"fixture-mutation")

    def test_reference_ingester_rejects_the_legacy_sog_paths(self):
        for source in ("support_sog.pdf","edited_support_services_sog.docx","extra_info_for_AI.pdf","driver_manual.pdf"):
            self.assertTrue(ingest.is_retired_sog_source(source))
        self.assertFalse(ingest.is_retired_sog_source("L3_manual.pdf"))

    def test_generic_technical_ingestion_uses_the_reference_namespace(self):
        captured = []
        def upsert(vectors):
            captured.extend(vectors)
            return True
        with patch.object(ingest,"extract_text_from_pdf",return_value=[(1,"Technical apparatus maintenance instructions "*20)]), \
             patch.object(ingest,"get_embeddings",return_value=[[0.0]*1024]), \
             patch.object(ingest,"upsert_vectors",side_effect=upsert), \
             patch.object(ingest.time,"sleep"), contextlib.redirect_stdout(io.StringIO()):
            ingest.process_file(self.root / "canonical.pdf")
        self.assertEqual(len(captured),1)
        self.assertEqual(captured[0]["namespace"],ingest.REFERENCE_NAMESPACE)


if __name__=="__main__":
    unittest.main()
