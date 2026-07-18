# ICS 214 Controlled AcroForm Template Package

## Purpose

This package preserves the uploaded one-page **Activity Log (ICS 214)** as the visual source and adds a controlled, invisible AcroForm layer. The page contains the Incident Name, Operational Period, unit information, eight Resources Assigned rows, twenty-four Activity Log rows, and Prepared-by fields shown in the uploaded form. The blank controlled template is intended to remain visually identical to the source.

## Package contents

- `ICS_214_Controlled_AcroForm_Template_v1.0.pdf` - controlled fillable template with **84 named fields**.
- `ICS_214_mapping_v1.0.json` - authoritative field names, semantic paths, coordinates, validation, font, overflow, and flattening rules.
- `ICS_214_field_reference_v1.0.csv` - compact field reference for implementation and review.
- `ICS_214_sample_values.json` - non-sensitive sample values.
- `ICS_214_fill_and_flatten.mjs` - Node/pdf-lib population example.
- `ICS_214_sample_filled_flattened.pdf` - flattened demonstration output.
- `ICS_214_verification_report.json` - structural and visual verification evidence.
- `create_template.py` - reproducible template-construction script.


## Node example setup

The included example is pinned to `pdf-lib` 1.17.1. From the package directory:

```bash
npm ci
node ICS_214_fill_and_flatten.mjs \
  ICS_214_Controlled_AcroForm_Template_v1.0.pdf \
  ICS_214_mapping_v1.0.json \
  ICS_214_sample_values.json \
  completed_ics214.pdf
```

Use `--keep-fields` only for testing an editable output. Official generated records should normally be flattened.

## Field naming

Examples:

- `incident_name`
- `operational_period_date_from`
- `unit_home_agency_unit`
- `resource_01_name`
- `resource_08_home_agency_unit`
- `activity_01_date_time`
- `activity_24_notable_activities`
- `prepared_by_signature`

Application data should be mapped through each field's `semantic_path`, while the PDF should be populated using the exact `name`.

## Visual preservation

The page artwork was not redrawn. The original page remains the visible page content. The added form widgets are transparent and borderless when empty. Always verify the controlled template SHA-256 before generation:

- Source SHA-256: `e1f2070c5a0ad154dc9268da21333d6d3a45843e27d2d76f334f933ec7da3307`
- Controlled template SHA-256: `1c56a9efa115755e23c49d40a500ea47824d66170de87ce5735c51ddc5fb41e5`

## Recommended deterministic generation sequence

1. Validate and normalize application data.
2. Verify the template SHA-256.
3. Load a fresh copy of the controlled template.
4. Populate exact named fields.
5. Apply one pinned approved font and deterministic sizes.
6. Regenerate appearance streams.
7. Reject or route overflow to an approved continuation page; never silently clip.
8. Flatten the final PDF.
9. Validate page count, dimensions, expected text, and lack of remaining form fields.
10. Render and compare against approved golden images.
11. Store the source-data snapshot, mapping version, template hash, generator version, and output SHA-256.

## Coordinate mapping

The JSON includes both:

- PDF user-space rectangles: bottom-left origin, points.
- Top-left rectangles: useful with PyMuPDF and image-based tools.
- Reference pixel rectangles at 200 DPI.

The PDF rectangle order is `[x0, y0, x1, y1]`.

## Signatures

`prepared_by_signature` is a controlled text field. An image signature may instead be fitted proportionally into the same mapped rectangle before flattening. The website should preserve the signature source separately and generate a new immutable PDF version after any form edit.

## Continuation policy

This one-page source contains eight resource rows and twenty-four activity rows. Do not compress, resize, or extend the original page. When capacity is exceeded, use a separately approved continuation page and preserve the page-one geometry.

## Viewer highlighting

Some PDF viewers display interactive fields with blue or colored highlighting. This is a viewer preference and is not part of the underlying page artwork. Flattened output will not contain interactive highlighting.
