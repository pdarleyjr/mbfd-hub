<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_products', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('manufacturer');
            $table->string('competitor_group')->nullable()->after('brand');
        });

        // Backfill: set brand = manufacturer where manufacturer is populated
        DB::table('candidate_products')
            ->whereNotNull('manufacturer')
            ->whereNull('brand')
            ->update(['brand' => DB::raw('manufacturer')]);
    }

    public function down(): void
    {
        Schema::table('candidate_products', function (Blueprint $table) {
            $table->dropColumn(['brand', 'competitor_group']);
        });
    }
};
