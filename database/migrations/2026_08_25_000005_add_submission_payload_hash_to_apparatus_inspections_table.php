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
            // Existing historical rows cannot be reconstructed losslessly, so
            // they remain nullable and are not eligible for a blind replay.
            $table->string('submission_payload_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropColumn('submission_payload_hash');
        });
    }
};
