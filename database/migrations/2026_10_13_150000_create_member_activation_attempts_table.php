<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-member guard for self-service activation: wrong answers are
        // counted here so guessing locks the path instead of succeeding.
        Schema::create('member_activation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_profile_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_activation_attempts');
    }
};
