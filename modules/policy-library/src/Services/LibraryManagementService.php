<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Models\Edition;
use Mbfd\PolicyLibrary\Models\ImportBatch;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Support\Audit;

final class LibraryManagementService
{
    public function createManual(array $data, ?int $userId): Manual
    {
        $data = validator($data, ['name' => 'required|string|max:255', 'type' => 'required|in:sog,medical,other', 'description' => 'nullable|string|max:4000'])->validate();

        return DB::transaction(function () use ($data, $userId): Manual {
            $manual = Manual::query()->create($data + ['slug' => $this->slug($data['name'], null), 'sort_order' => (int) Manual::query()->max('sort_order') + 1]);
            $manual->editions()->create(['label' => 'Working edition', 'created_by' => $userId]);
            Audit::record('manual.created', $userId, ['manual_id' => $manual->id]);

            return $manual;
        });
    }

    public function updateManual(Manual $manual, array $data, ?int $userId): void
    {
        $data = validator($data, ['name' => 'required|string|max:255', 'type' => 'required|in:sog,medical,other', 'description' => 'nullable|string|max:4000', 'is_active' => 'required|boolean'])->validate();
        DB::transaction(function () use ($manual, $data, $userId): void {
            $manual->update($data);
            Audit::record('manual.updated', $userId, ['manual_id' => $manual->id], $data);
        });
    }

    public function createNode(Edition $edition, array $data, ?int $userId): ManualNode
    {
        $this->editable($edition);
        $data = validator($data, ['title' => 'required|string|max:255', 'type' => 'required|in:section,document', 'parent_id' => 'nullable|integer'])->validate();

        return DB::transaction(function () use ($edition, $data, $userId): ManualNode {
            Edition::query()->whereKey($edition->id)->lockForUpdate()->firstOrFail();
            $node = $edition->nodes()->create(['manual_id' => $edition->manual_id, 'title' => $data['title'], 'slug' => $this->slug($data['title'], $edition->id), 'type' => $data['type']]);
            $data['sort_order'] = (int) $edition->nodes()->where('parent_id', $data['parent_id'] ?? null)->max('sort_order') + 1;
            app(TreeService::class)->updateNode($node, $data);
            Audit::record('node.created', $userId, ['manual_id' => $node->manual_id, 'node_id' => $node->id]);

            return $node;
        });
    }

    public function updateNode(ManualNode $node, array $data, ?int $userId): void
    {
        $this->editable($node->edition);
        $data = validator($data, ['title' => 'required|string|max:255', 'short_title' => 'nullable|string|max:255', 'parent_id' => 'nullable|integer', 'is_active' => 'required|boolean'])->validate();
        DB::transaction(function () use ($node, $data, $userId): void {
            $before = $node->only(['title', 'short_title', 'parent_id', 'is_active']);
            if (($data['parent_id'] ?? null) !== $node->parent_id) {
                $data['sort_order'] = (int) $node->edition->nodes()->where('parent_id', $data['parent_id'] ?? null)->max('sort_order') + 1;
            }
            app(TreeService::class)->updateNode($node, $data);
            Audit::record('node.updated', $userId, ['manual_id' => $node->manual_id, 'node_id' => $node->id], ['before' => $before, 'after' => $data]);
        });
    }

    public function reorderNode(ManualNode $node, ManualNode $before, ?int $userId): void
    {
        $this->editable($node->edition);
        if ($node->edition_id !== $before->edition_id || $node->parent_id !== $before->parent_id) {
            throw ValidationException::withMessages(['tree' => 'Drag between entries in the same section. Use Parent section to move into another section.']);
        }
        $this->reorder($node->edition->nodes()->where('parent_id', $node->parent_id)->orderBy('sort_order')->orderBy('id')->pluck('id')->all(), $node->id, $before->id, 'policy_nodes');
        Audit::record('node.reordered', $userId, ['manual_id' => $node->manual_id, 'node_id' => $node->id]);
    }

