<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_manuals', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('active_edition_id')->nullable();
            $table->timestamps();
        });
        Schema::create('policy_editions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manual_id')->constrained('policy_manuals');
            $table->string('label');
            $table->string('state')->default('draft');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::table('policy_manuals', fn (Blueprint $table) => $table->foreign('active_edition_id')->references('id')->on('policy_editions'));
        Schema::create('policy_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manual_id')->constrained('policy_manuals');
            $table->foreignId('edition_id')->constrained('policy_editions');
            $table->foreignId('parent_id')->nullable()->constrained('policy_nodes');
            $table->string('type')->default('document');
            $table->string('title');
            $table->string('short_title')->nullable();
            $table->string('slug');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['edition_id', 'parent_id', 'sort_order']);
            $table->unique(['edition_id', 'slug']);
        });
        Schema::create('policy_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('node_id')->constrained('policy_nodes');
            $table->string('version_label')->nullable();
            $table->date('revision_date')->nullable();
            $table->text('revision_notes')->nullable();
            $table->string('source_filename');
            $table->string('storage_path');
            $table->string('source_path')->nullable();
            $table->char('sha256', 64);
            $table->unsignedInteger('page_count');
            $table->string('state')->default('draft');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::table('policy_nodes', fn (Blueprint $table) => $table->foreign('current_revision_id')->references('id')->on('policy_revisions'));
        Schema::create('policy_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('policy_revisions');
            $table->unsignedInteger('page');
            $table->unsignedInteger('physical_page')->nullable();
            $table->string('printed_label')->nullable();
            $table->string('title')->nullable();
            $table->longText('text')->nullable();
            $table->unique(['revision_id', 'page']);
        });
        Schema::create('policy_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('action');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('manual_id')->nullable();
            $table->unsignedBigInteger('node_id')->nullable();
            $table->unsignedBigInteger('revision_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
        if (Schema::hasTable(config('permission.table_names.permissions', 'permissions'))) {
            Permission::findOrCreate('files.manage', 'web');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_audit_events');
        Schema::dropIfExists('policy_pages');
        Schema::table('policy_nodes', fn (Blueprint $table) => $table->dropForeign(['current_revision_id']));
        Schema::dropIfExists('policy_revisions');
        Schema::dropIfExists('policy_nodes');
        Schema::table('policy_manuals', fn (Blueprint $table) => $table->dropForeign(['active_edition_id']));
        Schema::dropIfExists('policy_editions');
        Schema::dropIfExists('policy_manuals');
    }
};
