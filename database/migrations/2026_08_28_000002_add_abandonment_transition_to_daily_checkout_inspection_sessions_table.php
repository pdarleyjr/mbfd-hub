<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_checkout_inspection_sessions', function (Blueprint $table): void {
            $table->foreignId('prior_inspection_session_id')
                ->nullable()
                ->constrained('daily_checkout_inspection_sessions')
                ->nullOnDelete();
            $table->timestampTz('abandoned_at')->nullable();
            $table->foreignId('abandoned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('abandoned_by_session_hash', 64)->nullable();
            $table->string('abandonment_reason')->nullable();
            $table->string('abandonment_transition_type')->nullable();
            $table->uuid('abandonment_transition_key')->nullable()->unique();
            $table->foreignId('replacement_session_id')
                ->nullable()
                ->unique()
                ->constrained('daily_checkout_inspection_sessions')
                ->nullOnDelete();

            $table->index(['apparatus_id', 'abandoned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_checkout_inspection_sessions', function (Blueprint $table): void {
            $table->dropIndex(['apparatus_id', 'abandoned_at']);
            $table->dropConstrainedForeignId('replacement_session_id');
            $table->dropUnique(['abandonment_transition_key']);
            $table->dropColumn([
                'abandoned_at',
                'abandoned_by_user_id',
                'abandoned_by_session_hash',
                'abandonment_reason',
                'abandonment_transition_type',
                'abandonment_transition_key',
            ]);
            $table->dropConstrainedForeignId('prior_inspection_session_id');
        });
    }
};
