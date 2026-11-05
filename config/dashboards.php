<?php

return [
    /*
     * Hours a resolved equipment issue stays on the Captain 5 workspace ("Recently resolved") and the Admin issue board
     * before it drops off. Staff can clear one earlier. The resolution and the history are never removed.
     */
    'resolved_display_hours' => (int) env('DASHBOARD_RESOLVED_DISPLAY_HOURS', 72),

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
        App\Services\Dashboards\Sources\Vehicle\VehicleChangeoutSource::class,
    ],

    'station_sources' => [
        App\Services\Dashboards\Sources\Station\StationRequestSource::class,
        App\Services\Dashboards\Sources\Station\StationInspectionSource::class,
        App\Services\Dashboards\Sources\Station\HubSupportTicketSource::class,
        App\Services\Dashboards\Sources\Station\SupportMessageSource::class,
        App\Services\Dashboards\Sources\Station\StationSupplyRequestSource::class,
    ],

    // What members ask for: the Member Requests board (4 of 4) and the Overview's Requests lane.
    'request_sources' => [
        App\Services\Dashboards\Sources\Requests\PersonnelRequestSource::class,
        App\Services\Dashboards\Sources\Requests\MedicalSupplyRequestSource::class,
        App\Services\Dashboards\Sources\Requests\NarcoticsCheckSource::class,
    ],
];
