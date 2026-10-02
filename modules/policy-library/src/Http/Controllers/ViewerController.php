<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\SearchService;
use Mbfd\PolicyLibrary\Services\TreeService;
use Mbfd\PolicyLibrary\Support\LibraryAccess;

final class ViewerController
{
    public function index(): View
    {
        return view('policy-library::viewer');
    }

    public function manuals(Request $request): JsonResponse
    {
        $manage = LibraryAccess::canManage($request->user('web'));

        return response()->json([
            'manuals' => Manual::query()->where('is_active', true)->whereNotNull('active_edition_id')->orderBy('sort_order')->orderBy('id')->get(['id', 'slug', 'name', 'type', 'description', 'updated_at']),
            'can_manage' => $manage, 'manage_url' => $manage ? '/manage' : null,
        ]);
    }

    public function tree(string $slug, TreeService $trees): JsonResponse
    {
        $manual = Manual::query()->where('slug', $slug)->where('is_active', true)->whereNotNull('active_edition_id')->firstOrFail();

        return response()->json($trees->tree($manual));
    }

    public function search(Request $request, SearchService $search, TreeService $trees): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:160'], 'manual' => ['nullable', 'string', 'max:100']]);

        return response()->json($search->search(trim($data['q']), $data['manual'] ?? null, $trees));
    }

    public function document(ManualNode $node, TreeService $trees): JsonResponse
    {
        $manual = $node->manual;
        abort_unless($manual->is_active && $node->is_active && $node->edition_id === $manual->active_edition_id && $node->currentRevision?->state === 'published', 404);
        $tree = $trees->tree($manual);
        $position = array_search($node->id, $tree['documents'], true);
        abort_if($position === false, 404); // A hidden ancestor also hides its descendants.

        return response()->json([
            'node' => $node->only(['id', 'manual_id', 'title', 'slug']), 'revision' => $trees->revisionData($node->currentRevision),
            'previous_node_id' => $position > 0 ? $tree['documents'][$position - 1] : null,
            'next_node_id' => $tree['documents'][$position + 1] ?? null,
        ]);
    }
}
