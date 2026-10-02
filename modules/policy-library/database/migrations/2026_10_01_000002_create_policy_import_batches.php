<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind');
            $table->string('state')->default('queued');
            $table->string('source_filename');
            $table->string('storage_path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->foreignId('section_id')->nullable()->constrained('policy_nodes');
            $table->json('edition_ids')->nullable();
            $table->text('status_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_import_batches');
    }
};
