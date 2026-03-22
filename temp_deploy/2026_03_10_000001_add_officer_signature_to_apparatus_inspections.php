<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table) {
            if (!Schema::hasColumn('apparatus_inspections', 'officer_signature')) {
                $table->string('officer_signature')->nullable()->after('results');
            }
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table) {
            $table->dropColumn('officer_signature');
        });
    }
};
