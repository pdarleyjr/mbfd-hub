<x-filament-panels::page>
    <div x-data="{ photoOpen: false }" class="trt-page">

        {{-- Session Selector --}}
        <div class="trt-session-bar">
            <label for="trt-visibility" class="trt-label">Visibility:</label>
            <select id="trt-visibility" wire:model.live="archiveState" class="trt-select">
                <option value="active">Active</option>
                <option value="archived">Archived</option>
                <option value="all">All</option>
            </select>
            <label for="session-select" class="trt-label">Session:</label>
            <select
                id="session-select"
                wire:model.live="selectedSessionId"
                class="trt-select trt-select--session"
            >
                @if($sessions->isEmpty())
                    <option value="">No sessions yet</option>
                @else
                    @foreach($sessions as $session)
                        <option value="{{ $session['id'] }}">{{ $session['label'] }}</option>
                    @endforeach
                @endif
            </select>
        </div>

        @if($archiveHistory !== [])
            <details class="trt-archive">
                <summary class="trt-archive__summary">Archive history</summary>
                <ul>
                    @foreach($archiveHistory as $event)
                        <li class="trt-archive__item">
                            {{ $event['event_type'] === 'record_archived' ? 'Archived' : 'Restored' }}
                            · {{ $event['actor_name'] }} · {{ \Illuminate\Support\Carbon::parse($event['created_at'])->format('M j, Y g:i A') }}
                            <p>{{ $event['metadata']['archive_reason'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        {{-- Stats Bar --}}
        <div class="trt-stats">
            <div class="trt-stat">
                <div class="trt-stat__value">{{ $stats['total'] }}</div>
                <div class="trt-stat__label">Total Items</div>
            </div>
            <div class="trt-stat trt-stat--success">
                <div class="trt-stat__value">{{ $stats['present'] }}</div>
                <div class="trt-stat__label">Present</div>
            </div>
            <div class="trt-stat trt-stat--danger">
                <div class="trt-stat__value">{{ $stats['missing'] }}</div>
                <div class="trt-stat__label">Missing</div>
            </div>
            <div class="trt-stat trt-stat--info">
                <div class="trt-stat__value">{{ $stats['images'] }}</div>
                <div class="trt-stat__label">Photos</div>
            </div>
            @if($stats['missing_images'] > 0)
                <div class="trt-stat trt-stat--warning">
                    <div class="trt-stat__value">{{ $stats['missing_images'] }}</div>
                    <div class="trt-stat__label">Missing Photo Files</div>
                </div>
            @endif
        </div>

        {{-- Inventory Table --}}
        @if(count($aggregatedItems) > 0)
            <div class="trt-table-wrap">
                <table class="trt-table">
                    <thead>
                        <tr>
                            <th class="trt-th trt-th--item">Item</th>
                            <th class="trt-th trt-th--exp">Exp.</th>
                            <th class="trt-th trt-th--present">Present</th>
                            <th class="trt-th trt-th--qty">Qty</th>
                            <th class="trt-th trt-th--cond">Condition</th>
                            <th class="trt-th trt-th--action">Action</th>
                            <th class="trt-th trt-th--images">Images</th>
                            <th class="trt-th trt-th--updated">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $currentCategory = ''; @endphp
                        @foreach($aggregatedItems as $item)
                            {{-- Category header row --}}
                            @if($item['category'] !== $currentCategory)
                                @php $currentCategory = $item['category']; @endphp
                                <tr>
                                    <td colspan="8" class="trt-category">
                                        {{ $currentCategory }}
                                    </td>
                                </tr>
                            @endif

                            <tr
                                wire:click="showItemDetail({{ $item['catalog_item_id'] }})"
                                class="trt-row"
                            >
                                {{-- Item Name --}}
                                <td class="trt-cell trt-cell--item">
                                    <button type="button" class="trt-inventory-control trt-item-link" wire:click.stop="showItemDetail({{ $item['catalog_item_id'] }})">
                                        {{ $item['item_name'] }}
                                    </button>
                                </td>

                                {{-- Expected Qty --}}
                                <td class="trt-cell trt-cell--exp">
                                    {{ $item['expected_qty'] }}
                                </td>

                                {{-- Present Status --}}
                                <td class="trt-cell">
                                    @if($item['present'] === true)
                                        <span class="trt-tag trt-tag--success">Yes</span>
                                    @elseif($item['present'] === false)
                                        <span class="trt-tag trt-tag--danger">No</span>
                                    @else
                                        <span class="trt-na">N/A</span>
                                    @endif
                                </td>

                                {{-- Actual Qty --}}
                                <td class="trt-cell trt-cell--qty">
                                    @if($item['actual_qty'] !== null)
                                        {{ $item['actual_qty'] }}
                                    @else
                                        <span class="trt-na trt-na--qty">N/A</span>
                                    @endif
                                </td>

                                {{-- Condition --}}
                                <td class="trt-cell">
                                    @if($item['condition'] === 'excellent')
                                        <span class="trt-tag trt-tag--success">Excellent</span>
                                    @elseif($item['condition'] === 'good')
                                        <span class="trt-tag trt-tag--warning">Good</span>
                                    @elseif($item['condition'] === 'poor')
                                        <span class="trt-tag trt-tag--danger">Poor</span>
                                    @else
                                        <span class="trt-na">N/A</span>
                                    @endif
                                </td>

                                {{-- Action --}}
                                <td class="trt-cell">
                                    @if($item['action'] === 'keep')
                                        <span class="trt-tag trt-tag--success">Keep</span>
                                    @elseif($item['action'] === 'replace')
                                        <span class="trt-tag trt-tag--danger">Replace</span>
                                    @else
                                        <span class="trt-na">N/A</span>
                                    @endif
                                </td>

                                {{-- Images (INLINE thumbnails) --}}
                                <td class="trt-cell trt-cell--images" onclick="event.stopPropagation()">
                                    @if(count($item['images']) > 0)
                                        <div class="trt-thumbs">
                                            @foreach($item['images'] as $imagePath)
                                                <button type="button" class="trt-inventory-control" aria-label="Open photo of {{ $item['item_name'] }}" x-on:click.stop="$dispatch('show-full-image', '{{ asset('storage/' . $imagePath) }}')">
                                                <img
                                                    src="{{ asset('storage/' . $imagePath) }}"
                                                    alt="Photo"
                                                    class="trt-thumb"
                                                    loading="lazy"
                                                />
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif($item['missing_images'] > 0)
                                        <span class="trt-missing-file">Missing photo file</span>
                                    @else
                                        <span class="trt-na">—</span>
                                    @endif
                                </td>

                                {{-- Last Updated --}}
                                <td class="trt-cell trt-cell--updated">
                                    {{ $item['last_updated'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="trt-empty">
                <svg class="trt-empty__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                <p>No inventory data for the selected session.</p>
            </div>
        @endif

        {{-- Full Image Modal (Alpine.js — no server round-trip) --}}
        <div
            x-data="{ src: '' }"
            x-on:show-full-image.window="src = $event.detail; photoOpen = true"
            x-show="photoOpen"
            x-trap.inert.noscroll="photoOpen"
            x-on:keydown.escape.window="if (photoOpen) { $event.preventDefault(); photoOpen = false }"
            x-on:click="photoOpen = false"
            role="dialog"
            aria-modal="true"
            aria-label="Inventory photo"
            class="trt-photo-dialog"
            x-cloak
        >
            <img
                x-bind:src="src"
                alt="Full size photo"
                class="trt-photo-dialog__img"
            />
            <button type="button" autofocus class="trt-inventory-control trt-photo-close" x-on:click.stop="photoOpen = false">Close photo</button>
        </div>

        {{-- Item Detail Modal --}}
        @if($detailItemId && count($detailEntries) > 0)
            <div
                x-data="{ returnFocus: document.activeElement }"
                x-trap.inert.noscroll.noreturn="!photoOpen"
                x-on:keydown.escape.window="if (!photoOpen && !$event.defaultPrevented) $wire.closeItemDetail().then(() => returnFocus?.focus())"
                role="dialog"
                aria-modal="true"
                aria-labelledby="trt-item-detail-heading"
                class="trt-detail"
            >
                <div class="trt-detail__panel">
                    <div class="trt-detail__head">
                        <h3 id="trt-item-detail-heading" class="trt-detail__title">{{ $detailItemName }}</h3>
                        <button
                            type="button"
                            x-ref="closeDetail"
                            class="trt-inventory-control trt-detail__close"
                            aria-label="Close item details"
                            x-on:click="$wire.closeItemDetail().then(() => returnFocus?.focus())"
                        >&times;</button>
                    </div>
                    <p class="trt-detail__hint">All submissions for this item (newest first)</p>

                    <div class="trt-detail__list">
                        @foreach($detailEntries as $entry)
                            <div class="trt-entry">
                                <div class="trt-entry__head">
                                    <span class="trt-entry__user">{{ $entry['user'] }}</span>
                                    <span class="trt-entry__date">{{ $entry['created_at'] }}</span>
                                </div>
                                <div class="trt-entry__facts">
                                    @if($entry['present'] !== null)
                                        <span>Present: <strong>{{ $entry['present'] ? 'Yes' : 'No' }}</strong></span>
                                    @endif
                                    @if($entry['actual_quantity'] !== null)
                                        <span>Qty: <strong>{{ $entry['actual_quantity'] }}</strong></span>
                                    @endif
                                    @if($entry['condition'])
                                        <span>Condition: <strong class="trt-cap">{{ $entry['condition'] }}</strong></span>
                                    @endif
                                    @if($entry['action'])
                                        <span>Action: <strong class="trt-cap">{{ $entry['action'] }}</strong></span>
                                    @endif
                                </div>
                                @if($entry['image_path'])
                                    <div class="trt-entry__photo">
                                        <button type="button" class="trt-inventory-control" aria-label="Open entry photo" x-on:click.stop="$dispatch('show-full-image', '{{ asset('storage/' . $entry['image_path']) }}')">
                                        <img
                                            src="{{ asset('storage/' . $entry['image_path']) }}"
                                            alt="Entry photo"
                                            class="trt-thumb trt-thumb--lg"
                                            loading="lazy"
                                        />
                                        </button>
                                    </div>
                                @elseif($entry['image_missing'])
                                    <div class="trt-entry__photo trt-entry__photo--missing">Missing photo file</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

    </div>
</x-filament-panels::page>
