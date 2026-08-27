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
            // Unauthenticated submissions retain their validated evidence here
            // until an authorized reviewer elects to apply operational effects.
            $table->json('pending_effects')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropColumn('pending_effects');
        });
    }
};
