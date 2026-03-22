<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_alert_events', function (Blueprint $table) {
            $table->id();
            $table->string('type')->comment('defect_created|recommendation_created|low_stock|allocation_made');
            $table->string('severity')->default('info')->comment('info|warning|critical');
            $table->text('message');
            $table->string('related_type')->nullable()->comment('Polymorphic type: apparatus_defect, equipment_item, etc.');
            $table->unsignedBigInteger('related_id')->nullable()->comment('Polymorphic ID');
            $table->boolean('is_read')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            // Indexes
            $table->index('type');
            $table->index('is_read');
            $table->index('created_at');
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_alert_events');
    }
};
