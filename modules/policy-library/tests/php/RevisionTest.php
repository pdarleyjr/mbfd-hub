<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\ImportService;
use Mbfd\PolicyLibrary\Services\PdfService;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Mbfd\PolicyLibrary\Services\TreeService;

final class RevisionTest extends TestCase
{
    private function node(): ManualNode
    {
        $manual = Manual::query()->create(['name' => 'Medical Protocols', 'slug' => 'medical', 'type' => 'medical']);
        $edition = $manual->editions()->create(['label' => 'Test edition']);

        return $edition->nodes()->create(['manual_id' => $manual->id, 'title' => 'Protocol', 'slug' => 'protocol', 'type' => 'document']);
    }

    private function draft(ManualNode $node, string $bytes = 'PDF content'): DocumentRevision
    {
        $path = $this->privateRoot.'/incoming.pdf';
        if (! is_dir($this->privateRoot)) {
            mkdir($this->privateRoot, 0700, true);
        }
        file_put_contents($path, $bytes);
        $this->app->instance(PdfService::class, new class extends PdfService
        {
            public function inspect(string $path): array
            {
                return ['page_count' => 2, 'sha256' => hash_file('sha256', $path)];
            }

            public function pageText(string $path): array
            {
                return ['Page one', 'Page two'];
            }
        });

        return $this->app->make(RevisionService::class)->createDraft($node, $path, 'protocol.pdf', null);
    }

    public function test_publication_and_rollback_preserve_content_and_audit(): void
    {
        $node = $this->node();
        $first = $this->draft($node, 'first revision');
        $service = $this->app->make(RevisionService::class);
        $service->publish($first, null);
        $this->assertSame($first->id, $node->fresh()->current_revision_id);
        $second = $this->draft($node, 'second revision');
        $service = $this->app->make(RevisionService::class);
        $service->publish($second, null);
        $this->assertSame('archived', $first->fresh()->state);
        $service->publish($first, null);
        $this->assertSame($first->id, $node->fresh()->current_revision_id);
        $this->assertSame('revision.rollback', DB::table('policy_audit_events')->latest('id')->value('action'));
        $this->assertSame('first revision', file_get_contents($service->path($first->storage_path)));
        $this->expectException(\LogicException::class);
        $first->update(['storage_path' => 'revisions/changed.pdf']);
    }

    public function test_corrupt_draft_cannot_replace_published_revision(): void
    {
        $node = $this->node();
        $first = $this->draft($node, 'first revision');
        $service = $this->app->make(RevisionService::class);
        $service->publish($first, null);
        $draft = $this->draft($node, 'draft revision');
        $service = $this->app->make(RevisionService::class);
        file_put_contents($service->path($draft->storage_path), 'corrupt');
        try {
            $service->publish($draft, null);
            $this->fail('Corrupt revision published.');
        } catch (ValidationException) {
        }
        $this->assertSame($first->id, $node->fresh()->current_revision_id);
        $this->assertSame('published', $first->fresh()->state);
    }

    public function test_edition_switch_is_atomic_and_hidden_drafts_do_not_leak(): void
    {
        $node = $this->node();
        $first = $this->draft($node, 'published revision');
        $node->update(['current_revision_id' => $first->id]);
        $imports = $this->app->make(ImportService::class);
        $imports->publish($node->edition, null);
        $manual = $node->manual->fresh();
        $draftEdition = $manual->editions()->create(['label' => 'Next edition']);
        $draftNode = $draftEdition->nodes()->create(['manual_id' => $manual->id, 'title' => 'New protocol', 'slug' => 'new-protocol', 'type' => 'document']);
        $user = $this->user();
        $this->actingAs($user)->withSession(['policy-library.access' => $this->accessGrant($user)]);
        $this->getJson('https://files.mbfdhub.com/api/nodes/'.$draftNode->id.'/document')->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/assets/'.$first->uuid)->assertOk()->assertHeader('Cache-Control', 'max-age=300, must-revalidate, private');
        try {
            $imports->publish($draftEdition, null);
            $this->fail('Empty edition published.');
        } catch (ValidationException) {
        }
        $this->assertSame($node->edition_id, $manual->fresh()->active_edition_id);
        $revision = $this->draft($draftNode, 'new valid revision');
        $draftNode->update(['current_revision_id' => $revision->id]);
        $this->app->make(ImportService::class)->publish($draftEdition, null);
        $this->assertSame($draftEdition->id, $manual->fresh()->active_edition_id);
        $this->getJson('https://files.mbfdhub.com/assets/'.$first->uuid)->assertNotFound();
    }

    public function test_hidden_ancestor_hides_document_and_asset(): void
    {
        $node = $this->node();
        $section = $node->edition->nodes()->create(['manual_id' => $node->manual_id, 'title' => 'Section', 'slug' => 'section', 'type' => 'section', 'is_active' => false]);
        $node->update(['parent_id' => $section->id]);
        $revision = $this->draft($node);
        $node->update(['current_revision_id' => $revision->id]);
        $this->app->make(ImportService::class)->publish($node->edition, null);
        $user = $this->user();
        $this->actingAs($user)->withSession(['policy-library.access' => $this->accessGrant($user)]);
        $this->getJson('https://files.mbfdhub.com/api/nodes/'.$node->id.'/document')->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/assets/'.$revision->uuid)->assertNotFound();
    }

    public function test_tree_cannot_cycle_or_move_between_editions(): void
    {
        $node = $this->node();
        $node->update(['type' => 'section']);
        $child = $node->edition->nodes()->create(['manual_id' => $node->manual_id, 'title' => 'Child', 'slug' => 'child', 'type' => 'section', 'parent_id' => $node->id]);
        $this->expectException(ValidationException::class);
        $this->app->make(TreeService::class)->updateNode($node, ['parent_id' => $child->id]);
    }

    public function test_navigation_updates_cannot_change_edition_identity_or_use_document_parent(): void
    {
        $node = $this->node();
        $edition = $node->manual->editions()->create(['label' => 'Other edition']);
        $other = $edition->nodes()->create(['manual_id' => $node->manual_id, 'title' => 'Other section', 'slug' => 'other', 'type' => 'section']);
        $service = app(TreeService::class);
        foreach ([['edition_id' => $edition->id], ['parent_id' => $other->id], ['parent_id' => $node->id]] as $data) {
            try {
                $service->updateNode($node, $data);
                $this->fail('An invalid navigation update was accepted.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame($node->edition_id, $node->fresh()->edition_id);
        $this->assertNull($node->fresh()->parent_id);
    }

    public function test_real_pdf_boundary_rejects_non_pdf_before_processing(): void
    {
        mkdir($this->privateRoot, 0700, true);
        $path = $this->privateRoot.'/not-a-pdf.pdf';
        file_put_contents($path, '<html>not a PDF</html>');
        $this->expectException(ValidationException::class);
        (new PdfService)->inspect($path);
    }

    public function test_existing_page_metadata_cannot_be_changed_or_deleted(): void
    {
        $revision = $this->draft($this->node());
        $page = $revision->pages()->firstOrFail();
        try {
            $page->update(['text' => 'Changed text']);
            $this->fail('Revision page text was modified.');
        } catch (\LogicException) {
        }
        try {
            $page->delete();
            $this->fail('Revision page metadata was deleted.');
        } catch (\LogicException) {
        }
        $this->assertSame('Page one', $page->fresh()->text);
    }
}
