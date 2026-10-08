<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Illuminate\Support\Str;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;

final class ReadingViewTest extends TestCase
{
    private function document(array $changes = [], bool $validated = true): ManualNode
    {
        $manual = Manual::query()->create(['name' => 'SOGs', 'slug' => 'sogs', 'type' => 'sog']);
        $edition = $manual->editions()->create(['label' => 'Current', 'state' => 'published']);
        $manual->update(['active_edition_id' => $edition->id]);
        $node = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => 'Source policy', 'slug' => '100-01', 'metadata' => ['asset_id' => '100.01']]);
        $data = array_replace_recursive(['schema' => 'mbfd-reading-v1', 'pdf_sha256' => str_repeat('a', 64),
            'source_docx_sha256' => str_repeat('b', 64), 'page_count' => 2,
            'entries' => [['id' => '100.01', 'title' => 'Source policy', 'blocks' => [
                ['type' => 'paragraph', 'text' => 'Exact reviewed policy text <script>stays text</script>.', 'pdf_page' => 1],
                ['type' => 'pdf_reference', 'text' => 'Table or diagram', 'pdf_page' => 2],
            ]]]], $changes);
        $bytes = json_encode($data, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $bytes);
        mkdir($this->privateRoot.'/reading', 0700, true);
        file_put_contents($this->privateRoot.'/reading/'.$hash.'.json', $bytes);
        $revision = $node->revisions()->create(['uuid' => (string) Str::uuid(), 'source_filename' => 'policy.pdf', 'storage_path' => 'revisions/'.str_repeat('a', 64).'.pdf',
            'sha256' => str_repeat('a', 64), 'page_count' => 2, 'state' => 'published', 'metadata' => [
                'asset_id' => '100.01', 'source_docx_sha256' => str_repeat('b', 64), 'reading_view' => ['sha256' => $hash, 'validated' => $validated],
                'primary_entries' => [['id' => '100.01', 'slug' => '100-01', 'title' => 'Source policy', 'physical_page' => 1, 'semantic_pages' => [1, 2]]],
            ]]);
        $node->update(['current_revision_id' => $revision->id]);

        return $node->fresh();
    }

    private function member(): void
    {
        $user = $this->user();
        $this->actingAs($user)->withSession(['policy-library.access' => $this->accessGrant($user)]);
    }

    private function url(ManualNode $node): string
    {
        return 'https://files.mbfdhub.com/api/nodes/'.$node->id.'/reading?revision='.$node->currentRevision->uuid;
    }

    public function test_reading_text_requires_hub_identity_and_current_pin_grant(): void
    {
        $node = $this->document();
        $this->getJson($this->url($node))->assertUnauthorized();
        $user = $this->user();
        $this->actingAs($user)->getJson($this->url($node))->assertForbidden();
        $this->withSession(['policy-library.access' => $this->accessGrant($user)])->getJson($this->url($node))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('CDN-Cache-Control', 'no-store')
            ->assertJsonPath('revision_id', $node->currentRevision->uuid)->assertJsonPath('entries.0.blocks.1.pdf_page', 2)
            ->assertJsonPath('entries.0.blocks.0.text', 'Exact reviewed policy text <script>stays text</script>.');
        $this->withSession(['policy-library.access' => $this->accessGrant($user, -1)])->getJson($this->url($node))->assertForbidden();
    }

    public function test_pending_review_does_not_expose_reading_text_or_url(): void
    {
        $node = $this->document(validated: false);
        $this->member();
        $this->getJson($this->url($node))->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/api/nodes/'.$node->id.'/document')->assertOk()->assertJsonPath('revision.reading_url', null);
    }

    public function test_retired_edition_and_hidden_ancestor_cannot_deliver_reading_text(): void
    {
        $node = $this->document();
        $this->member();
        $hidden = $node->edition->nodes()->create(['manual_id' => $node->manual_id, 'title' => 'Hidden', 'slug' => 'hidden', 'type' => 'section', 'is_active' => false]);
        $node->update(['parent_id' => $hidden->id]);
        $this->getJson($this->url($node))->assertNotFound();
        $node->update(['parent_id' => null]);
        $node->manual->update(['active_edition_id' => null]);
        $this->getJson($this->url($node))->assertNotFound();
    }

    public function test_wrong_pdf_binding_fails_closed(): void
    {
        $node = $this->document(['pdf_sha256' => str_repeat('c', 64)]);
        $this->member();
        $this->getJson($this->url($node))->assertNotFound();
    }

    public function test_foreign_node_revision_pointer_cannot_deliver_reading_text(): void
    {
        $node = $this->document();
        $other = $node->edition->nodes()->create(['manual_id' => $node->manual_id, 'title' => 'Other', 'slug' => 'other']);
        $other->update(['current_revision_id' => $node->current_revision_id]);
        $this->member();
        $this->getJson($this->url($other->fresh()))->assertNotFound();
    }

    public function test_wrong_revision_query_and_invalid_page_ownership_fail_closed(): void
    {
        $node = $this->document(['entries' => [['blocks' => [['pdf_page' => 3]]]]]);
        $this->member();
        $this->getJson($this->url($node))->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/api/nodes/'.$node->id.'/reading?revision='.Str::uuid())->assertNotFound();
    }

    public function test_wrong_source_binding_fails_closed(): void
    {
        $node = $this->document(['source_docx_sha256' => str_repeat('c', 64)]);
        $this->member();
        $this->getJson($this->url($node))->assertNotFound();
    }

    public function test_changed_artifact_bytes_fail_closed_without_a_public_storage_url(): void
    {
        $node = $this->document();
        $hash = $node->currentRevision->metadata['reading_view']['sha256'];
        file_put_contents($this->privateRoot.'/reading/'.$hash.'.json', 'modified source text');
        $this->member();
        $this->getJson($this->url($node))->assertNotFound();
        $this->getJson('https://files.mbfdhub.com/reading/'.$hash.'.json')->assertNotFound();
    }

    public function test_native_table_cell_text_headers_and_merged_geometry_are_preserved(): void
    {
        $table = ['type' => 'table', 'text' => '', 'pdf_page' => 1, 'columns' => 3, 'rows' => [
            [['text' => 'Owner', 'col_span' => 1, 'row_span' => 2, 'header' => true, 'pdf_page' => 1],
                ['text' => 'Decision criteria', 'col_span' => 2, 'row_span' => 1, 'header' => true, 'pdf_page' => 1]],
            [['text' => "Exact condition\nExact response", 'col_span' => 2, 'row_span' => 1, 'header' => false, 'pdf_page' => 2]],
        ]];
        $node = $this->document(['entries' => [['blocks' => [$table]]]]);
        $this->member();
        $this->getJson($this->url($node))->assertOk()->assertJsonPath('entries.0.blocks.0.rows', $table['rows']);
    }

    public function test_overlapping_table_geometry_fails_closed(): void
    {
        $node = $this->document(['entries' => [['blocks' => [['type' => 'table', 'columns' => 2, 'rows' => [
            [['text' => 'First', 'col_span' => 2, 'row_span' => 2, 'header' => false, 'pdf_page' => 1]],
            [['text' => 'Overlaps', 'col_span' => 1, 'row_span' => 1, 'header' => false, 'pdf_page' => 1]],
        ]]]]]]);
        $this->member();
        $this->getJson($this->url($node))->assertNotFound();
    }
}
