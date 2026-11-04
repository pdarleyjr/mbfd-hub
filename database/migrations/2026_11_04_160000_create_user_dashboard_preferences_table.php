<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How one staff member likes the Admin Overview ordered (docs/unified-activity-and-ownership.md section 5).
 *
 * Additive only: no row means "default order for everything", so deploying this changes nothing for anyone. A row is
 * written only when somebody picks a non-default order and is removed again when both are back on the default.
 * It never grants access; what a person sees is still decided by each domain's own permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_dashboard_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('issue_sort', 24)->nullable();
            $table->string('activity_sort', 24)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Dashboard order preferences are only dropped in local or testing.');
        }

        Schema::dropIfExists('user_dashboard_preferences');
    }
};
