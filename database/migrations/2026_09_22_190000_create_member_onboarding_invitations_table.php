<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_onboarding_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employees')->cascadeOnDelete();
            $table->string('email');
            $table->unsignedInteger('security_version');
            $table->string('token_hash', 64)->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->string('redeemed_binding_hash', 64)->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->string('delivery_status', 16)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_onboarding_invitations');
    }
};
