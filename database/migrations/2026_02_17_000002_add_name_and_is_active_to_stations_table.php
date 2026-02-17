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
        Schema::table('stations', function (Blueprint $table) {
            if (!Schema::hasColumn('stations', 'name')) {
                $table->string('name')->nullable()->after('station_number');
            }
            if (!Schema::hasColumn('stations', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            if (Schema::hasColumn('stations', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('stations', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
