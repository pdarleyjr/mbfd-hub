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
            // Nullable keeps historical records intact; new public submissions
            // must provide a UUID at the request boundary.
            $table->uuid('client_submission_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropUnique(['client_submission_id']);
            $table->dropColumn('client_submission_id');
        });
    }
};
