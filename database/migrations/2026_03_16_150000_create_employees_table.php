<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the dedicated `employees` table for the Employee Portal.
 * This is COMPLETELY SEPARATE from the `users` table used by Admin/Workgroup panels.
 * Employees authenticate with their Employee ID and a password through a custom auth guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->unique(); // MBFD Employee ID (e.g., 20731)
            $table->string('name');
            $table->string('rank')->nullable();
            $table->string('password');              // Hashed password
            $table->boolean('must_change_password')->default(true);
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
        });

        // Migrate assigned_equipment foreign key to support employees table
        Schema::table('assigned_equipment', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_portal_id')->nullable()->after('user_id');
            $table->foreign('employee_portal_id')->references('id')->on('employees')->nullOnDelete();
        });

        // Migrate equipment requests foreign key to support employees table
        Schema::table('employee_equipment_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_portal_id')->nullable()->after('user_id');
            $table->foreign('employee_portal_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_equipment_requests', function (Blueprint $table) {
            $table->dropForeign(['employee_portal_id']);
            $table->dropColumn('employee_portal_id');
        });
        Schema::table('assigned_equipment', function (Blueprint $table) {
            $table->dropForeign(['employee_portal_id']);
            $table->dropColumn('employee_portal_id');
        });
        Schema::dropIfExists('employees');
    }
};
