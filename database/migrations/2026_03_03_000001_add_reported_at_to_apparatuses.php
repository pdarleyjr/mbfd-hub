<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->timestamp('reported_at')->nullable()->after('notes');
        });

        // Backfill: use updated_at as initial reported_at for all existing rows
        DB::statement('UPDATE apparatuses SET reported_at = updated_at WHERE reported_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->dropColumn('reported_at');
        });
    }
};
