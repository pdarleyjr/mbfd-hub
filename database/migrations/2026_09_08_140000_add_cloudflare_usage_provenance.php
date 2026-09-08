<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloudflare_usage_budgets', function (Blueprint $table): void {
            $table->string('provider_account_id', 32)->nullable();
            $table->string('provider_usage_source', 64)->nullable();
            $table->timestamp('provider_billing_measured_through')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cloudflare_usage_budgets', function (Blueprint $table): void {
            $table->dropColumn(['provider_account_id', 'provider_usage_source', 'provider_billing_measured_through']);
        });
    }
};
