<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_master_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('veh_number')->nullable()->index();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('year')->nullable();
            $table->string('tag_number')->nullable()->index();
            $table->string('dept_code')->nullable()->index();
            $table->string('employee_or_vehicle_name')->nullable();
            $table->string('sunpass_number')->nullable();
            $table->string('als_license')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('section')->nullable()->index();
            $table->string('assignment')->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_master_vehicles');
    }
};
