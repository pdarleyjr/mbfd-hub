# FROC-LOG-001-FF Version 11 - Controlled AcroForm Template

This package preserves the four user-supplied PDF pages as the exact visual background and adds **270 named, transparent, borderless AcroForm text fields**.

## Files

- `FROC-LOG-001-FF_v11_AcroForm_Template.pdf` - fillable controlled template
- `FROC-LOG-001-FF_v11_mapping.json` - complete versioned mapping with PDF-point rectangles and 200-DPI reference rectangles
- `FROC-LOG-001-FF_v11_field_reference.csv` - flat field reference
- `FROC-LOG-001-FF_v11_sample_values.json` - non-sensitive sample values for mapping tests

## Required generation behavior

1. Verify the template SHA-256 from the mapping JSON.
2. Populate fields by exact field name.
3. For boolean cells, write `X` for true and an empty string for false.
4. Calculate totals from structured application data; do not rely on PDF JavaScript.
5. For the six calculated total fields, apply an opaque white field background/knockout before drawing the replacement value because the source form contains printed zero defaults.
6. Regenerate field appearance streams.
7. Flatten the completed form.
8. Render and compare against approved golden outputs.

## Signature images

The signature text fields also define the intended signature rectangles. A PDF engine may place a scaled signature image in the same `rect_pdf` and then flatten the result.

## Coordinate convention

- PDF rectangles are `[llx, lly, urx, ury]`.
- Origin is the bottom-left of each page.
- Units are points; 72 points = 1 inch.
- All pages are 792 x 612 points.

## Important limitation

This is a technically enhanced derivative of the user-supplied form. It does not independently establish that the form is the latest official FDEM/F-ROC release or that modifying it is approved for official submission. Preserve the source form and verify acceptance requirements with the issuing authority.

## Printed total defaults

The source PDF already prints `0.00` or `0` inside six total boxes. The blank AcroForm template intentionally leaves those pixels untouched so it remains pixel-identical to the source. When a total changes, the generation engine must paint an opaque white background inside that field rectangle before drawing the calculated value. The mapping JSON marks these fields with `requires_background_knockout_when_value_changes`.

## Included fill-and-flatten example

The package includes `FROC-LOG-001-FF_v11_fill_and_flatten.mjs`. In a Node project:

```bash
npm install pdf-lib
node FROC-LOG-001-FF_v11_fill_and_flatten.mjs \
  FROC-LOG-001-FF_v11_AcroForm_Template.pdf \
  FROC-LOG-001-FF_v11_mapping.json \
  values.json \
  completed.pdf
```

The helper applies the mapped white knockout to printed total boxes, regenerates appearances with one controlled Helvetica font, and flattens the result. For production, use a pinned `pdf-lib` version or implement the same contract in PDFBox.
