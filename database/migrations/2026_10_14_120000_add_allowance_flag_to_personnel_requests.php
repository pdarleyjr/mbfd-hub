<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: flag uniform requests submitted over the member's remaining annual
 * allowance, with the member's justification. Existing rows stay NULL/false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel_requests', function (Blueprint $table): void {
            $table->boolean('over_allowance')->nullable()->default(false);
            $table->text('allowance_justification')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personnel_requests', function (Blueprint $table): void {
            $table->dropColumn(['over_allowance', 'allowance_justification']);
        });
    }
};
