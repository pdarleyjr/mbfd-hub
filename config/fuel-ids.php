<?php

declare(strict_types=1);

return [
    // Python 3 with PyMuPDF; the production image installs it for the policy library.
    'python_binary' => env('FUEL_IDS_PYTHON_BINARY', env('POLICY_LIBRARY_PYTHON_BINARY', 'python3')),
    'extractor_timeout_seconds' => 120,
    'max_upload_kb' => 10240,
];
