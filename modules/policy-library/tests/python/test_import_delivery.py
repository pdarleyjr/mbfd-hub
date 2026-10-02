import sys
import json
import os
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
from import_delivery import bound_json, canonical_peer_links, contained_path, verify_derivative
from import_sources import digest, pymupdf
from validate_import import require_coverage


class WholeDeliveryLinkTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.source = self.root / "source.pdf"
        self.target = self.root / "target.pdf"
        with pymupdf.open() as pdf:
            pdf.new_page().insert_text((36, 50), "Target page one")
            pdf.new_page().insert_text((36, 50), "Target shared page two")
            pdf.save(self.target)
        with pymupdf.open() as pdf:
            page = pdf.new_page()
            page.insert_text((36, 50), "Two instruments share this original page")
            page.insert_link({"kind": pymupdf.LINK_GOTOR, "from": pymupdf.Rect(36, 60, 180, 80), "file": "target.pdf", "page": 1, "to": pymupdf.Point(0, 0)})
            page.insert_link({"kind": pymupdf.LINK_GOTO, "from": pymupdf.Rect(36, 90, 180, 110), "page": 0, "to": pymupdf.Point(0, 0)})
            pdf.save(self.source)
        self.assets = {"SECTION-100": {"pdf_pages": 1, "pdf_sha256": "a" * 64}, "200-B6-R1": {"pdf_pages": 2, "pdf_sha256": "b" * 64}}

    def tearDown(self):
        self.directory.cleanup()

    def links(self):
        return canonical_peer_links(self.source, {"id": "SECTION-100"}, {self.target.resolve(): "200-B6-R1"}, self.assets)

    def test_remote_page_is_one_based_and_current_asset_bound(self):
        link = self.links()[0]
        self.assertEqual(link["target_page"], 2)
        self.assertEqual(link["uri"], "https://files.mbfdhub.com/current-sog/200-B6-R1?page=2")
        self.assertEqual(link["target_source_sha256"], "b" * 64)

    def test_unknown_or_out_of_range_peer_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "unknown canonical peer"):
            canonical_peer_links(self.source, {"id": "SECTION-100"}, {}, self.assets)
        self.assets["200-B6-R1"]["pdf_pages"] = 1
        with self.assertRaisesRegex(ValueError, "leaves its current target"):
            self.links()

    def test_uri_rewrite_preserves_shared_page_pixels_and_local_link(self):
        links = self.links()
        served = self.root / "served.pdf"
        with pymupdf.open(self.source) as pdf:
            pdf.xref_set_key(links[0]["annotation_xref"], "A", f"<< /S /URI /URI ({links[0]['uri']}) >>")
            pdf.save(served, garbage=0, deflate=False, no_new_id=True)
        proof = verify_derivative(self.source, served, links, True)
        self.assertEqual(len(proof), 1)
        self.assertEqual(proof[0]["canonical_render_sha256"], proof[0]["served_render_sha256"])
        with pymupdf.open(served) as pdf:
            self.assertEqual([link["kind"] for link in pdf[0].get_links()], [pymupdf.LINK_URI, pymupdf.LINK_GOTO])

    def test_added_content_cannot_pass_derivative_validation(self):
        links = self.links()
        served = self.root / "changed.pdf"
        with pymupdf.open(self.source) as pdf:
            pdf.xref_set_key(links[0]["annotation_xref"], "A", f"<< /S /URI /URI ({links[0]['uri']}) >>")
            pdf[0].insert_text((36, 150), "Unexpected replacement content")
            pdf.save(served)
        with self.assertRaisesRegex(ValueError, "text or geometry changed"):
            verify_derivative(self.source, served, links, True)

    def test_source_path_escape_and_wrong_delivery_hash_fail(self):
        with self.assertRaisesRegex(ValueError, "outside"):
            contained_path(self.root, "../source.pdf")
        with self.assertRaisesRegex(ValueError, "hash mismatch"):
            bound_json(self.source, "0" * 64)


class FrozenR2CorpusTests(unittest.TestCase):
    def setUp(self):
        self.root = Path(os.environ.get("POLICY_R2_CORPUS_ROOT", Path(__file__).resolve().parents[4] / "var" / "r2-staged"))
        path = self.root / "import-manifest.json"
        if not path.exists():
            self.skipTest("Private frozen R2 corpus not staged")
        self.manifest = json.loads(path.read_text(encoding="utf-8"))
        self.sogs = next(manual for manual in self.manifest["manuals"] if manual["slug"] == "sogs")
        self.documents = [document for section in self.sogs["sections"] for document in section["documents"]]

    def test_all_32_assets_887_unique_canonical_pages_and_347_identities_present(self):
        self.assertEqual(len(self.documents), 32)
        self.assertEqual(sum(document["page_count"] for document in self.documents), 887)
        entries = [entry for document in self.documents for entry in document["metadata"]["primary_entries"]]
        self.assertEqual(len(entries), 347)
        self.assertEqual(len({entry["id"] for entry in entries}), 347)
        self.assertEqual(len({entry["slug"] for entry in entries}), 347)
        for document in self.documents:
            require_coverage(document["pages"], document["page_count"])
            self.assertEqual(digest(self.root / document["source_path"]), document["metadata"]["canonical_sha256"])

    def test_shared_page_instruments_and_duplicate_800_01_subjects_stay_distinct(self):
        entries = {entry["id"]: entry for document in self.documents for entry in document["metadata"]["primary_entries"]}
        self.assertEqual(entries["200-B6-FRM05"]["physical_page"], entries["200-B6-FRM06"]["physical_page"])
        self.assertIn(56, entries["200-B6-FRM06"]["semantic_pages"])
        aliases = {alias["source_record_id"]: alias for document in self.documents for alias in document["metadata"]["subject_aliases"]}
        self.assertEqual(len(aliases), 130)
        duplicated = [alias for alias in aliases.values() if alias["legacy_id"] == "800.01"]
        self.assertEqual(len(duplicated), 2)
        self.assertEqual({alias["source_title"] for alias in duplicated}, {"Air Tech Duties", "Apparatus Waxing Schedule"})
        self.assertNotEqual(duplicated[0]["current_ids"], duplicated[1]["current_ids"])

    def test_review_controls_are_separate_hidden_admin_context_and_moms_absent(self):
        self.assertEqual({manual["slug"] for manual in self.manifest["manuals"]}, {"sogs", "sog-review-controls"})
        controls = next(manual for manual in self.manifest["manuals"] if manual["slug"] == "sog-review-controls")
        self.assertFalse(controls["is_active"])
        self.assertTrue(controls["metadata"]["admin_only"])
        documents = controls["sections"][0]["documents"]
        self.assertEqual(len(documents), 2)
        self.assertEqual(sum(document["page_count"] for document in documents), 39)

    def test_all_1685_peer_actions_and_926_200dpi_page_proofs_present(self):
        proof = json.loads((self.root / "sog-delivery-proof.json").read_text(encoding="utf-8"))
        self.assertEqual(proof["peer_action_count"], 1685)
        self.assertEqual(proof["peer_path_pairs"], 205)
        pages = [page for asset in proof["canonical_assets"] for page in asset["pages"]]
        self.assertEqual(len(pages), 926)
        for page in pages:
            self.assertEqual(page["render_dpi"], 200)
            self.assertEqual(page["canonical_render_sha256"], page["served_render_sha256"])


if __name__ == "__main__":
    unittest.main()
