<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('apparatus_defects', 'photo')) {
            Schema::table('apparatus_defects', function (Blueprint $table) {
                $table->text('photo')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        Schema::table('apparatus_defects', function (Blueprint $table) {
            $table->dropColumn('photo');
        });
    }
};
