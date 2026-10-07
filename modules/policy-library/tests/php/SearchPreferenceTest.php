<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Illuminate\Support\Str;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\SearchService;
use Mbfd\PolicyLibrary\Services\TreeService;

final class SearchPreferenceTest extends TestCase
{
    private function document(string $asset, array $metadata = []): ManualNode
    {
        $manual = Manual::query()->firstOrCreate(['slug' => 'sogs'], ['name' => 'SOGs', 'type' => 'sog']);
        $edition = $manual->activeEdition ?? $manual->editions()->create(['label' => 'Current', 'state' => 'published']);
        $manual->update(['active_edition_id' => $edition->id]);
        $node = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => $asset, 'slug' => strtolower(str_replace('.', '-', $asset)), 'metadata' => ['asset_id' => $asset]]);
        $revision = $node->revisions()->create(['uuid' => (string) Str::uuid(), 'source_filename' => 'policy.pdf', 'storage_path' => 'test.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 1, 'state' => 'published', 'metadata' => array_replace([
            'asset_id' => $asset, 'primary_entries' => [['id' => '100.01', 'slug' => '100-01', 'title' => 'Source policy', 'physical_page' => 1, 'semantic_pages' => [1], 'semantic_text' => 'searchterm']],
        ], $metadata)]);
        $revision->pages()->create(['page' => 1, 'text' => '100.01 searchterm']);
        $node->update(['current_revision_id' => $revision->id]);

        return $node->fresh();
    }

    private function search(string $query): array
    {
        return app(SearchService::class)->search($query, 'sogs', app(TreeService::class))['results'];
    }

    public function test_complete_declared_leaf_coverage_suppresses_duplicate_aggregate_page_hits(): void
    {
        $this->document('SECTION-100', ['search_role' => 'aggregate', 'leaf_asset_ids' => ['100.01']]);
        $leaf = $this->document('100.01');
        $results = $this->search('searchterm');
        $this->assertCount(1, $results);
        $this->assertSame($leaf->id, $results[0]['node_id']);
    }

    public function test_complete_coverage_preserves_legacy_section_route(): void
    {
        $this->document('SECTION-100', ['search_role' => 'aggregate', 'leaf_asset_ids' => ['100.01']]);
        $this->document('100.01');
        $user = $this->user();
        $this->actingAs($user)->withSession(['policy-library.access' => $this->accessGrant($user)])
            ->get('https://files.mbfdhub.com/current-sog/SECTION-100?page=1')->assertRedirect('/?manual=sogs&node=section-100&page=1');
    }

    public function test_aggregate_cannot_reintroduce_missing_hidden_draft_old_or_other_manual_leaf_content(): void
    {
        $user = $this->user();
        foreach (['missing', 'hidden', 'draft', 'ancestor', 'old_edition', 'other_manual', 'missing_metadata'] as $case) {
            $identity = str_replace('_', '-', $case);
            $aggregate = $this->document('SECTION-'.$identity, ['search_role' => 'aggregate', 'leaf_asset_ids' => ['100.'.$identity]]);
            if ($case !== 'missing') {
                $leaf = $this->document('100.'.$identity);
                if ($case === 'hidden') {
                    $leaf->update(['is_active' => false]);
                }
                if ($case === 'draft') {
                    $leaf->currentRevision->update(['state' => 'draft']);
                }
                if ($case === 'missing_metadata') {
                    $leaf->update(['metadata' => []]);
                }
                if ($case === 'ancestor') {
                    $parent = $leaf->edition->nodes()->create(['manual_id' => $leaf->manual_id, 'title' => 'Hidden', 'slug' => 'hidden-'.$case, 'type' => 'section', 'is_active' => false]);
                    $leaf->update(['parent_id' => $parent->id]);
                }
                if ($case === 'old_edition') {
                    $old = $leaf->manual->editions()->create(['label' => 'Old', 'state' => 'archived']);
                    $leaf->update(['edition_id' => $old->id]);
                }
                if ($case === 'other_manual') {
                    $other = Manual::query()->create(['slug' => 'other', 'name' => 'Other', 'type' => 'sog']);
                    $edition = $other->editions()->create(['label' => 'Current', 'state' => 'published']);
                    $other->update(['active_edition_id' => $edition->id]);
                    $leaf->update(['manual_id' => $other->id, 'edition_id' => $edition->id]);
                }
            }
            $this->assertNotContains($aggregate->id, app(TreeService::class)->documentIds($aggregate->manual), $case);
            $this->assertNotContains($aggregate->id, array_column($this->search('searchterm'), 'node_id'), $case);
            $this->actingAs($user)->withSession(['policy-library.access' => $this->accessGrant($user)])
                ->get('https://files.mbfdhub.com/current-sog/SECTION-'.$identity.'?page=1')->assertNotFound();
        }
    }

    public function test_declared_aggregate_without_coverage_metadata_fails_closed(): void
    {
        $aggregate = $this->document('SECTION-100', ['search_role' => 'aggregate']);
        $this->document('100.01');
        $this->assertNotContains($aggregate->id, app(TreeService::class)->documentIds($aggregate->manual));
        $this->assertNotContains($aggregate->id, array_column($this->search('searchterm'), 'node_id'));
    }

    public function test_identity_and_existing_subject_alias_prefer_leaf_regardless_of_insert_order(): void
    {
        $leaf = $this->document('100.01');
        $this->document('SECTION-100', ['subject_aliases' => [['source_record_id' => 'LEGACY-800.01', 'legacy_id' => '800.01', 'source_title' => 'Retained subject', 'current_ids' => ['100.01']]]]);
        $this->assertSame($leaf->id, $this->search('100.01')[0]['node_id']);
        $alias = $this->search('800.01');
        $this->assertCount(1, $alias);
        $this->assertSame($leaf->id, $alias[0]['node_id']);
        $this->assertSame('LEGACY-800.01', $alias[0]['subject_alias']['source_record_id']);
    }
}
