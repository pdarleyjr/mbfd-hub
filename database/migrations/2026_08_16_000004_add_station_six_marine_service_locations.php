<?php

declare(strict_types=1);

use App\Models\Station;
use App\Services\StationRoomBlueprintService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $station = Station::query()->where('station_number', 6)->first();

        if ($station !== null) {
            app(StationRoomBlueprintService::class)->sync($station);
        }
    }

    public function down(): void
    {
        // Retain these locations because station requests, assets, and audits
        // may reference them. Reapplying the migration remains idempotent.
    }
};
