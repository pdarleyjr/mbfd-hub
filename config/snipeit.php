<?php

return [
    'url' => env('SNIPEIT_API_URL', 'http://snipeit:80/api/v1/'),
    'token' => env('SNIPEIT_API_TOKEN', ''),
    'timeout' => env('SNIPEIT_API_TIMEOUT', 15),
    'admin_email' => env('SNIPEIT_ADMIN_EMAIL', 'PeterDarley@miamibeachfl.gov'),
    'status_ids' => [
        'out_for_repair' => (int) env('SNIPEIT_STATUS_OUT_FOR_REPAIR_ID', 4),
        'missing' => (int) env('SNIPEIT_STATUS_MISSING_ID', 5),
    ],
];
