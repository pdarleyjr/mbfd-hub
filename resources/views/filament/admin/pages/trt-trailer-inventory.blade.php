<x-filament-panels::page>
    <div style="font-family: 'Plus Jakarta Sans', 'Source Sans 3', system-ui, sans-serif;">

        {{-- Session Selector --}}
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <label for="session-select" style="font-size:0.875rem;font-weight:600;color:#44403c;">Session:</label>
            <select
                id="session-select"
                wire:model.live="selectedSessionId"
                style="padding:0.5rem 1rem;border:1px solid #d6d3d1;border-radius:0.5rem;font-size:0.875rem;min-width:240px;background:#fff;color:#292524;"
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
            <div style="background:#fafaf9;border:1px solid #e7e5e4;border-radius:0.5rem;padding:0.75rem 1rem;text-align:center;">
                <div style="font-size:1.5rem;font-weight:700;color:#292524;font-variant-numeric:tabular-nums;">{{ $stats['total'] }}</div>
                <div style="font-size:0.75rem;color:#78716c;">Total Items</div>
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
        </div>

        {{-- Inventory Table --}}
        @if(count($aggregatedItems) > 0)
            <div style="overflow-x:auto;border:1px solid #e7e5e4;border-radius:0.75rem;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="background:#f5f5f4;border-bottom:2px solid #d6d3d1;">
                            <th style="padding:0.625rem 0.75rem;text-align:left;font-weight:600;color:#44403c;">Item</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:#44403c;width:60px;">Exp.</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:#44403c;width:75px;">Present</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:#44403c;width:60px;">Qty</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:#44403c;width:85px;">Condition</th>
                            <th style="padding:0.625rem 0.5rem;text-align:center;font-weight:600;color:#44403c;width:75px;">Action</th>
                            <th style="padding:0.625rem 0.5rem;text-align:left;font-weight:600;color:#44403c;min-width:100px;">Images</th>
                            <th style="padding:0.625rem 0.5rem;text-align:right;font-weight:600;color:#44403c;width:120px;">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $currentCategory = ''; @endphp
                        @foreach($aggregatedItems as $item)
                            {{-- Category header row --}}
                            @if($item['category'] !== $currentCategory)
                                @php $currentCategory = $item['category']; @endphp
                                <tr>
                                    <td colspan="8" style="padding:0.5rem 0.75rem;background:#fafaf9;font-weight:700;font-size:0.75rem;color:#78716c;text-transform:uppercase;letter-spacing:0.05em;border-top:1px solid #e7e5e4;">
                                        {{ $currentCategory }}
                                    </td>
                                </tr>
                            @endif

                            <tr
                                wire:click="showItemDetail({{ $item['catalog_item_id'] }})"
                                style="border-bottom:1px solid #f5f5f4;cursor:pointer;transition:background 0.15s;"
                                onmouseover="this.style.background='#fafaf9'"
                                onmouseout="this.style.background='transparent'"
                            >
                                {{-- Item Name --}}
                                <td style="padding:0.5rem 0.75rem;color:#292524;font-weight:500;">
                                    {{ $item['item_name'] }}
                                </td>

                                {{-- Expected Qty --}}
                                <td style="padding:0.5rem;text-align:center;color:#78716c;font-variant-numeric:tabular-nums;">
                                    {{ $item['expected_qty'] }}
                                </td>

                                {{-- Present Status --}}
                                <td style="padding:0.5rem;text-align:center;">
                                    @if($item['present'] === true)
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#dcfce7;color:#166534;">Yes</span>
                                    @elseif($item['present'] === false)
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#fee2e2;color:#991b1b;">No</span>
                                    @else
                                        <span style="color:#a8a29e;font-size:0.75rem;">N/A</span>
                                    @endif
                                </td>

                                {{-- Actual Qty --}}
                                <td style="padding:0.5rem;text-align:center;font-variant-numeric:tabular-nums;color:#292524;">
                                    {{ $item['actual_qty'] ?? '<span style="color:#a8a29e;">N/A</span>' }}
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
                                        <span style="color:#a8a29e;font-size:0.75rem;">N/A</span>
                                    @endif
                                </td>

                                {{-- Action --}}
                                <td style="padding:0.5rem;text-align:center;">
                                    @if($item['action'] === 'keep')
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#dcfce7;color:#166534;">Keep</span>
                                    @elseif($item['action'] === 'replace')
                                        <span style="display:inline-block;padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.6875rem;font-weight:600;background:#fee2e2;color:#991b1b;">Replace</span>
                                    @else
                                        <span style="color:#a8a29e;font-size:0.75rem;">N/A</span>
                                    @endif
                                </td>

                                {{-- Images (INLINE thumbnails) --}}
                                <td style="padding:0.5rem;" onclick="event.stopPropagation()">
                                    @if(count($item['images']) > 0)
                                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                            @foreach($item['images'] as $imagePath)
                                                <img
                                                    src="{{ asset('storage/' . $imagePath) }}"
                                                    alt="Photo"
                                                    x-on:click.stop="$dispatch('show-full-image', '{{ asset('storage/' . $imagePath) }}')"
                                                    style="width:48px;height:48px;object-fit:cover;border-radius:0.375rem;cursor:pointer;border:1px solid #e7e5e4;transition:transform 0.15s;"
                                                    onmouseover="this.style.transform='scale(1.1)'"
                                                    onmouseout="this.style.transform='scale(1)'"
                                                    loading="lazy"
                                                />
                                            @endforeach
                                        </div>
                                    @else
                                        <span style="color:#a8a29e;font-size:0.75rem;">—</span>
                                    @endif
                                </td>

                                {{-- Last Updated --}}
                                <td style="padding:0.5rem;text-align:right;color:#78716c;font-size:0.75rem;white-space:nowrap;">
                                    {{ $item['last_updated'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="text-align:center;padding:3rem;color:#a8a29e;">
                <svg style="width:3rem;height:3rem;margin:0 auto 1rem;opacity:0.5;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                <p>No inventory data for the selected session.</p>
            </div>
        @endif

        {{-- Full Image Modal (Alpine.js — no server round-trip) --}}
        <div
            x-data="{ open: false, src: '' }"
            x-on:show-full-image.window="src = $event.detail; open = true"
            x-show="open"
            x-on:click="open = false"
            x-cloak
            style="position:fixed;inset:0;z-index:50;background:rgba(0,0,0,0.85);display:flex;align-items:center;justify-content:center;cursor:pointer;"
        >
            <img
                x-bind:src="src"
                alt="Full size photo"
                style="max-width:90vw;max-height:90vh;border-radius:0.5rem;box-shadow:0 25px 50px rgba(0,0,0,0.5);"
            />
            <div style="position:absolute;top:1rem;right:1rem;color:white;font-size:0.875rem;opacity:0.7;">
                Click anywhere to close
            </div>
        </div>

        {{-- Item Detail Modal --}}
        @if($detailItemId && count($detailEntries) > 0)
            <div
                style="position:fixed;inset:0;z-index:40;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;"
            >
                <div style="background:white;border-radius:0.75rem;max-width:600px;width:90vw;max-height:80vh;overflow-y:auto;padding:1.5rem;box-shadow:0 25px 50px rgba(0,0,0,0.25);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                        <h3 style="font-size:1.125rem;font-weight:700;color:#292524;">{{ $detailItemName }}</h3>
                        <button
                            wire:click="closeItemDetail"
                            style="width:2rem;height:2rem;border-radius:0.375rem;display:flex;align-items:center;justify-content:center;background:#f5f5f4;color:#78716c;border:none;cursor:pointer;font-size:1.125rem;"
                        >&times;</button>
                    </div>
                    <p style="font-size:0.75rem;color:#78716c;margin-bottom:1rem;">All submissions for this item (newest first)</p>

                    <div style="display:flex;flex-direction:column;gap:0.75rem;">
                        @foreach($detailEntries as $entry)
                            <div style="background:#fafaf9;border:1px solid #e7e5e4;border-radius:0.5rem;padding:0.75rem;">
                                <div style="display:flex;justify-content:space-between;margin-bottom:0.375rem;">
                                    <span style="font-size:0.8125rem;font-weight:600;color:#44403c;">{{ $entry['user'] }}</span>
                                    <span style="font-size:0.75rem;color:#a8a29e;">{{ $entry['created_at'] }}</span>
                                </div>
                                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;font-size:0.75rem;color:#78716c;">
                                    @if($entry['present'] !== null)
                                        <span>Present: <strong style="color:#292524;">{{ $entry['present'] ? 'Yes' : 'No' }}</strong></span>
                                    @endif
                                    @if($entry['actual_quantity'] !== null)
                                        <span>Qty: <strong style="color:#292524;">{{ $entry['actual_quantity'] }}</strong></span>
                                    @endif
                                    @if($entry['condition'])
                                        <span>Condition: <strong style="color:#292524;text-transform:capitalize;">{{ $entry['condition'] }}</strong></span>
                                    @endif
                                    @if($entry['action'])
                                        <span>Action: <strong style="color:#292524;text-transform:capitalize;">{{ $entry['action'] }}</strong></span>
                                    @endif
                                </div>
                                @if($entry['image_path'])
                                    <div style="margin-top:0.5rem;">
                                        <img
                                            src="{{ asset('storage/' . $entry['image_path']) }}"
                                            alt="Entry photo"
                                            wire:click="showFullImage('{{ $entry['image_path'] }}')"
                                            style="width:64px;height:64px;object-fit:cover;border-radius:0.375rem;cursor:pointer;border:1px solid #e7e5e4;"
                                            loading="lazy"
                                        />
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

    </div>
</x-filament-panels::page>
