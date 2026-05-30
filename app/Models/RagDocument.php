<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document ingested into the support-chatbot RAG knowledge base
 * (Cloudflare Vectorize `mbfd-rag-index`). Managed via the admin
 * Knowledge Base page.
 */
class RagDocument extends Model
{
    protected $fillable = [
        'filename',
        'source_key',
        'chunk_count',
        'chunk_ids',
        'size',
        'mime',
        'status',
        'uploaded_by',
    ];

    protected $casts = [
        'chunk_ids' => 'array',
        'chunk_count' => 'integer',
        'size' => 'integer',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
