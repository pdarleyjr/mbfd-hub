<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_import_batches', function (Blueprint $table): void {
            $table->foreignId('manual_id')->nullable()->constrained('policy_manuals');
        });
    }

    public function down(): void
    {
        Schema::table('policy_import_batches', fn (Blueprint $table) => $table->dropConstrainedForeignId('manual_id'));
    }
};
