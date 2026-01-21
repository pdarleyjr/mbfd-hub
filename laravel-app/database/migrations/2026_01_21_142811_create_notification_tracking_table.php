<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('capital_projects')->cascadeOnDelete();
            $table->string('notification_type');
            $table->timestamp('sent_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->string('action_taken')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'sent_at']);
            $table->index(['project_id', 'notification_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_tracking');
    }
};
