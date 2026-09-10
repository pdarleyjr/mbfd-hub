<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identity_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('subject', 255);
            $table->string('provider_user_id', 255)->nullable();
            $table->string('status', 32)->default('pending');
            // Laravel's encrypted:array cast stores an encrypted string, not JSON.
            $table->text('security_state')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'subject']);
            $table->unique(['provider', 'user_id']);
        });

        Schema::create('identity_synchronizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('provider', 40);
            $table->unsignedBigInteger('requested_security_version');
            $table->boolean('desired_active');
            $table->boolean('revoke_sessions')->default(false);
            $table->boolean('reset_mfa')->default(false);
            $table->string('state', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'user_id', 'requested_security_version']);
            $table->index(['state', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_synchronizations');
        Schema::dropIfExists('user_identity_links');
    }
};
