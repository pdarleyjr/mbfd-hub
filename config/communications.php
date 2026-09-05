<?php

declare(strict_types=1);

return [
    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_EMAIL_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_EMAIL_API_TOKEN'),
        'from_address' => env('CLOUDFLARE_EMAIL_FROM', 'info@mbfdhub.com'),
        'safe_email_ceiling' => (int) env('CLOUDFLARE_EMAIL_SAFE_CEILING', 2850),
        'max_recipients_per_message' => (int) env('CLOUDFLARE_EMAIL_MAX_RECIPIENTS', 10),
        'max_message_bytes' => (int) env('CLOUDFLARE_EMAIL_MAX_BYTES', 4500000),
        'max_attachment_bytes' => (int) env('CLOUDFLARE_EMAIL_MAX_ATTACHMENT_BYTES', 3500000),
        'max_attachments' => (int) env('CLOUDFLARE_EMAIL_MAX_ATTACHMENTS', 5),
        'worker_request_threshold' => (int) env('CLOUDFLARE_WORKER_REQUEST_THRESHOLD', 9000000),
        'worker_cpu_ms_threshold' => (int) env('CLOUDFLARE_WORKER_CPU_MS_THRESHOLD', 27000000),
        'max_reconciliation_age_seconds' => (int) env('CLOUDFLARE_USAGE_MAX_AGE', 900),
    ],
    'inbound' => [
        'address' => env('MBFD_INBOUND_EMAIL_ADDRESS', 'info@mbfdhub.com'),
        'secret' => env('MBFD_INBOUND_EMAIL_SECRET'),
        'max_bytes' => (int) env('MBFD_INBOUND_EMAIL_MAX_BYTES', 5000000),
        'max_attachment_bytes' => (int) env('MBFD_INBOUND_EMAIL_MAX_ATTACHMENT_BYTES', 3500000),
        'max_attachments' => (int) env('MBFD_INBOUND_EMAIL_MAX_ATTACHMENTS', 5),
        'signature_tolerance_seconds' => (int) env('MBFD_INBOUND_EMAIL_SIGNATURE_TOLERANCE', 300),
        'nonce_ttl_seconds' => (int) env('MBFD_INBOUND_EMAIL_NONCE_TTL', 600),
    ],
    'allowed_attachment_mime_types' => [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/gif',
        'image/jpeg',
        'image/png',
        'text/csv',
        'text/plain',
    ],
];
