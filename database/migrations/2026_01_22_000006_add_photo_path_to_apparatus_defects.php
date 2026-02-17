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
        if (!Schema::hasColumn('apparatus_defects', 'photo_path')) {
            Schema::table('apparatus_defects', function (Blueprint $table) {
                $table->string('photo_path')->nullable()->after('notes');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apparatus_defects', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
