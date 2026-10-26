<?php

declare(strict_types=1);

namespace App\Services\ResourceLibrary;

use App\Models\ResourceDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Member-facing library query. Every search word must appear in the title, category, description or the
 * extracted document text. Case-insensitive LIKE with an explicit ESCAPE works the same on PostgreSQL and SQLite.
 */
final class ResourceLibrarySearch
{
    private const MAX_TERMS = 8;

    private const SEARCHABLE_COLUMNS = ['title', 'category', 'description', 'search_text'];

    /** @return Collection<int, ResourceDocument> */
    public function documents(string $search = '', string $category = ''): Collection
    {
        $query = ResourceDocument::query()
            // The extracted text is large; it is filtered on in SQL and never loaded into the page.
            ->select(['id', 'uuid', 'slug', 'title', 'category', 'description', 'original_filename', 'original_mime', 'original_size', 'original_path', 'preview_path', 'page_count', 'sort', 'published_at'])
            ->published()
            ->shared()
            ->orderBy('category')
            ->orderBy('sort')
            ->orderBy('title');

        if (trim($category) !== '') {
            $query->where('category', $category);
        }
        foreach ($this->terms($search) as $term) {
            $this->requireTerm($query, $term);
        }

        return $query->get();
    }

    /** @return list<string> */
    public function categories(): array
    {
        return ResourceDocument::query()->published()->shared()->distinct()->orderBy('category')->pluck('category')->all();
    }

    /** @return list<string> */
    public function terms(string $search): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::MAX_TERMS);
    }

    /** @param  Builder<ResourceDocument>  $query */
    private function requireTerm(Builder $query, string $term): void
    {
        $pattern = '%'.addcslashes($term, '\\%_').'%';
        $query->where(function (Builder $group) use ($pattern): void {
            foreach (self::SEARCHABLE_COLUMNS as $column) {
                $group->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '\\'", [$pattern]);
            }
        });
    }
}
