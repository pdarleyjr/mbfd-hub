<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_supply_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->string('sent_via')->nullable(); // 'email', 'phone', 'manual'
            $table->string('status')->default('draft'); // draft, sent, manual_ordered, failed
            $table->string('subject')->nullable();
            $table->text('recipient_emails')->nullable(); // JSON array
            $table->string('vendor_name')->default('Grainger');
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_supply_orders');
    }
};
