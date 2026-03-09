<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table) {
            if (!Schema::hasColumn('apparatus_inspections', 'results')) {
                $table->jsonb('results')->nullable()->after('unit_number');
            }
            if (!Schema::hasColumn('apparatus_inspections', 'vehicle_number')) {
                $table->string('vehicle_number')->nullable()->after('unit_number');
            }
            if (!Schema::hasColumn('apparatus_inspections', 'designation_at_time')) {
                $table->string('designation_at_time')->nullable()->after('vehicle_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table) {
            $table->dropColumn(['results', 'vehicle_number', 'designation_at_time']);
        });
    }
};
