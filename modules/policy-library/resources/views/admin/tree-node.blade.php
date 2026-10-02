@php($children = $tree->get($item->id, collect()))
<li role="treeitem" @if ($item->type === 'section') aria-expanded="{{ in_array($item->id, $expanded, true) ? 'true' : 'false' }}" @endif aria-selected="{{ $nodeId === $item->id ? 'true' : 'false' }}" wire:key="tree-node-{{ $item->id }}">
    <div class="pl-tree-row {{ $nodeId === $item->id ? 'is-selected' : '' }}" x-on:dragover.prevent x-on:drop.prevent.stop="if (draggingNode !== null) { $wire.reorderNode(draggingNode, {{ $item->id }}); draggingNode = null; }">
        <button type="button" class="pl-drag" draggable="true" aria-label="Drag {{ $item->title }} to reorder" x-on:dragstart.stop="draggingNode = {{ $item->id }}; $event.dataTransfer.setData('text/plain', '{{ $item->id }}')" x-on:dragend="draggingNode = null">⠿</button>
        @if ($item->type === 'section')
            <button type="button" class="pl-expand" wire:click="toggleSection({{ $item->id }})" aria-label="{{ in_array($item->id, $expanded, true) ? 'Collapse' : 'Expand' }} {{ $item->title }}">{{ in_array($item->id, $expanded, true) ? '▾' : '▸' }}</button>
        @else<span class="pl-expand" aria-hidden="true"></span>@endif
        <button type="button" class="pl-tree-select" wire:click="selectNode({{ $item->id }})">
            <x-filament::icon :icon="$item->type === 'section' ? 'heroicon-o-folder' : 'heroicon-o-document-text'" class="pl-tree-icon" />
            <span class="pl-tree-title">{{ $item->short_title ?: $item->title }}@unless ($item->is_active)<span class="pl-hidden">Hidden</span>@endunless</span>
        </button>
    </div>
    @if ($children->isNotEmpty() && in_array($item->id, $expanded, true))
        <ul class="pl-tree-list" role="group">@foreach ($children as $child)@include('policy-library::admin.tree-node', ['item' => $child])@endforeach</ul>
    @endif
</li>
