<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: the master roster gains the few FY27 fleet attributes it did not
 * hold (color, city status code, fleet class) plus a verified current driver link
 * and a review flag for records created outside Fleet administration. Existing
 * columns keep their meaning: tag_number is the plate, serial_number the VIN/serial.
 * No uniqueness constraint is added; vehicle numbers stay indexed, not unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_master_vehicles', function (Blueprint $table): void {
            $table->string('color', 64)->nullable()->after('year');
            // City fleet list status letter (A/R/P). Meaning is not yet confirmed by Fleet,
            // so it is stored verbatim and never used for availability decisions.
            $table->string('fleet_status_code', 16)->nullable()->after('location');
            $table->string('fleet_class_code', 32)->nullable()->after('fleet_status_code');
            $table->string('fleet_class_description')->nullable()->after('fleet_class_code');
            // vehicle | trailer | boat | boat_motor | utility_cart (null = vehicle).
            $table->string('asset_category', 32)->nullable()->index()->after('fleet_class_description');
            $table->foreignId('current_driver_employee_id')->nullable()->after('assignment')
                ->constrained('employees')->nullOnDelete();
            $table->boolean('needs_fleet_review')->default(false)->index()->after('asset_category');
            $table->string('record_source', 48)->nullable()->after('needs_fleet_review');
        });
    }

    public function down(): void
    {
        Schema::table('unit_master_vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_driver_employee_id');
            $table->dropIndex(['asset_category']);
            $table->dropIndex(['needs_fleet_review']);
            $table->dropColumn([
                'color', 'fleet_status_code', 'fleet_class_code', 'fleet_class_description',
                'asset_category', 'needs_fleet_review', 'record_source',
            ]);
        });
    }
};
