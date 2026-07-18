<?php

return [
    'node_binary' => env('OPERATIONAL_FORMS_NODE_BINARY', 'node'),
    'generator_timeout_seconds' => (int) env('OPERATIONAL_FORMS_GENERATOR_TIMEOUT', 30),
    'maximum_pdf_bytes' => (int) env('OPERATIONAL_FORMS_MAXIMUM_PDF_BYTES', 20 * 1024 * 1024),
    'require_external_validation' => (bool) env(
        'OPERATIONAL_FORMS_REQUIRE_EXTERNAL_VALIDATION',
        env('APP_ENV') === 'production',
    ),
    'generator_version' => 'pdf-lib-1.17.1/operational-forms-1.0.0',
];
