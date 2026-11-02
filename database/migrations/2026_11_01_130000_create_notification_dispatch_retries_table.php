<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Alerts waiting for the queue to take them. Normally empty. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_dispatch_retries')) {
            return;
        }

        Schema::create('notification_dispatch_retries', function (Blueprint $table): void {
            $table->id();
            $table->text('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatch_retries');
    }
};
