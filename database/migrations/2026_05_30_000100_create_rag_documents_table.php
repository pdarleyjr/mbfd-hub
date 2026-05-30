<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks documents that admins upload into the support-chatbot RAG knowledge
 * base (Cloudflare Vectorize `mbfd-rag-index`). One row per document; the
 * chunk_ids let us delete a document's vectors later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            // The "source" sent to the worker — chatbot cites this + it prefixes the chunk ids.
            $table->string('source_key')->unique();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->json('chunk_ids')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime')->nullable();
            $table->string('status')->default('indexed'); // indexed | failed
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_documents');
    }
};
