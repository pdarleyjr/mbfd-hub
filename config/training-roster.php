<?php

declare(strict_types=1);

return [
    'host' => env('TRAINING_ROSTER_HOST', 'rosters.mbfdhub.com'),
    'scheme' => env('TRAINING_ROSTER_SCHEME', 'https'),
    'bid_api_url' => env('TRAINING_ROSTER_BID_API_URL', ''),
    'grant_hours' => 12,
];
