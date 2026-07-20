<?php

return [
    'import_force_fallback' => (bool) env('FROC_IMPORT_FORCE_FALLBACK', false),
    'upload_max_kilobytes' => (int) env('OPERATIONAL_FORMS_UPLOAD_MAX_KB', 51200),
    'upload_max_megabytes' => (int) env('OPERATIONAL_FORMS_UPLOAD_MAX_MB', 50),

    // F-ROC AI activity-note import capacity.
    //
    // These values are authoritative for both the backend (request validation
    // and bounded ZIP extraction) and the frontend (client-side preflight).
    // The single canonical byte/entry representation is used everywhere so the
    // MB and KB limits cannot drift. Untrusted or misconfigured values are
    // clamped to safe operational ranges by App\Services\OperationalForms\FrocImportLimits.
    'froc_import_upload_max_kilobytes' => (int) env('FROC_IMPORT_UPLOAD_MAX_KB', 50 * 1024),
    'froc_import_max_extracted_bytes' => (int) env('FROC_IMPORT_MAX_EXTRACTED_BYTES', 1 * 1024 * 1024),
    'froc_import_max_zip_entries' => (int) env('FROC_IMPORT_MAX_ZIP_ENTRIES', 500),
    'froc_import_max_text_entries' => (int) env('FROC_IMPORT_MAX_TEXT_ENTRIES', 10),
    'froc_import_max_compression_ratio' => (float) env('FROC_IMPORT_MAX_COMPRESSION_RATIO', 100),
    // Independent ceiling for the text actually sent to the configured AI
    // service, so a 50 MB archive never produces a 50 MB prompt.
    'froc_import_max_model_input_bytes' => (int) env('FROC_IMPORT_MAX_MODEL_INPUT_BYTES', 256 * 1024),
    'node_binary' => env('OPERATIONAL_FORMS_NODE_BINARY', 'node'),
    'generator_timeout_seconds' => (int) env('OPERATIONAL_FORMS_GENERATOR_TIMEOUT', 30),
    'maximum_pdf_bytes' => (int) env('OPERATIONAL_FORMS_MAXIMUM_PDF_BYTES', 20 * 1024 * 1024),
    'require_external_validation' => (bool) env(
        'OPERATIONAL_FORMS_REQUIRE_EXTERNAL_VALIDATION',
        env('APP_ENV') === 'production',
    ),
    'generator_version' => 'pdf-lib-1.17.1/operational-forms-1.0.0',
];
