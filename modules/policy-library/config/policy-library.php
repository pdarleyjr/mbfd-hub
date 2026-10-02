<?php

declare(strict_types=1);

return [
    'domain' => env('POLICY_LIBRARY_DOMAIN', 'files.mbfdhub.com'),
    'pin_hash' => env('POLICY_LIBRARY_PIN_HASH'),
    'pin_ttl_minutes' => (int) env('POLICY_LIBRARY_PIN_TTL_MINUTES', 480),
    'storage_root' => env('POLICY_LIBRARY_STORAGE_ROOT', storage_path('app/private/policy-library')),
    'qpdf_binary' => env('POLICY_LIBRARY_QPDF_BINARY', 'qpdf'),
    'pdfinfo_binary' => env('POLICY_LIBRARY_PDFINFO_BINARY', 'pdfinfo'),
    'pdftotext_binary' => env('POLICY_LIBRARY_PDFTOTEXT_BINARY', 'pdftotext'),
    'max_upload_kb' => (int) env('POLICY_LIBRARY_MAX_UPLOAD_KB', 51200),
    'accel_prefix' => env('POLICY_LIBRARY_ACCEL_PREFIX'),
    'scanner_enabled' => (bool) env('POLICY_LIBRARY_SCANNER_ENABLED', true),
    'clamd_host' => env('POLICY_LIBRARY_CLAMD_HOST', 'mbfd-clamav'),
    'clamd_port' => (int) env('POLICY_LIBRARY_CLAMD_PORT', 3310),
    'python_binary' => env('POLICY_LIBRARY_PYTHON_BINARY', 'python3'),
    'queue_connection' => 'policy-library',
    'queue_name' => 'policy-library',
    'queue_retry_after' => 1860,
    'job_timeout' => 1800,
];
