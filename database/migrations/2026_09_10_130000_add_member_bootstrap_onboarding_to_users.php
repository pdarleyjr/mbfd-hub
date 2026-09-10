<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('bootstrap_onboarding_eligible')->default(false)->index();
            $table->timestamp('bootstrap_onboarding_eligible_at')->nullable();
            $table->timestamp('bootstrap_onboarding_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['bootstrap_onboarding_eligible']);
            $table->dropColumn([
                'bootstrap_onboarding_eligible',
                'bootstrap_onboarding_eligible_at',
                'bootstrap_onboarding_completed_at',
            ]);
        });
    }
};
