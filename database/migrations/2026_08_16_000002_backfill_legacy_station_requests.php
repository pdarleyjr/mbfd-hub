<?php

declare(strict_types=1);

use App\Services\LegacyStationRequestBackfillService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(LegacyStationRequestBackfillService::class)->run();
    }

    public function down(): void
    {
        // Intentionally no-op. Canonical history remains until the domain
        // migration itself is rolled back; legacy source tables are untouched.
    }
};
