<x-filament-panels::page>
    <style>
        .trt-inventory-control:focus-visible { outline: 2px solid rgb(var(--hub-focus)); outline-offset: 2px; }
        .trt-photo-close:focus-visible { outline-color: rgb(var(--hub-surface)); }
        .trt-photo-dialog { display:flex; }
    </style>
    <div x-data="{ photoOpen: false }" style="font-family: var(--hub-font-sans);">

        {{-- Session Selector --}}
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <label for="trt-visibility" style="font-size:0.875rem;font-weight:600;color:rgb(var(--hub-ink-secondary));">Visibility:</label>
            <select id="trt-visibility" wire:model.live="archiveState" style="min-height:44px;border:1px solid rgb(var(--hub-border-strong));border-radius:0.5rem;background:rgb(var(--hub-surface));color:rgb(var(--hub-ink));">
                <option value="active">Active</option>
                <option value="archived">Archived</option>
                <option value="all">All</option>
            </select>
            <label for="session-select" style="font-size:0.875rem;font-weight:600;color:rgb(var(--hub-ink-secondary));">Session:</label>
            <select
                id="session-select"
                wire:model.live="selectedSessionId"
                style="padding:0.5rem 1rem;border:1px solid rgb(var(--hub-border-strong));border-radius:0.5rem;font-size:0.875rem;min-width:min(240px,100%);max-width:100%;min-height:44px;background:rgb(var(--hub-surface));color:rgb(var(--hub-ink));"
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

        {{-- Stats Bar --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0.75rem;margin-bottom:1.5rem;">
            <div style="background:rgb(var(--hub-surface-muted));border:1px solid rgb(var(--hub-border));border-radius:0.5rem;padding:0.75rem 1rem;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:rgb(var(--hub-ink));font-variant-numeric:tabular-nums;">{{ $stats['total'] }}</div>
                <div style="font-size:0.75rem;color:rgb(var(--hub-ink-secondary));">Total Items</div>
            </div>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:0.5rem;padding:0.75rem 1rem;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:#166534;font-variant-numeric:tabular-nums;">{{ $stats['present'] }}</div>
                <div style="font-size:0.75rem;color:#166534;">Present</div>
            </div>
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:0.5rem;padding:0.75rem 1rem;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:#991b1b;font-variant-numeric:tabular-nums;">{{ $stats['missing'] }}</div>
                <div style="font-size:0.75rem;color:#991b1b;">Missing</div>
            </div>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:0.5rem;padding:0.75rem 1rem;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:#1e40af;font-variant-numeric:tabular-nums;">{{ $stats['images'] }}</div>
                <div style="font-size:0.75rem;color:#1e40af;">Photos</div>
            </div>
            @if($stats['missing_images'] > 0)
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:0.5rem;padding:0.75rem 1rem;text-align:center;">
                    <div style="font-size:1.5rem;font-weight:700;color:#92400e;font-variant-numeric:tabular-nums;">{{ $stats['missing_images'] }}</div>
                    <div style="font-size:0.75rem;color:#92400e;">Missing Photo Files</div>
                </div>
            @endif
        </div>

        {{-- Inventory Table --}}
        @if(count($aggregatedItems) > 0)
            <div style="overflow-x:auto;border:1px solid rgb(var(--hub-border));border-radius:0.75rem;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="background:rgb(var(--hub-surface-muted));border-bottom:2px solid rgb(var(--hub-border-strong));">
                            <th style="padding:0.625rem 0.75rem;text-align:left;font-weight:600;color:rgb(var(--hub-ink-secondary));min-width:12rem;">Item</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:rgb(var(--hub-ink-secondary));width:60px;">Exp.</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:rgb(var(--hub-ink-secondary));width:75px;">Present</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:rgb(var(--hub-ink-secondary));width:60px;">Qty</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:rgb(var(--hub-ink-secondary));width:85px;">Condition</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:rgb(var(--hub-ink-secondary));width:75px;">Action</th>
                            <th style="padding:0.625rem 0.5rem;text-align:left;font-weight:600;color:rgb(var(--hub-ink-secondary));min-width:100px;">Images</th>
                            <th style="padding:0.625rem 0.5rem;text-align:right;font-weight:600;color:rgb(var(--hub-ink-secondary));width:120px;">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $currentCategory = ''; @endphp
                        @foreach($aggregatedItems as $item)
                            {{-- Category header row --}}
                            @if($item['category'] !== $currentCategory)
                                @php $currentCategory = $item['category']; @endphp
                                <tr>
                                    <td colspan="8" style="padding:0.5rem 0.75rem;background:rgb(var(--hub-surface-muted));font-weight:700;font-size:0.75rem;color:rgb(var(--hub-ink-secondary));text-transform:uppercase;letter-spacing:0.05em;border-top:1px solid rgb(var(--hub-border));">
                                        {{ $currentCategory }}
                                    </td>
                                </tr>
                            @endif

                            <tr
                                wire:click="showItemDetail({{ $item['catalog_item_id'] }})"
                                style="border-bottom:1px solid rgb(var(--hub-surface-muted));cursor:pointer;transition:background 0.15s;"
                                onmouseover="this.style.background='rgb(var(--hub-surface-muted))'"
                                onmouseout="this.style.background='transparent'"
                            >
                                {{-- Item Name --}}
                                <td style="padding:0.5rem 0.75rem;color:rgb(var(--hub-ink));font-weight:500;">
                                    <button type="button" class="trt-inventory-control" wire:click.stop="showItemDetail({{ $item['catalog_item_id'] }})" style="min-height:44px;text-align:left;color:rgb(var(--hub-action-primary));">
                                        {{ $item['item_name'] }}
                                    </button>
                                </td>

                                {{-- Expected Qty --}}
                                <td style="padding:0.5rem;text-align:center;color:rgb(var(--hub-ink-secondary));font-variant-numeric:tabular-nums;">
                                    {{ $item['expected_qty'] }}
                                </td>

                                {{-- Present Status --}}
                                <td style="padding:0.5rem;text-align:center;">
                                    @if($item['present'] === true)
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#dcfce7;color:#166534;">Yes</span>
                                    @elseif($item['present'] === false)
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#fee2e2;color:#991b1b;">No</span>
                                    @else
                                        <span style="color:rgb(var(--hub-ink-secondary));font-size:0.75rem;">N/A</span>
                                    @endif
                                </td>

                                {{-- Actual Qty --}}
                                <td style="padding:0.5rem;text-align:center;font-variant-numeric:tabular-nums;color:rgb(var(--hub-ink));">
                                    @if($item['actual_qty'] !== null)
                                        {{ $item['actual_qty'] }}
                                    @else
                                        <span style="color:rgb(var(--hub-ink-secondary));">N/A</span>
                                    @endif
                                </td>

                                {{-- Condition --}}
                                <td style="padding:0.5rem;text-align:center;">
                                    @if($item['condition'] === 'excellent')
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#dcfce7;color:#166534;">Excellent</span>
                                    @elseif($item['condition'] === 'good')
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#fef9c3;color:#854d0e;">Good</span>
                                    @elseif($item['condition'] === 'poor')
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#fee2e2;color:#991b1b;">Poor</span>
                                    @else
                                        <span style="color:rgb(var(--hub-ink-secondary));font-size:0.75rem;">N/A</span>
                                    @endif
                                </td>

                                {{-- Action --}}
                                <td style="padding:0.5rem;text-align:center;">
                                    @if($item['action'] === 'keep')
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#dcfce7;color:#166534;">Keep</span>
                                    @elseif($item['action'] === 'replace')
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#fee2e2;color:#991b1b;">Replace</span>
                                    @else
                                        <span style="color:rgb(var(--hub-ink-secondary));font-size:0.75rem;">N/A</span>
                                    @endif
                                </td>

                                {{-- Images (INLINE thumbnails) --}}
                                <td style="padding:0.5rem;" onclick="event.stopPropagation()">
                                    @if(count($item['images']) > 0)
                                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                            @foreach($item['images'] as $imagePath)
                                                <button type="button" class="trt-inventory-control" aria-label="Open photo of {{ $item['item_name'] }}" x-on:click.stop="$dispatch('show-full-image', '{{ asset('storage/' . $imagePath) }}')">
                                                <img
                                                    src="{{ asset('storage/' . $imagePath) }}"
                                                    alt="Photo"
                                                    style="width:48px;height:48px;object-fit:cover;border-radius:0.375rem;cursor:pointer;border:1px solid rgb(var(--hub-border));transition:transform 0.15s;"
                                                    onmouseover="this.style.transform='scale(1.1)'"
                                                    onmouseout="this.style.transform='scale(1)'"
                                                    loading="lazy"
                                                />
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif($item['missing_images'] > 0)
                                        <span style="color:#92400e;font-size:0.75rem;">Missing photo file</span>
                                    @else
                                        <span style="color:rgb(var(--hub-ink-secondary));font-size:0.75rem;">—</span>
                                    @endif
                                </td>

                                {{-- Last Updated --}}
                                <td style="padding:0.5rem;text-align:right;color:rgb(var(--hub-ink-secondary));font-size:0.75rem;white-space:nowrap;">
                                    {{ $item['last_updated'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="text-align:center;padding:3rem;color:rgb(var(--hub-ink-secondary));">
                <svg style="width:3rem;height:3rem;margin:0 auto 1rem;opacity:0.5;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
            style="position:fixed;inset:0;z-index:50;background:rgba(0,0,0,0.85);display:flex;align-items:center;justify-content:center;cursor:pointer;"
        >
            <img
                x-bind:src="src"
                alt="Full size photo"
                style="max-width:90vw;max-height:90vh;border-radius:0.5rem;box-shadow:0 25px 50px rgba(0,0,0,0.5);"
            />
            <button type="button" autofocus class="trt-inventory-control trt-photo-close" x-on:click.stop="photoOpen = false" style="position:absolute;top:1rem;right:1rem;min-height:44px;padding:0.5rem;color:white;font-size:0.875rem;">Close photo</button>
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
                style="position:fixed;inset:0;z-index:40;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;"
            >
                <div style="background:white;border-radius:0.75rem;max-width:600px;width:90vw;max-height:80vh;overflow-y:auto;padding:1.5rem;box-shadow:0 25px 50px rgba(0,0,0,0.25);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                        <h3 id="trt-item-detail-heading" style="min-width:0;overflow-wrap:anywhere;font-size:1.125rem;font-weight:700;color:rgb(var(--hub-ink));">{{ $detailItemName }}</h3>
                        <button
                            type="button"
                            x-ref="closeDetail"
                            class="trt-inventory-control"
                            aria-label="Close item details"
                            x-on:click="$wire.closeItemDetail().then(() => returnFocus?.focus())"
                            style="width:44px;height:44px;flex-shrink:0;border-radius:0.375rem;display:flex;align-items:center;justify-content:center;background:rgb(var(--hub-surface-muted));color:rgb(var(--hub-ink-secondary));border:none;cursor:pointer;font-size:1.125rem;"
                        >&times;</button>
                    </div>
                    <p style="font-size:0.75rem;color:rgb(var(--hub-ink-secondary));margin-bottom:1rem;">All submissions for this item (newest first)</p>

                    <div style="display:flex;flex-direction:column;gap:0.75rem;">
                        @foreach($detailEntries as $entry)
                            <div style="background:rgb(var(--hub-surface-muted));border:1px solid rgb(var(--hub-border));border-radius:0.5rem;padding:0.75rem;">
                                <div style="display:flex;justify-content:space-between;margin-bottom:0.375rem;">
                                    <span style="font-size:0.8125rem;font-weight:600;color:rgb(var(--hub-ink-secondary));">{{ $entry['user'] }}</span>
                                    <span style="font-size:0.75rem;color:rgb(var(--hub-ink-secondary));">{{ $entry['created_at'] }}</span>
                                </div>
                                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;font-size:0.75rem;color:rgb(var(--hub-ink-secondary));">
                                    @if($entry['present'] !== null)
                                        <span>Present: <strong style="color:rgb(var(--hub-ink));">{{ $entry['present'] ? 'Yes' : 'No' }}</strong></span>
                                    @endif
                                    @if($entry['actual_quantity'] !== null)
                                        <span>Qty: <strong style="color:rgb(var(--hub-ink));">{{ $entry['actual_quantity'] }}</strong></span>
                                    @endif
                                    @if($entry['condition'])
                                        <span>Condition: <strong style="color:rgb(var(--hub-ink));text-transform:capitalize;">{{ $entry['condition'] }}</strong></span>
                                    @endif
                                    @if($entry['action'])
                                        <span>Action: <strong style="color:rgb(var(--hub-ink));text-transform:capitalize;">{{ $entry['action'] }}</strong></span>
                                    @endif
                                </div>
                                @if($entry['image_path'])
                                    <div style="margin-top:0.5rem;">
                                        <button type="button" class="trt-inventory-control" aria-label="Open entry photo" x-on:click.stop="$dispatch('show-full-image', '{{ asset('storage/' . $entry['image_path']) }}')">
                                        <img
                                            src="{{ asset('storage/' . $entry['image_path']) }}"
                                            alt="Entry photo"
                                            style="width:64px;height:64px;object-fit:cover;border-radius:0.375rem;cursor:pointer;border:1px solid rgb(var(--hub-border));"
                                            loading="lazy"
                                        />
                                        </button>
                                    </div>
                                @elseif($entry['image_missing'])
                                    <div style="margin-top:0.5rem;color:#92400e;font-size:0.75rem;">Missing photo file</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

    </div>
</x-filament-panels::page>
