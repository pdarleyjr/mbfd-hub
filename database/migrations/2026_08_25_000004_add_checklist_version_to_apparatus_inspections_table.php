<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            // Historical inspections predate canonical checklist versions. New
            // public submissions are required to provide and persist one.
            $table->string('checklist_version', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropColumn('checklist_version');
        });
    }
};
