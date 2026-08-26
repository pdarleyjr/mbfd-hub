<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_inspection_review_events', function (Blueprint $table): void {
            $table->id();
            // Review evidence must remain tied to its inspection. The parent
            // deletion restriction prevents an audited decision from being
            // silently removed through the application model.
            $table->foreignId('apparatus_inspection_id')
                ->constrained('apparatus_inspections')
                ->restrictOnDelete();
            $table->string('previous_status', 32);
            $table->string('status', 32);
            $table->text('internal_note')->nullable();
            $table->foreignId('changed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index([
                'apparatus_inspection_id',
                'created_at',
            ], 'apparatus_inspection_review_events_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_inspection_review_events');
    }
};
