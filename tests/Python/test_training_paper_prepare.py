"""Synthetic-only tests; no private roster or employee fixtures."""
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
import zipfile

from pypdf import PdfWriter

SPEC = importlib.util.spec_from_file_location(
    "paper_prepare", Path(__file__).resolve().parents[2] / "scripts/operations/training_paper_prepare.py"
)
PREPARE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PREPARE)


def pdf_bytes(tag):
    writer = PdfWriter()
    writer.add_blank_page(width=100, height=100)
    writer.add_blank_page(width=100, height=100)
    writer.add_metadata({"/Title": tag})
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue()


class PreparationTest(unittest.TestCase):
    def archive(self, members):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        path = Path(folder.name) / "synthetic.zip"
        with zipfile.ZipFile(path, "w") as package:
            for name, data in members:
                package.writestr(name, data)
        return path

    def members(self):
        return [(name, pdf_bytes(name)) for name in sorted(PREPARE.EXPECTED)]

    def test_inventory_confirms_all_six_pages_without_attendance_claims(self):
        result = PREPARE.inventory(self.archive(self.members()))
        self.assertEqual(6, result["page_total"])
        self.assertEqual(3, len(result["documents"]))
        self.assertNotIn("employees", result)

    def test_traversal_missing_extra_and_duplicate_member_are_rejected(self):
        valid = self.members()
        for members in [valid[:2], valid + [("extra.pdf", b"%PDF-")],
                        [("../" + valid[0][0], valid[0][1])] + valid[1:],
                        [valid[0], valid[0], valid[2]]]:
            with self.assertRaises(ValueError):
                PREPARE.inventory(self.archive(members))

    def test_repeated_source_bytes_are_rejected(self):
        valid = self.members()
        valid[1] = (valid[1][0], valid[0][1])
        with self.assertRaises(ValueError):
            PREPARE.inventory(self.archive(valid))

    def test_invalid_magic_and_oversized_member_are_rejected(self):
        valid = self.members()
        valid[0] = (valid[0][0], b"not-pdf")
        with self.assertRaises(ValueError):
            PREPARE.inventory(self.archive(valid))
        valid[0] = (valid[0][0], b"%PDF-" + b"x" * PREPARE.MAX_SIZE)
        with self.assertRaises(ValueError):
            PREPARE.inventory(self.archive(valid))

    def test_malformed_archive_is_rejected(self):
        path = self.archive(self.members())
        path.write_bytes(b"not-a-zip")
        with self.assertRaises(zipfile.BadZipFile):
            PREPARE.inventory(path)

    def test_ambiguous_names_and_false_positive_substrings_never_approve(self):
        employees = {"employees": [{"id": 1, "name": "Synthetic Alpha"},
                                    {"id": 2, "name": "Synthetic Alpha"},
                                    {"id": 3, "name": "Synthetic Alphabet"}]}
        rows = [{"day": "13", "page": 1, "row": "1", "raw_transcription": "Alpha Synthetic"},
                {"day": "14", "page": 2, "row": "overflow-1", "raw_transcription": "Synthetic Alph"}]
        result = PREPARE.reconcile({"rows": rows}, employees)
        self.assertEqual([1, 2], result["rows"][0]["candidate_employee_ids"])
        self.assertEqual([], result["rows"][1]["candidate_employee_ids"])
        self.assertTrue(all(row["review_status"] == "pending" for row in result["rows"]))
        self.assertTrue(all(row["employee_profile_id"] is None for row in result["rows"]))


if __name__ == "__main__":
    unittest.main()
