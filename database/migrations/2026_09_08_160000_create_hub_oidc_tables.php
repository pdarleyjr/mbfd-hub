<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passport 13 persistence schema, with only the enabled code flow.
        Schema::create('oauth_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            $table->timestamps();
        });
        Schema::create('oauth_auth_codes', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->index();
            $table->foreignUuid('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });
        Schema::create('oauth_access_tokens', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignUuid('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });
        Schema::create('oidc_sessions', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->char('auth_code_id', 80)->unique();
            $table->char('access_token_id', 80)->nullable()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedBigInteger('security_version');
            $table->uuid('client_id');
            $table->string('application', 32);
            $table->string('external_uid')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('oidc_account_links', function (Blueprint $table): void {
            $table->id();
            $table->string('application', 32);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employees')->restrictOnDelete();
            $table->string('external_uid');
            $table->unique(['application', 'user_id']);
            $table->unique(['application', 'external_uid']);
            $table->timestamps();
        });
        // Define gates, never grant them to any user or role automatically.
        \Spatie\Permission\Models\Permission::findOrCreate('app.cmd.access', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('app.cloud.access', 'web');
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_account_links');
        Schema::dropIfExists('oidc_sessions');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_clients');
    }
};
