import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace

script = Path(__file__).resolve().parents[2] / "scripts" / "build-reading-view.py"
spec = importlib.util.spec_from_file_location("reader_build", script)
reader = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reader)


class SourceRangeTests(unittest.TestCase):
    def test_uniform_native_top_margin_excludes_wrapped_header_fragment(self):
        class Page:
            mediabox = SimpleNamespace(height=792)
            def extract_text(self, visitor_text):
                for text, y in [('Full header', 710), ('Record', 684.9), ('snapshots continuation', 658.85)]:
                    visitor_text(text, [1, 0, 0, 1, 0, 0], [1, 0, 0, 1, 72, y], None, 13)
        pdf = SimpleNamespace(pages=[Page()])
        spans, bounds = reader.pdf_spans(pdf, {'Full header'}, 48, 117.5)
        self.assertEqual([span['text'] for span in spans[0]], ['snapshots continuation'])
        self.assertEqual(bounds[0]['header_floor'], 674.5)
        self.assertEqual(bounds[0]['native_uniform_top_margin_points'], 117.5)

    def test_repeated_text_elsewhere_does_not_prove_the_assigned_source_range(self):
        start = {"mapping": "named_destination", "pdf_page": 1, "pdf_top": 600}
        end = {"pdf_page": 1, "pdf_top": 500}
        spans = [[{"text": "Different source paragraph", "y": 588}, {"text": "Repeated policy text", "y": 400}]]
        proof = reader.paragraph_range("Repeated policy text", start, end, spans, [{"header_floor": 650, "footer_body_boundary": 48}])
        self.assertFalse(proof["matched"])
        self.assertEqual(len(proof["spans"]), 1)

    def test_actual_source_range_can_continue_across_pages_without_headers_or_footer(self):
        start = {"mapping": "named_destination", "pdf_page": 1, "pdf_top": 90}
        end = {"pdf_page": 2, "pdf_top": 550}
        spans = [[{"text": "mission-", "y": 78}, {"text": "Footer", "y": 24}],
                 [{"text": "Header", "y": 690}, {"text": "based improvement.", "y": 600}, {"text": "Next paragraph", "y": 538}]]
        proof = reader.paragraph_range("mission-based improvement.", start, end, spans, [{"header_floor": 650, "footer_body_boundary": 48}] * 2)
        self.assertTrue(proof["matched"])
        self.assertEqual(proof["pdf_page_range"], [1, 2])
        self.assertEqual([item["text"] for item in proof["spans"]], ["mission-", "based improvement."])

    def test_ambiguous_same_position_bookmarks_do_not_reflow_unproved_text(self):
        start = {"mapping": "named_destination", "pdf_page": 1, "pdf_top": 600}
        proof = reader.paragraph_range("Source", start, {"pdf_page": 1, "pdf_top": 600}, [[]], [{}])
        self.assertFalse(proof["matched"])

    def test_native_header_horizontal_and_vertical_merges_preserve_every_cell(self):
        table = reader.etree.fromstring(f'''<w:tbl xmlns:w="{reader.NS['w']}">
          <w:tblGrid><w:gridCol w:w="2000"/><w:gridCol w:w="2000"/></w:tblGrid>
          <w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr><w:p><w:r><w:t>Header</w:t></w:r></w:p></w:tc></w:tr>
          <w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr><w:p><w:r><w:t>Shared</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>First</w:t></w:r></w:p></w:tc></w:tr>
          <w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc><w:tc><w:p><w:r><w:t>Second</w:t></w:r></w:p></w:tc></w:tr>
        </w:tbl>''')
        coordinates = {"Header": (0, 700), "Shared": (0, 612), "First": (100, 612), "Second": (100, 550)}
        def destination(node, *_):
            left, top = coordinates.get(reader.visible_text(node), (0, 700))
            return {"mapping": "named_destination", "pdf_page": 1, "pdf_top": top, "pdf_left": left}
        spans = [[{"text": text, "x": left, "y": top - 12, "font_size": 12} for text, (left, top) in coordinates.items()]]
        with patch.object(reader, "source_destination", side_effect=destination):
            block, proof = reader.native_table(table, None, [], spans, [], set())
        self.assertEqual(block["type"], "table")
        self.assertEqual([[cell["text"] for cell in row] for row in block["rows"]], [["Header"], ["Shared", "First"], ["Second"]])
        self.assertEqual(block["rows"][0][0]["col_span"], 2)
        self.assertTrue(block["rows"][0][0]["header"])
        self.assertEqual(block["rows"][1][0]["row_span"], 2)
        self.assertEqual(len(proof["cells"]), 5)
        self.assertIsNone(proof["fallback_reason"])

    def test_unproved_cell_text_keeps_the_entire_table_in_original_pdf(self):
        table = reader.etree.fromstring(f'''<w:tbl xmlns:w="{reader.NS['w']}"><w:tblGrid><w:gridCol w:w="2000"/></w:tblGrid>
          <w:tr><w:tc><w:p><w:r><w:t>Actual source cell</w:t></w:r></w:p></w:tc></w:tr></w:tbl>''')
        mapping = {"mapping": "named_destination", "pdf_page": 1, "pdf_top": 600, "pdf_left": 0}
        with patch.object(reader, "source_destination", return_value=mapping):
            block, proof = reader.native_table(table, None, [], [[{"text": "Different cell", "x": 0, "y": 588, "font_size": 12}]], [], set())
        self.assertEqual(block["type"], "pdf_reference")
        self.assertEqual(block["pdf_page"], 1)
        self.assertFalse(proof["cells"][0]["paragraphs"][0]["coordinate_proof"]["matched"])


if __name__ == "__main__":
    unittest.main()
