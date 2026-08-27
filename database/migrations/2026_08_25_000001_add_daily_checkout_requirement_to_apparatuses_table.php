<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatuses', function (Blueprint $table): void {
            // Existing apparatus are deliberately not inferred or backfilled.
            // An authorized operational owner must classify each record before
            // it becomes eligible for Daily Checkout.
            $table->string('daily_checkout_requirement', 32)
                ->default('unknown')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table): void {
            $table->dropIndex(['daily_checkout_requirement']);
            $table->dropColumn('daily_checkout_requirement');
        });
    }
};
