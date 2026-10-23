<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->timestamp('test_excluded_at')->nullable()->index();
            $table->foreignId('test_excluded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('test_exclusion_reason')->nullable();
        });

        Schema::create('apparatus_inspection_test_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_inspection_id')->constrained('apparatus_inspections')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 16);
            $table->text('reason');
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();
            $table->index(['apparatus_inspection_id', 'id'], 'inspection_test_events_inspection_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_inspection_test_events');
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('test_excluded_by_user_id');
            $table->dropIndex(['test_excluded_at']);
            $table->dropColumn(['test_excluded_at', 'test_exclusion_reason']);
        });
    }
};
