<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trusted self-service activation proofs. Date of birth and phone are
        // stored only as keyed digests and are cleared once the account is claimed.
        Schema::create('member_activation_verifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_profile_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('employee_id');
            $table->string('dob_digest', 64)->nullable();
            $table->string('phone_digest', 64)->nullable();
            $table->string('source_sha256', 64);
            $table->timestamp('approved_at');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_activation_verifiers');
    }
};
