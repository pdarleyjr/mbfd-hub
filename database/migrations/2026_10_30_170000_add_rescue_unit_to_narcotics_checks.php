<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narcotics check: the rescue unit the member names (docs/narcotics-check.md). Additive only; it creates
 * no permission and grants none.
 *
 * `rescue_unit` is the option chosen (R1 ... FB6, or "Other"); `rescue_unit_other` is the text typed when
 * the option is "Other". Both are nullable: checks sent before this existed stay NULL and are shown as
 * "Not recorded". The server requires the unit on every new submission (NarcoticsCheckService).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('narcotics_checks')) {
            return;
        }

        Schema::table('narcotics_checks', function (Blueprint $table): void {
            if (! Schema::hasColumn('narcotics_checks', 'rescue_unit')) {
                $table->string('rescue_unit', 40)->nullable()->after('member_note');
            }
            if (! Schema::hasColumn('narcotics_checks', 'rescue_unit_other')) {
                $table->string('rescue_unit_other', 40)->nullable()->after('rescue_unit');
            }
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Narcotics check records are never altered backwards outside local or testing.');
        }
        if (! Schema::hasTable('narcotics_checks')) {
            return;
        }

        Schema::table('narcotics_checks', function (Blueprint $table): void {
            foreach (['rescue_unit_other', 'rescue_unit'] as $column) {
                if (Schema::hasColumn('narcotics_checks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
