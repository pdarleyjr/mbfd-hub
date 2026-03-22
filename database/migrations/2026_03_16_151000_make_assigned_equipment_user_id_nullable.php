<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make user_id nullable in assigned_equipment and employee_equipment_requests.
 * These tables now use employee_portal_id (FK to employees table) as the primary link.
 * user_id remains for backwards compatibility only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assigned_equipment', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('employee_equipment_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Cannot easily revert to NOT NULL if data has nulls
    }
};