    public function reorderManual(Manual $manual, Manual $before, ?int $userId): void
    {
        $this->reorder(Manual::query()->orderBy('sort_order')->orderBy('id')->pluck('id')->all(), $manual->id, $before->id, 'policy_manuals');
        Audit::record('manual.reordered', $userId, ['manual_id' => $manual->id]);
    }

    private function reorder(array $ids, int $moving, int $before, string $table): void
    {
        if ($moving === $before) {
            return;
        }
        $ids = array_values(array_diff($ids, [$moving]));
        array_splice($ids, (int) array_search($before, $ids, true), 0, [$moving]);
        DB::transaction(function () use ($ids, $table): void {
            DB::table($table)->whereIn('id', $ids)->lockForUpdate()->get();
            foreach ($ids as $order => $id) {
                DB::table($table)->where('id', $id)->update(['sort_order' => $order]);
            }
        });
    }

    public function archiveManual(Manual $manual, ?int $userId): void
    {
        $manual->update(['is_active' => false]);
        Audit::record('manual.archived', $userId, ['manual_id' => $manual->id]);
    }

    public function archiveNode(ManualNode $node, ?int $userId): void
    {
        $this->editable($node->edition);
        $node->update(['is_active' => false]);
        Audit::record('node.archived', $userId, ['manual_id' => $node->manual_id, 'node_id' => $node->id]);
    }

    public function canDeleteManual(Manual $manual): bool
    {
        return $manual->active_edition_id === null && ! $manual->editions()->where('state', '!=', 'draft')->exists()
            && ! $manual->nodes()->whereHas('revisions')->exists()
            && ! ImportBatch::query()->where('manual_id', $manual->id)->exists()
            && ! ImportBatch::query()->whereIn('section_id', $manual->nodes()->select('id'))->exists();
    }

    public function deleteManual(Manual $manual, ?int $userId): void
    {
        DB::transaction(function () use ($manual, $userId): void {
            Manual::query()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            if (! $this->canDeleteManual($manual)) {
                throw ValidationException::withMessages(['remove' => 'This manual has history. Archive it to remove it from the library.']);
            }
            foreach ($manual->nodes()->whereNull('parent_id')->get() as $node) {
                $this->deleteUnusedTree($node);
            }
            $manual->editions()->delete();
            Audit::record('manual.deleted', $userId, ['manual_id' => $manual->id], ['name' => $manual->name, 'slug' => $manual->slug]);
            $manual->delete();
        });
    }

    public function canDeleteNode(ManualNode $node): bool
    {
        if ($node->edition->state !== 'draft' || $node->edition_id === $node->manual->active_edition_id || $node->revisions()->exists()
            || ImportBatch::query()->where('section_id', $node->id)->exists()) {
            return false;
        }
        foreach ($node->children as $child) {
            if (! $this->canDeleteNode($child)) {
                return false;
            }
        }

        return true;
    }

    public function deleteNode(ManualNode $node, ?int $userId): void
    {
        DB::transaction(function () use ($node, $userId): void {
            Edition::query()->whereKey($node->edition_id)->lockForUpdate()->firstOrFail();
            if (! $this->canDeleteNode($node)) {
                throw ValidationException::withMessages(['remove' => 'This entry has history. Archive it to remove it from the library.']);
            }
            Audit::record('node.deleted', $userId, ['manual_id' => $node->manual_id, 'node_id' => $node->id], ['title' => $node->title]);
            $this->deleteUnusedTree($node);
        });
    }

    private function deleteUnusedTree(ManualNode $node): void
    {
        foreach ($node->children as $child) {
            $this->deleteUnusedTree($child);
        }
        $node->delete();
    }

    private function editable(Edition $edition): void
    {
        if ($edition->state === 'archived') {
            throw ValidationException::withMessages(['edition' => 'Historical editions are preserved. Select the current edition or restore this edition first.']);
        }
    }

    private function slug(string $title, ?int $editionId): string
    {
        $base = Str::limit(Str::slug($title) ?: 'entry', $editionId ? 230 : 80, '');
        $slug = $base;
        $suffix = 2;
        while ($editionId ? ManualNode::query()->where('edition_id', $editionId)->where('slug', $slug)->exists() : Manual::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
