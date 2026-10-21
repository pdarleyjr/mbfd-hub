<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only the vehicle and the inspector's acknowledgment and signature are mandatory
 * on a Take Home Vehicle Inspection. Driver, keys and acceptability may be left
 * unanswered; null means "not answered", never Yes or No.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('take_home_vehicle_inspections', function (Blueprint $table): void {
            $table->string('driver_name')->nullable()->change();
            $table->boolean('keys_present')->nullable()->change();
            $table->boolean('acceptable_for_use')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally not reverting to NOT NULL: submitted inspections may hold unanswered values.
    }
};
