<?php

return [
    /*
     * IssueSource adapters shown on the admin dashboards (each implements
     * App\Support\Dashboards\IssueSource). Grouped by owning stream so parallel
     * work never edits the same list; the provider merges them in this order.
     */
    'vehicle_sources' => [
        App\Services\Dashboards\Sources\Vehicle\ApparatusDefectSource::class,
        App\Services\Dashboards\Sources\Vehicle\InspectionExceptionSource::class,
        App\Services\Dashboards\Sources\Vehicle\ApparatusServiceTicketSource::class,
        App\Services\Dashboards\Sources\Vehicle\InventoryCorrectionSource::class,
    ],

    'station_sources' => [
    ],
];
