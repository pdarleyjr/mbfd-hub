<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\ReadingViewService;
use Mbfd\PolicyLibrary\Services\SearchService;
use Mbfd\PolicyLibrary\Services\TreeService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Canonical Hub dependency/application coverage; synthetic test bytes are not publication artifacts. */
final class CanonicalReadingDataTest extends TestCase
{
    use RefreshDatabase;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateRoot = sys_get_temp_dir().'/mbfd-canonical-reading-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->privateRoot.'/reading', 0700, true);
        config()->set('policy-library.storage_root', $this->privateRoot);
    }

    protected function tearDown(): void
    {
        if (isset($this->privateRoot) && str_starts_with($this->privateRoot, sys_get_temp_dir().'/mbfd-canonical-reading-')) {
            File::deleteDirectory($this->privateRoot);
        }
        parent::tearDown();
    }

    private function documents(): array
    {
        $manual = Manual::query()->create(['name' => 'Reading integration', 'slug' => 'reading-integration', 'type' => 'sog']);
        $edition = $manual->editions()->create(['label' => 'Current', 'state' => 'published']);
        $manual->update(['active_edition_id' => $edition->id]);
        $nodes = [];
        foreach (['SECTION-100', '100.01'] as $asset) {
            $node = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => $asset, 'slug' => Str::slug($asset), 'metadata' => ['asset_id' => $asset, 'search_role' => $asset === 'SECTION-100' ? 'aggregate' : 'leaf']]);
            $revision = $node->revisions()->create(['uuid' => (string) Str::uuid(), 'source_filename' => 'synthetic-unit.pdf', 'storage_path' => 'revisions/'.str_repeat('a', 64).'.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 1, 'state' => 'published', 'metadata' => [
                'asset_id' => $asset, 'search_role' => $asset === 'SECTION-100' ? 'aggregate' : 'leaf', 'leaf_asset_ids' => ['100.01'],
                'source_docx_sha256' => str_repeat('b', 64), 'primary_entries' => [['id' => '100.01', 'slug' => '100-01', 'title' => 'Synthetic policy', 'physical_page' => 1, 'semantic_pages' => [1], 'semantic_text' => 'needle']],
            ]]);
            $revision->pages()->create(['page' => 1, 'title' => 'Synthetic policy', 'text' => 'needle']);
            $node->update(['current_revision_id' => $revision->id]);
            $nodes[] = $node->fresh();
        }

        return [$manual->fresh(), ...$nodes];
    }

    private function bindArtifact(ManualNode $node, array $changes = []): void
    {
        $data = array_replace_recursive([
            'schema' => 'mbfd-reading-v1', 'pdf_sha256' => str_repeat('a', 64), 'source_docx_sha256' => str_repeat('b', 64), 'page_count' => 1,
            'entries' => [['id' => '100.01', 'title' => 'Synthetic policy', 'blocks' => [
                ['type' => 'paragraph', 'text' => 'Plain <script> text', 'pdf_page' => 1],
            ]]],
        ], $changes);
        $bytes = json_encode($data, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $bytes);
        File::put($this->privateRoot.'/reading/'.$hash.'.json', $bytes);
        // A new immutable test revision models the governed import, never a metadata amendment.
        $revision = $node->revisions()->create([...$node->currentRevision->only(['source_filename', 'storage_path', 'sha256', 'page_count', 'state']),
            'uuid' => (string) Str::uuid(), 'metadata' => [...$node->currentRevision->metadata, 'reading_view' => ['sha256' => $hash, 'validated' => true]]]);
        $node->update(['current_revision_id' => $revision->id]);
        $node->refresh();
    }

    public function test_canonical_application_delivers_only_the_hash_bound_plain_representation(): void
    {
        [, , $leaf] = $this->documents();
        $this->bindArtifact($leaf);
        $output = $this->app->make(ReadingViewService::class)->load($leaf->currentRevision);
        self::assertSame('Plain <script> text', $output['entries'][0]['blocks'][0]['text']);
        self::assertSame($leaf->currentRevision->uuid, $output['revision_id']);
        self::assertArrayNotHasKey('storage_root', $output);
    }

    public function test_canonical_application_rejects_a_wrong_pdf_binding(): void
    {
        [, , $leaf] = $this->documents();
        $this->bindArtifact($leaf, ['pdf_sha256' => str_repeat('c', 64)]);
        try {
            $this->app->make(ReadingViewService::class)->load($leaf->currentRevision);
            self::fail('Foreign PDF text was accepted');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_hidden_or_unpublished_leaf_cannot_reappear_through_the_aggregate(): void
    {
        [$manual, $aggregate, $leaf] = $this->documents();
        $trees = $this->app->make(TreeService::class);
        self::assertContains($aggregate->id, $trees->documentIds($manual));
        $leaf->update(['is_active' => false]);
        self::assertSame([], $trees->documentIds($manual));
        self::assertSame([], $trees->tree($manual)['documents']);
        $leaf->update(['is_active' => true]);
        $leaf->currentRevision->update(['state' => 'draft']);
        self::assertSame([], $trees->documentIds($manual));
    }

    public function test_default_search_uses_the_leaf_and_withholds_hidden_aggregate_content(): void
    {
        [$manual, , $leaf] = $this->documents();
        $trees = $this->app->make(TreeService::class);
        $search = $this->app->make(SearchService::class);
        self::assertSame([$leaf->id], array_column($search->search('needle', $manual->slug, $trees)['results'], 'node_id'));
        $leaf->update(['is_active' => false]);
        self::assertSame([], $search->search('needle', $manual->slug, $trees)['results']);
    }

    public function test_replacement_upload_cannot_erase_the_persistent_aggregate_declaration(): void
    {
        [$manual, $aggregate, $leaf] = $this->documents();
        $trees = $this->app->make(TreeService::class);
        $search = $this->app->make(SearchService::class);
        self::assertContains($aggregate->id, $trees->documentIds($manual));
        // Standard admin upload supplies version/date/notes, without the governed coverage declaration.
        // Model its newly published immutable revision; the node declaration survives replacement.
        $replacement = $aggregate->revisions()->create([...$aggregate->currentRevision->only(['source_filename', 'storage_path', 'sha256', 'page_count', 'state']),
            'uuid' => (string) Str::uuid(), 'metadata' => ['version_label' => 'Replacement', 'revision_date' => '2026-10-07', 'revision_notes' => 'Synthetic upload regression']]);
        $replacement->pages()->create(['page' => 1, 'title' => 'Synthetic policy', 'text' => 'needle SECTION-100']);
        $aggregate->update(['current_revision_id' => $replacement->id]);
        self::assertSame([$leaf->id], $trees->documentIds($manual));
        self::assertSame([$leaf->id], $trees->tree($manual)['documents']);
        self::assertSame([$leaf->id], array_column($search->search('needle', $manual->slug, $trees)['results'], 'node_id'));
        self::assertSame([], $search->search('SECTION-100', $manual->slug, $trees)['results']);
        $leaf->update(['is_active' => false]);
        self::assertSame([], $trees->documentIds($manual));
        self::assertSame([], $trees->tree($manual)['documents']);
        self::assertSame([], $search->search('needle', $manual->slug, $trees)['results']);
        self::assertSame([], $search->search('SECTION-100', $manual->slug, $trees)['results']);
    }

    public function test_node_declared_aggregate_requires_its_replacement_revision_role_and_coverage(): void
    {
        [$manual, $aggregate, $leaf] = $this->documents();
        $trees = $this->app->make(TreeService::class);
        foreach ([['leaf_asset_ids' => ['100.01']], ['search_role' => 'aggregate']] as $metadata) {
            $replacement = $aggregate->revisions()->create([...$aggregate->currentRevision->only(['source_filename', 'storage_path', 'sha256', 'page_count', 'state']),
                'uuid' => (string) Str::uuid(), 'metadata' => $metadata]);
            $aggregate->update(['current_revision_id' => $replacement->id]);
            $aggregate->refresh();
            self::assertSame([$leaf->id], $trees->documentIds($manual));
            self::assertSame([$leaf->id], $trees->tree($manual)['documents']);
        }
    }

    private function foreignLeafRevision(Manual $manual, bool $otherManual): DocumentRevision
    {
        if ($otherManual) {
            $manual = Manual::query()->create(['name' => 'Other manual', 'slug' => 'reading-other', 'type' => 'sog']);
        }
        $edition = $manual->editions()->create(['label' => 'Foreign edition', 'state' => 'published']);
        $node = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => 'Foreign policy', 'slug' => 'foreign-policy', 'metadata' => ['asset_id' => '100.01']]);

        return $node->revisions()->create(['uuid' => (string) Str::uuid(), 'source_filename' => 'synthetic-foreign.pdf',
            'storage_path' => 'revisions/'.str_repeat('b', 64).'.pdf', 'sha256' => str_repeat('b', 64),
            'page_count' => 1, 'state' => 'published', 'metadata' => ['asset_id' => '100.01']]);
    }

    public function test_old_edition_revision_cannot_satisfy_current_aggregate_coverage(): void
    {
        [$manual, , $leaf] = $this->documents();
        $leaf->update(['current_revision_id' => $this->foreignLeafRevision($manual, false)->id]);
        $trees = $this->app->make(TreeService::class);
        self::assertSame([], $trees->documentIds($manual));
        $tree = $trees->tree($manual);
        self::assertSame([], $tree['documents']);
        self::assertNull($tree['nodes'][0]['revision']);
        self::assertSame([], $this->app->make(SearchService::class)->search('SECTION-100', $manual->slug, $trees)['results']);
    }

    public function test_another_manual_revision_cannot_satisfy_current_aggregate_coverage(): void
    {
        [$manual, , $leaf] = $this->documents();
        $leaf->update(['current_revision_id' => $this->foreignLeafRevision($manual, true)->id]);
        $trees = $this->app->make(TreeService::class);
        self::assertSame([], $trees->documentIds($manual));
        self::assertSame([], $trees->tree($manual)['documents']);
    }

    public function test_protected_aggregate_asset_withholds_foreign_leaf_revision_coverage(): void
    {
        [$manual, $aggregate, $leaf] = $this->documents();
        $leaf->update(['current_revision_id' => $this->foreignLeafRevision($manual, false)->id]);
        File::makeDirectory($this->privateRoot.'/revisions', 0700, true);
        // Controlled gate bytes only; this test makes no PDF fidelity claim.
        File::put($this->privateRoot.'/revisions/'.str_repeat('a', 64).'.pdf', 'Synthetic aggregate gate fixture');
        config()->set('policy-library.accel_prefix', null);
        $user = $this->actingAsCanonicalFixture('QA-WEB-AGGREGATE', 'QA Aggregate');
        $this->withSession(['policy-library.access' => ['user_id' => (string) $user->id,
            'expires_at' => now()->timestamp + 60, 'pin_version' => hash('sha256', (string) config('policy-library.pin_hash'))]])
            ->get('https://files.mbfdhub.com/assets/'.$aggregate->currentRevision->uuid)->assertNotFound();
    }
}
