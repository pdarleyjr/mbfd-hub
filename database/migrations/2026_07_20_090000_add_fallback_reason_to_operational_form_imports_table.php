<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_form_imports', function (Blueprint $table): void {
            $table->string('fallback_reason', 64)->nullable()->after('fallback_used');
            $table->index(['fallback_used', 'fallback_reason']);
        });
    }

    public function down(): void
    {
        Schema::table('operational_form_imports', function (Blueprint $table): void {
            $table->dropIndex(['fallback_used', 'fallback_reason']);
            $table->dropColumn('fallback_reason');
        });
    }
};
