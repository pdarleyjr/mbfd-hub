<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->after('id')->constrained('stations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
            $table->dropColumn('station_id');
        });
    }
};