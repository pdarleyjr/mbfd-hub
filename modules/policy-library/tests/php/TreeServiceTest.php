<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Services\TreeService;

final class TreeServiceTest extends TestCase
{
    public function test_lightweight_document_ids_preserve_order_hidden_ancestors_and_revision_state(): void
    {
        $manual = Manual::query()->create(['name' => 'SOGs', 'slug' => 'sogs', 'type' => 'sog']);
        $edition = $manual->editions()->create(['label' => 'Current', 'state' => 'published']);
        $manual->update(['active_edition_id' => $edition->id]);
        $hidden = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => 'Hidden', 'slug' => 'hidden', 'type' => 'section', 'is_active' => false]);
        $expected = [];
        foreach ([['second', 20, null, 'published'], ['first', 10, null, 'published'], ['hidden-child', 1, $hidden->id, 'published'], ['draft', 2, null, 'draft']] as [$slug, $order, $parent, $state]) {
            $node = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => $slug, 'slug' => $slug, 'parent_id' => $parent, 'sort_order' => $order]);
            $revision = $node->revisions()->create(['uuid' => (string) Str::uuid(), 'source_filename' => 'document.pdf', 'storage_path' => 'revisions/'.str_repeat('a', 64).'.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 1, 'state' => $state]);
            $node->update(['current_revision_id' => $revision->id]);
            $revision->pages()->create(['page' => 1, 'title' => $slug, 'text' => str_repeat('private page text ', 1000)]);
            if ($parent === null && $state === 'published') {
                $expected[$order] = $node->id;
            }
        }
        ksort($expected);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $service = $this->app->make(TreeService::class);
        $this->assertSame(array_values($expected), $service->documentIds($manual));
        $this->assertCount(2, $queries);
        $this->assertStringNotContainsString('policy_pages', implode(' ', $queries));
        $this->assertStringNotContainsString('metadata', implode(' ', $queries));
        $this->assertSame($service->tree($manual)['documents'], $service->documentIds($manual));
    }

    public function test_tree_metadata_projection_preserves_page_labels_without_loading_page_text(): void
    {
        $manual = Manual::query()->create(['name' => 'SOGs', 'slug' => 'sogs', 'type' => 'sog']);
        $edition = $manual->editions()->create(['label' => 'Current', 'state' => 'published']);
        $manual->update(['active_edition_id' => $edition->id]);
        $node = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => 'Policy', 'slug' => 'policy']);
        $revision = $node->revisions()->create(['uuid' => (string) Str::uuid(), 'source_filename' => 'document.pdf', 'storage_path' => 'revisions/'.str_repeat('a', 64).'.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 1, 'state' => 'published']);
        $node->update(['current_revision_id' => $revision->id]);
        $revision->pages()->create(['page' => 1, 'physical_page' => 17, 'printed_label' => 'A-17', 'title' => 'Policy', 'text' => 'Private searchable content']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $tree = $this->app->make(TreeService::class)->tree($manual);
        $this->assertSame([['page' => 1, 'physical_page' => 17, 'printed_label' => 'A-17', 'title' => 'Policy']], $tree['nodes'][0]['revision']['pages']);
        $pageQuery = collect($queries)->first(fn ($sql) => str_contains($sql, 'policy_pages'));
        $this->assertNotNull($pageQuery);
        $this->assertStringNotContainsString('select *', $pageQuery);
        $this->assertStringNotContainsString('"text"', $pageQuery);
    }
}
