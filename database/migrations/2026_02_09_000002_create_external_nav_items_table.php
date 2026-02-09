<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_nav_items', function (Blueprint $table) {
            $table->id();
            $table->string('division')->default('training')->index();
            $table->string('label');
            $table->string('slug');
            $table->string('type')->default('iframe'); // iframe, api_table
            $table->text('url')->nullable();
            $table->foreignId('external_source_id')->nullable()->constrained('external_sources')->nullOnDelete();
            $table->unsignedInteger('baserow_workspace_id')->nullable();
            $table->unsignedInteger('baserow_database_id')->nullable();
            $table->unsignedInteger('baserow_table_id')->nullable();
            $table->unsignedInteger('baserow_view_id')->nullable();
            $table->json('allowed_roles')->default('[]');
            $table->json('allowed_permissions')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('open_in_new_tab')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['division', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_nav_items');
    }
};
