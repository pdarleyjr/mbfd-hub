<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nextcloud_access_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('external_uid', 64)->unique();
            $table->boolean('desired_enabled');
            $table->unsignedBigInteger('security_version');
            $table->unsignedBigInteger('requested_revision')->default(1);
            $table->unsignedBigInteger('applied_revision')->default(0);
            $table->timestampTz('verified_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nextcloud_access_syncs');
    }
};
