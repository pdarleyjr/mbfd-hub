<?php

declare(strict_types=1);

use App\Services\StationRoomBlueprintService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('blueprint_key', 100)->nullable()->after('name');
            $table->unsignedInteger('sort_order')->default(1000)->after('blueprint_key');
            $table->unique(['station_id', 'blueprint_key']);
        });

        app(StationRoomBlueprintService::class)->syncAll();
    }

    public function down(): void
    {
        // Blueprint rooms are retained because requests, assets, and audits
        // may refer to them. Rollback only removes organizing metadata.
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropUnique(['station_id', 'blueprint_key']);
            $table->dropColumn(['blueprint_key', 'sort_order']);
        });
    }
};
