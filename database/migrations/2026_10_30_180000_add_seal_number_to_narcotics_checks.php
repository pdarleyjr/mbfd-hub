<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narcotics check: the seal number the member types (docs/narcotics-check.md). Additive only; it creates
 * no permission and grants none.
 *
 * `seal_number` is free text as typed (trimmed). It is nullable: checks sent before this existed stay NULL and
 * are shown as "Not recorded". The server requires it on every new submission (NarcoticsCheckService).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('narcotics_checks') || Schema::hasColumn('narcotics_checks', 'seal_number')) {
            return;
        }

        Schema::table('narcotics_checks', function (Blueprint $table): void {
            $table->string('seal_number', 40)->nullable()->after('rescue_unit_other');
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Narcotics check records are never altered backwards outside local or testing.');
        }
        if (! Schema::hasTable('narcotics_checks') || ! Schema::hasColumn('narcotics_checks', 'seal_number')) {
            return;
        }

        Schema::table('narcotics_checks', function (Blueprint $table): void {
            $table->dropColumn('seal_number');
        });
    }
};
