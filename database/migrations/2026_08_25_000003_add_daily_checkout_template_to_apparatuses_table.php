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
            // This deliberately makes no policy or template inference for existing
            // records. Every row remains pending until an authorized owner selects
            // a template or the approved family mapping applies at resolution time.
            $table->string('daily_checkout_template', 32)
                ->default('pending')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table): void {
            $table->dropIndex(['daily_checkout_template']);
            $table->dropColumn('daily_checkout_template');
        });
    }
};
