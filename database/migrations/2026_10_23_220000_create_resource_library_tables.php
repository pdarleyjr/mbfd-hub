<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Member-facing document library (Employee > Resources).
 *
 * Document bytes live on the private disk; these tables hold metadata, extracted search text and an
 * append-only import/replace history. Also creates the Admin capability pair that manages the
 * library. It is granted to nobody here: the production deploy gate refuses permission changes on
 * established accounts, so access is given deliberately in the Admin capability editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 120)->unique();
            $table->string('title', 200);
            $table->string('category', 120);
            $table->text('description')->nullable();
            $table->string('original_filename', 255);
            $table->string('original_mime', 150);
            $table->unsignedBigInteger('original_size');
            $table->char('original_sha256', 64)->unique();
            $table->string('original_path', 255);
            $table->string('preview_path', 255)->nullable();
            $table->char('preview_sha256', 64)->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->longText('search_text')->nullable();
            $table->boolean('published')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['published', 'category', 'sort'], 'resource_documents_listing_idx');
        });

        Schema::create('resource_document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_document_id')->constrained('resource_documents')->cascadeOnDelete();
            $table->string('event', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['resource_document_id', 'created_at'], 'resource_document_events_timeline_idx');
        });

        $this->provisionPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_document_events');
        Schema::dropIfExists('resource_documents');
    }

    private function provisionPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        foreach (['admin.resources.view', 'admin.resources.manage'] as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
