<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authoritative station complement
|--------------------------------------------------------------------------
|
| The JSON source is shared with the Daily SPA so server totals, client
| fallbacks, apparatus labels, and room-blueprint dorm positions cannot
| drift apart. Database apparatus records only add live availability.
|
*/
$source = file_get_contents(base_path('resources/js/daily-checkout/src/data/stationOperations.json'));

if ($source === false) {
    throw new RuntimeException('The authoritative station operations definition is missing.');
}

return json_decode($source, true, 512, JSON_THROW_ON_ERROR);
