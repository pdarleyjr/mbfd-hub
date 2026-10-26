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
        Schema::table('resource_documents', function (Blueprint $table): void {
            $table->foreignId('recipient_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            // Separate from shared publication: older releases must never list personal files.
            $table->boolean('taskbook_published')->default(false);
            $table->index(['recipient_employee_id', 'taskbook_published'], 'resource_documents_taskbook_recipient_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resource_documents ADD CONSTRAINT resource_documents_personal_not_shared CHECK (recipient_employee_id IS NULL OR published = false)');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER resource_documents_personal_not_shared_'.strtolower($operation)
                    .' BEFORE '.$operation.' ON resource_documents'
                    .' WHEN NEW.recipient_employee_id IS NOT NULL AND NEW.published = 1'
                    ." BEGIN SELECT RAISE(ABORT, 'Personal taskbooks cannot be shared.'); END");
            }
        } else {
            throw new LogicException('Personal taskbooks require a PostgreSQL or SQLite visibility constraint.');
        }
    }

    public function down(): void
    {
        if (DB::table('resource_documents')->whereNotNull('recipient_employee_id')->exists()) {
            throw new LogicException('Personal taskbook recipients must be retained during rollback.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resource_documents DROP CONSTRAINT resource_documents_personal_not_shared');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER resource_documents_personal_not_shared_insert');
            DB::unprepared('DROP TRIGGER resource_documents_personal_not_shared_update');
        }

        Schema::table('resource_documents', function (Blueprint $table): void {
            $table->dropIndex('resource_documents_taskbook_recipient_idx');
            $table->dropConstrainedForeignId('recipient_employee_id');
            $table->dropColumn('taskbook_published');
        });
    }
};
