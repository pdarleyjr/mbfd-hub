<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks TeleStaff's 7-day outlook mail and the dates it is showing (docs/operations/telestaff-roster-outlook.md).
 * Null is the 5-minute export, so every existing row keeps its meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roster_imports', 'source')) {
            Schema::table('roster_imports', static function (Blueprint $table): void {
                $table->string('source', 16)->nullable();
            });
        }

        if (! Schema::hasColumn('roster_active_versions', 'source')) {
            Schema::table('roster_active_versions', static function (Blueprint $table): void {
                $table->string('source', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['roster_imports', 'roster_active_versions'] as $name) {
            if (Schema::hasColumn($name, 'source')) {
                Schema::table($name, static function (Blueprint $table): void {
                    $table->dropColumn('source');
                });
            }
        }
    }
};
