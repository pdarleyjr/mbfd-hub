<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_name', 255);
            $table->char('workbook_sha256', 64)->index();
            $table->jsonb('summary');
            // Contains proposed contact information, encrypted by Laravel at rest.
            $table->longText('rows');
            $table->timestampsTz();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('personnel_reconciliations')->exists()) {
            throw new LogicException('Reconciliation evidence must be retained during code rollback.');
        }

        Schema::dropIfExists('personnel_reconciliations');
    }
};
