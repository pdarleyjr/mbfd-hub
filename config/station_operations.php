<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authoritative station complement
|--------------------------------------------------------------------------
|
| This describes assigned complement, not current availability. Database
| apparatus records are matched only to report live availability; they never
| change the approved assigned-unit or staffing totals below.
|
*/
return [
    'stations' => [
        1 => [
            'units' => [
                ['label' => 'E1', 'aliases' => ['E1', 'E 1'], 'personnel' => 4],
                ['label' => 'L1', 'aliases' => ['L1', 'L 1'], 'personnel' => 4],
                ['label' => 'R1', 'aliases' => ['R1', 'R 1'], 'personnel' => 3],
                ['label' => 'R11', 'aliases' => ['R11', 'R 11'], 'personnel' => 3],
            ],
            'dorm_beds' => 14,
        ],
        2 => [
            'units' => [
                ['label' => '300', 'aliases' => ['300'], 'personnel' => 1],
                ['label' => 'Captain 5', 'aliases' => ['Captain 5', 'Captain5'], 'personnel' => 1],
                ['label' => 'E2', 'aliases' => ['E2', 'E 2'], 'personnel' => 4],
                ['label' => 'R2', 'aliases' => ['R2', 'R 2'], 'personnel' => 3],
                ['label' => 'R22', 'aliases' => ['R22', 'R 22'], 'personnel' => 3],
                ['label' => 'Air Truck', 'aliases' => ['Air Truck'], 'personnel' => 0],
            ],
            'dorm_beds' => 12,
        ],
        3 => [
            'units' => [
                ['label' => 'E3', 'aliases' => ['E3', 'E 3'], 'personnel' => 4],
                ['label' => 'L3', 'aliases' => ['L3', 'L 3'], 'personnel' => 4],
                ['label' => 'R3', 'aliases' => ['R3', 'R 3'], 'personnel' => 3],
            ],
            'dorm_beds' => 11,
        ],
        4 => [
            'units' => [
                ['label' => 'E4', 'aliases' => ['E4', 'E 4'], 'personnel' => 4],
                ['label' => 'R4', 'aliases' => ['R4', 'R 4'], 'personnel' => 3],
                ['label' => 'R44', 'aliases' => ['R44', 'R 44'], 'personnel' => 3],
            ],
            'dorm_beds' => 10,
        ],
        6 => [
            'units' => [
                ['label' => 'FB6', 'aliases' => ['FB6', 'FB 6'], 'personnel' => 4],
            ],
            'dorm_beds' => 4,
        ],
    ],
];
