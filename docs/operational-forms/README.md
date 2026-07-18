# MBFD Operational Forms

This module fills the controlled AcroForm templates in `resources/forms`, regenerates field appearances, flattens the result, validates its structure, and stores immutable versions on the configured private disk.

The sample PDFs in `samples/` contain fictional, non-sensitive acceptance data. They are generated from the matching JSON fixtures under `tests/Fixtures/OperationalForms` and must be regenerated only when a controlled template, mapping, generator, or approved fixture changes.

Production requires Node.js 20 or newer plus `qpdf` and `pdfinfo`. Set `OPERATIONAL_FORMS_REQUIRE_EXTERNAL_VALIDATORS=true` outside local/test environments so generation fails closed when those validators are unavailable.

Pinned acceptance outputs:

- `ICS-214-sample.pdf`: `2dae797b4258fbe3adb7cfca9c129f4cbfbf8db3b052d8bcb661b117045c6dfd`
- `FROC-LOG-001-FF-v11-sample.pdf`: `91a0ef2c90bb1fe3196a442b8eaa6133cacbfd40f58c43f1bca1008217685a3c`
