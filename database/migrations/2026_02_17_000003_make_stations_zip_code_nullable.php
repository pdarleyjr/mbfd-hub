<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Make zip_code nullable so tests and seeding don't require it.
     */
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('zip_code')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('zip_code')->nullable(false)->change();
        });
    }
};
