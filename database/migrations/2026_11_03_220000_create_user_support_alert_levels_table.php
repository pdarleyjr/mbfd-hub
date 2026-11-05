<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Support Services priority levels notify a staff member (docs/support-services-triage.md).
 *
 * Additive only: no row means "all three levels on", so deploying this changes nothing for anyone. A row is
 * written only when somebody unchecks a level on Notification Settings. It narrows the optional new-issue and
 * new-request alerts; it never grants access and never touches permissions or channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_support_alert_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('low_enabled')->default(true);
            $table->boolean('medium_enabled')->default(true);
            $table->boolean('high_enabled')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Support alert level preferences are only dropped in local or testing.');
        }

        Schema::dropIfExists('user_support_alert_levels');
    }
};
