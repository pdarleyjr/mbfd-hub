<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->string('name')->nullable()->after('unit_id');
            $table->string('type')->nullable()->after('name');
            $table->string('vehicle_number')->nullable()->after('type');
            $table->string('slug')->nullable()->unique()->after('vehicle_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->dropColumn(['name', 'type', 'vehicle_number', 'slug']);
        });
    }
};
