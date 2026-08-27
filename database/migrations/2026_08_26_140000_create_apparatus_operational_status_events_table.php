<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_operational_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_id')
                ->constrained('apparatuses')
                ->restrictOnDelete();
            $table->string('previous_status')->nullable();
            $table->string('status');
            // This is the timestamp of the authoritative apparatus model write,
            // not the later time at which this append-only ledger row is read.
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(
                ['apparatus_id', 'changed_at', 'id'],
                'apparatus_operational_status_events_timeline_idx',
            );
        });
    }

    public function down(): void
    {
        // Deliberately preserve append-only operational-status evidence. A
        // rollback of application code must not silently delete that ledger.
    }
};
