<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_profile_id')
                ->nullable()
                ->unique()
                ->constrained('employees')
                ->restrictOnDelete();
            $table->enum('account_status', ['pending_activation', 'active', 'disabled'])
                ->default('pending_activation');
            $table->unsignedBigInteger('security_version')->default(1);
            $table->timestamp('password_changed_at')->nullable();
        });

        Schema::create('device_principals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 64);
            $table->foreignId('station_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('room_identifier', 128)->nullable();
            $table->json('abilities')->nullable();
            $table->string('credential_key_hash', 64)->nullable();
            $table->string('credential_key_id', 128)->nullable()->unique();
            $table->enum('status', ['active', 'disabled', 'revoked'])->default('active');
            $table->unsignedBigInteger('security_version')->default(1);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('authentication_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_principal_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('session_id_hash', 64)->unique();
            $table->unsignedBigInteger('security_version');
            $table->enum('context_class', ['managed_city', 'enrolled_phone', 'unmanaged_browser', 'shared_station', 'kiosk_overlay', 'privileged']);
            $table->timestamp('issued_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('idle_expires_at');
            $table->timestamp('absolute_expires_at');
            $table->timestamp('recent_auth_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->string('user_agent_label', 255)->nullable();
            $table->string('last_ip_prefix', 64)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
            $table->index(['user_id', 'last_activity_at']);
        });

        Schema::create('persistent_login_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_principal_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('selector_hash', 64)->unique();
            $table->string('validator_hash', 64);
            $table->enum('context_class', ['managed_city', 'enrolled_phone', 'unmanaged_browser', 'shared_station', 'kiosk_overlay', 'privileged']);
            $table->timestamp('issued_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persistent_login_credentials');
        Schema::dropIfExists('authentication_sessions');
        Schema::dropIfExists('device_principals');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_profile_id']);
            $table->dropUnique(['employee_profile_id']);
            $table->dropColumn([
                'employee_profile_id',
                'account_status',
                'security_version',
                'password_changed_at',
            ]);
        });
    }
};
