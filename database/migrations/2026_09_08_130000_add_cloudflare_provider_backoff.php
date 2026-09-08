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
            $table->timestamp('provider_backoff_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cloudflare_usage_budgets', function (Blueprint $table): void {
            $table->dropColumn('provider_backoff_until');
        });
    }
};
