<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Support\Facades\DB;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\Manual;

final class SearchService
{
    public function search(string $query, ?string $manualSlug, TreeService $trees): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $query, $matches);
        $terms = array_slice(array_unique($matches[0]), 0, 12);
        $identifier = preg_match('/^(?:[0-9]{3}[.\-][A-Za-z0-9.\-]+|MBFD-SOG-MANUAL-[A-Za-z0-9\-]+)$/i', $query) ? mb_strtolower($query) : null;
        if ($terms === []) {
            return ['results' => [], 'has_more' => false];
        }

        $manuals = Manual::query()->where('is_active', true)->whereNotNull('active_edition_id')
            ->when($manualSlug, fn ($builder) => $builder->where('slug', $manualSlug))->orderBy('sort_order')->orderBy('id')->get();
        $visible = [];
        $catalog = [];
        foreach ($manuals as $manual) {
            $visit = function (array $nodes, array $path = []) use (&$visit, &$visible, &$catalog, $manual): void {
                foreach ($nodes as $node) {
                    if ($node['revision']) {
                        $visible[$node['id']] = ['manual' => $manual->only(['name', 'slug']), 'path' => $path, 'slug' => $node['slug'], 'title' => $node['title']];
                        $catalog[$node['id']] = $node['revision'];
                    }
                    $visit($node['children'], [...$path, $node['title']]);
                }
            };
            $visit($trees->tree($manual)['nodes']);
        }
        if ($visible === []) {
            return ['results' => [], 'has_more' => false];
        }

        $pages = DB::table('policy_pages')->join('policy_revisions', 'policy_revisions.id', '=', 'policy_pages.revision_id')
            ->join('policy_nodes', 'policy_nodes.current_revision_id', '=', 'policy_revisions.id')
            ->join('policy_manuals', 'policy_manuals.id', '=', 'policy_nodes.manual_id')
            ->join('policy_editions', 'policy_editions.id', '=', 'policy_nodes.edition_id')
            ->whereIn('policy_nodes.id', array_keys($visible))->where('policy_revisions.state', 'published')
            ->where('policy_editions.state', 'published')->whereColumn('policy_revisions.node_id', 'policy_nodes.id')
            ->where('policy_nodes.is_active', true)->where('policy_manuals.is_active', true)
            ->whereColumn('policy_nodes.edition_id', 'policy_manuals.active_edition_id');
        if (DB::connection()->getDriverName() === 'pgsql') {
            $pages->whereRaw("to_tsvector('simple', coalesce(policy_pages.text, '')) @@ plainto_tsquery('simple', ?)", [implode(' ', $terms)]);
        } else {
            foreach ($terms as $term) {
                $pages->whereRaw("lower(coalesce(policy_pages.text, '')) like ?", ['%'.mb_strtolower($term).'%']);
            }
        }
        $rows = $pages->orderBy('policy_manuals.sort_order')->orderBy('policy_manuals.id')->orderBy('policy_nodes.sort_order')
            ->orderBy('policy_nodes.id')->orderBy('policy_pages.page')->limit(51)
            ->get(['policy_nodes.id as node_id', 'policy_pages.page', 'policy_pages.printed_label', 'policy_pages.text']);

        $privateMetadata = DocumentRevision::query()->whereIn('uuid', array_column($catalog, 'id'))->pluck('metadata', 'uuid');
        foreach ($catalog as &$revision) {
            $revision['metadata'] = $privateMetadata[$revision['id']] ?? [];
        }
        unset($revision);
        $results = $this->identityResults($visible, $catalog, $terms, $identifier);
        $matchedPages = array_fill_keys(array_map(fn (array $result): string => $result['node_id'].':'.$result['page'], $results), true);
        foreach ($rows as $row) {
            $semanticMatches = [];
            foreach ($catalog[$row->node_id]['metadata']['primary_entries'] ?? [] as $entry) {
                if ($identifier === null && in_array((int) $row->page, $entry['semantic_pages'] ?? [], true) && $this->matches($entry['semantic_text'] ?? '', $terms)) {
                    $semanticMatches[] = [...$visible[$row->node_id], 'node_id' => $row->node_id, 'page' => $row->page,
                        'title' => $entry['title'], 'primary_id' => $entry['id'], 'primary_slug' => $entry['slug'] ?? null,
                        'semantic_page_count' => count($entry['semantic_pages']), 'printed_label' => $row->printed_label,
                        'excerpt' => $this->excerpt($row->text ?? '', $terms)];
                }
            }
            if ($semanticMatches !== []) {
                foreach ($semanticMatches as $match) {
                    $duplicate = false;
                    foreach ($results as $result) {
                        if (($result['primary_id'] ?? null) === $match['primary_id'] && (int) $result['page'] === (int) $match['page']) {
                            $duplicate = true;
                            break;
                        }
                    }
                    if (! $duplicate) {
                        $results[] = $match;
                    }
                }

                continue;
            }
            if (! isset($matchedPages[$row->node_id.':'.$row->page])) {
                $results[] = [...$visible[$row->node_id], 'node_id' => $row->node_id, 'page' => $row->page,
                    'printed_label' => $row->printed_label, 'excerpt' => $this->excerpt($row->text ?? '', $terms)];
            }
        }

        return ['results' => array_slice($results, 0, 50), 'has_more' => count($results) > 50 || $rows->count() > 50];
    }

    private function identityResults(array $visible, array $catalog, array $terms, ?string $identifier): array
    {
        $targets = [];
        foreach ($catalog as $nodeId => $revision) {
            foreach ($revision['metadata']['primary_entries'] ?? [] as $entry) {
                $page = (int) ($entry['physical_page'] ?? 0);
                if (! isset($entry['id'], $entry['title']) || $page < 1 || $page > $revision['page_count']) {
                    continue;
                }
                $targets[$entry['id']] = [...$visible[$nodeId], 'node_id' => $nodeId, 'page' => $page,
                    'title' => $entry['title'], 'primary_id' => $entry['id'], 'primary_slug' => $entry['slug'] ?? null,
                    'semantic_page_count' => count($entry['semantic_pages'] ?? [$page]),
                    'printed_label' => $revision['pages'][$page - 1]['printed_label'] ?? null, 'excerpt' => $entry['title']];
            }
        }
        $results = [];
        foreach ($targets as $id => $target) {
            if ($identifier !== null ? mb_strtolower($id) === $identifier : $this->matches($id.' '.$target['title'], $terms)) {
                $results['primary:'.$id] = $target;
            }
        }
        foreach ($catalog as $revision) {
            foreach ($revision['metadata']['subject_aliases'] ?? [] as $alias) {
                $matchesAlias = $identifier !== null
                    ? in_array($identifier, [mb_strtolower($alias['source_record_id'] ?? ''), mb_strtolower($alias['legacy_id'] ?? '')], true)
                    : $this->matches(($alias['source_record_id'] ?? '').' '.($alias['legacy_id'] ?? '').' '.($alias['source_title'] ?? ''), $terms);
                if (! $matchesAlias) {
                    continue;
                }
                foreach ($alias['current_ids'] ?? [] as $id) {
                    if (isset($targets[$id])) {
                        $results['alias:'.($alias['source_record_id'] ?? '').':'.$id] = $targets[$id] + [
                            'subject_alias' => ['source_record_id' => $alias['source_record_id'], 'legacy_id' => $alias['legacy_id'], 'title' => $alias['source_title'], 'source_pages' => $alias['source_pages'] ?? []],
                        ];
                    }
                }
            }
        }

        return array_values($results);
    }

    private function matches(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (mb_stripos($text, $term) === false) {
                return false;
            }
        }

        return true;
    }

    private function excerpt(string $text, array $terms): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        $position = mb_stripos($text, $terms[0]);
        $start = max(0, ($position === false ? 0 : $position) - 65);
        $excerpt = mb_substr($text, $start, 210);

        return ($start > 0 ? '…' : '').$excerpt.(mb_strlen($text) > $start + 210 ? '…' : '');
    }
}
