import sys
import unittest
import json
import os
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
from import_sources import latest_candidate, numeric_version, pymupdf, validate_protocol_start
from validate_import import require_coverage


class VersionSelectionTests(unittest.TestCase):
    def test_numeric_components_do_not_sort_lexically(self):
        self.assertLess(numeric_version("V1.9/Policy.pdf"), numeric_version("V1.10/Policy.pdf"))
        self.assertLess(numeric_version("Version 2.3/Policy.pdf"), numeric_version("v10/Policy.pdf"))

    def test_supported_explicit_version_names(self):
        for name in ["V 1", "v1", "Version 1", "policy-v1.pdf"]:
            self.assertEqual(numeric_version(name), (1,))
        self.assertEqual(numeric_version("V2.3/Policy.pdf"), (2, 3))
        self.assertEqual(numeric_version("legacy.pdf"), ())

    def test_same_version_different_hashes_requires_resolution(self):
        candidates = [{"version": (3,), "sha256": "a", "path": "a.pdf"}, {"version": (3, 0), "sha256": "b", "path": "b.pdf"}]
        with self.assertRaisesRegex(ValueError, "Conflicting PDFs"):
            latest_candidate(candidates)

    def test_same_version_identical_copies_have_stable_selection(self):
        candidates = [{"version": (3,), "sha256": "a", "path": "nested/a.pdf"}, {"version": (3, 0), "sha256": "a", "path": "a.pdf"}]
        self.assertEqual(latest_candidate(candidates)["path"], "a.pdf")
        self.assertEqual(latest_candidate(list(reversed(candidates)))["path"], "a.pdf")


class PhysicalPageCoverageTests(unittest.TestCase):
    def test_duplicate_page_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "more than one"):
            require_coverage([{"physical_page": 1}, {"physical_page": 1}], 2)

    def test_gap_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "gap"):
            require_coverage([{"physical_page": 1}, {"physical_page": 3}], 3)

    def test_complete_out_of_order_mapping_is_accepted(self):
        require_coverage([{"physical_page": n} for n in [3, 1, 2]], 3)


class ProtocolStartValidationTests(unittest.TestCase):
    def validate_fixture(self, heading, page_number, title="PATIENT RESTRAINT", bookmark_titles=()):
        with pymupdf.open() as pdf:
            page = pdf.new_page()
            page.insert_text((36, 60), heading)
            page.insert_text((36, 150), page_number)
            return validate_protocol_start({"title": title, "classification": "procedure"}, page, True, bookmark_titles)

    def test_wrong_toc_target_with_different_heading_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "heading does not identify"):
            self.validate_fixture("MASS CASUALTY INCIDENT", "Page 1 of 8")

    def test_matching_heading_on_continuation_page_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "not Page 1"):
            self.validate_fixture("PATIENT RESTRAINT", "Page 2 of 4")

    def test_matching_bookmark_cannot_hide_an_unrelated_actual_heading(self):
        with self.assertRaisesRegex(ValueError, "heading does not identify"):
            self.validate_fixture("MASS CASUALTY INCIDENT", "Page 1 of 8", bookmark_titles=("PATIENT RESTRAINT",))

    def test_independently_verified_first_page_is_accepted(self):
        evidence = self.validate_fixture("PATIENT RESTRAINT", "Page 1 of 4")
        self.assertEqual(evidence["protocol_page_count"], 4)
        self.assertEqual(evidence["heading_token_overlap"], 1)


class SuppliedCorpusAcceptanceTests(unittest.TestCase):
    def setUp(self):
        root = Path(os.environ.get("POLICY_CORPUS_ROOT", Path(__file__).resolve().parents[2] / "var" / "import"))
        path = root / "import-manifest.json"
        if not path.exists():
            self.skipTest("Private supplied-corpus manifest not staged")
        self.root = root
        self.manifest = json.loads(path.read_text(encoding="utf-8"))
        self.manual = next(m for m in self.manifest["manuals"] if m["slug"] == "medical-protocols")
        self.documents = [d for s in self.manual["sections"] for d in s["documents"]]

    def test_all_784_supplied_physical_pages_are_owned_once(self):
        require_coverage([p for d in self.documents for p in d["pages"]], 784)

    def test_inserts_and_patient_restraint_use_actual_physical_pages(self):
        cases = {"ROCEPHIN (CEFTRIAXONE)": [443, 444], "POINT OF CARE ULTRASOUND (POCUS) (MIAMI BEACH)": [613, 614, 615, 616], "PATIENT RESTRAINT": [547, 548, 549, 550]}
        for title, physical_pages in cases.items():
            document = next(d for d in self.documents if d["title"] == title)
            self.assertEqual([p["physical_page"] for p in document["pages"]], physical_pages)
        correction = next(c for a in self.manifest["audits"] if a.get("kind") == "moms_mapping" for c in a["corrections"] if c["title"] == "PATIENT RESTRAINT")
        self.assertEqual(correction["menu_printed_label"], "535")
        self.assertEqual(correction["actual_printed_label"], "545")

    def test_qpdf_range_keeps_original_page_streams(self):
        from import_sources import pymupdf
        document = next(d for d in self.documents if d["title"] == "ROCEPHIN (CEFTRIAXONE)")
        with pymupdf.open(self.root / document["source_path"]) as source, pymupdf.open(self.root / document["asset_path"]) as asset:
            self.assertEqual(len(asset), 2)
            for index, physical in enumerate([443, 444]):
                expected = source[physical - 1]
                self.assertEqual([asset.xref_stream(x) for x in asset[index].get_contents()], [source.xref_stream(x) for x in expected.get_contents()])

    def test_unique_governance_companions_are_retained_at_their_latest_version(self):
        chief = next(s for m in self.manifest["manuals"] if m["slug"] == "sogs" for s in m["sections"] if s["slug"] == "section-100")
        section = next(s for s in chief["children"] if s["slug"] == "governance-companions")
        self.assertEqual([d["version_label"] for d in section["documents"]], ["V1", "V1"])
        self.assertEqual([[p["physical_page"] for p in d["pages"]] for d in section["documents"]], [[10, 11], [12, 13]])
        archive = next(s for s in self.manifest["sources"] if s["sha256"] == section["documents"][0]["metadata"]["source_sha256"])
        self.assertEqual(archive["page_count"], 13)
        self.assertEqual(archive["coverage_exclusions"][0]["physical_pages"], list(range(1, 10)))


if __name__ == "__main__":
    unittest.main()
