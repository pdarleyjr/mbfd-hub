<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge Library retrieval (docs/ai-response-automation.md). Additive only.
 *
 *  - sample_phrases: optional short phrases a member might type to reach an article (JSON list). They count like
 *    tags when matching, and publishing is checked against them.
 *  - PostgreSQL only: a full-text index so ranking by ts_rank_cd stays cheap. Other drivers use the word scorer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ai_knowledge_article_versions', 'sample_phrases')) {
            Schema::table('ai_knowledge_article_versions', function (Blueprint $table): void {
                $table->json('sample_phrases')->nullable()->after('source_note');
            });
        }

        // No full-text index: the ranking query only ever reads a handful of already-selected rows by id.
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Knowledge retrieval columns are only dropped in local or testing.');
        }

        if (Schema::hasColumn('ai_knowledge_article_versions', 'sample_phrases')) {
            Schema::table('ai_knowledge_article_versions', function (Blueprint $table): void {
                $table->dropColumn('sample_phrases');
            });
        }
    }
};
