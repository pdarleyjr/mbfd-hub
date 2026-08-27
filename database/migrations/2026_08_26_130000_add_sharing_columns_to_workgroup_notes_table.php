<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workgroup_notes', 'is_shared')) {
            Schema::table('workgroup_notes', function (Blueprint $table): void {
                $table->boolean('is_shared')->default(false);
            });
        }

        if (! Schema::hasColumn('workgroup_notes', 'shared_with_user_id')) {
            Schema::table('workgroup_notes', function (Blueprint $table): void {
                $table->foreignId('shared_with_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // This is intentionally an additive, data-preserving migration. A code rollback
        // can safely leave these fields in place, while a destructive rollback could
        // remove sharing data that pre-dated this migration in an existing installation.
    }
};
