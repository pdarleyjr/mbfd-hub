@php
    /** @var array<string, mixed>|null $diff */
    $caption ??= null;
    $counts = $diff['counts'] ?? [];
    $value = fn ($v) => filled($v) ? $v : 'none';
@endphp
<div class="hub-insp hub-tpl-diff" data-template-diff>
    @if($caption)
        <p class="hub-insp-note">{{ $caption }}</p>
    @endif
    @if($diff === null)
        {{-- nothing to compare --}}
    @elseif(! ($diff['has_changes'] ?? false))
        <p class="hub-insp-note" data-no-changes>No changes: the checklist is the same.</p>
    @else
        <div class="hub-tpl-metrics" role="list" aria-label="Summary of changes">
            <div class="hub-insp-metric" role="listitem"><p class="hub-insp-metric__value">{{ (int) ($counts['added'] ?? 0) }}</p><p class="hub-insp-metric__label">Items added</p></div>
            <div class="hub-insp-metric" role="listitem"><p class="hub-insp-metric__value">{{ (int) ($counts['removed'] ?? 0) }}</p><p class="hub-insp-metric__label">Items removed</p></div>
            <div class="hub-insp-metric" role="listitem"><p class="hub-insp-metric__value">{{ (int) ($counts['changed'] ?? 0) }}</p><p class="hub-insp-metric__label">Items changed</p></div>
            <div class="hub-insp-metric" role="listitem"><p class="hub-insp-metric__value">{{ (int) ($counts['compartments_changed'] ?? 0) }}</p><p class="hub-insp-metric__label">Compartments changed</p></div>
        </div>

        @if(($diff['compartments_added'] ?? []) !== [] || ($diff['compartments_removed'] ?? []) !== [] || ($diff['compartments_renamed'] ?? []) !== [] || ($diff['compartments_reordered'] ?? false))
            <h3 class="hub-tpl-subtitle">Compartments</h3>
            <ul class="hub-tpl-list">
                @foreach($diff['compartments_added'] ?? [] as $row)
                    <li data-diff="compartment-added"><span class="hub-insp-badge hub-insp-badge--success">Added</span> {{ $row['name'] }}</li>
                @endforeach
                @foreach($diff['compartments_removed'] ?? [] as $row)
                    <li data-diff="compartment-removed"><span class="hub-insp-badge hub-insp-badge--danger">Removed</span> {{ $row['name'] }}</li>
                @endforeach
                @foreach($diff['compartments_renamed'] ?? [] as $row)
                    <li data-diff="compartment-renamed"><span class="hub-insp-badge hub-insp-badge--info">Renamed</span> <s>{{ $row['from'] }}</s> → <strong>{{ $row['to'] }}</strong></li>
                @endforeach
                @if($diff['compartments_reordered'] ?? false)
                    <li data-diff="compartments-reordered"><span class="hub-insp-badge">Order</span> The compartment order changed.</li>
                @endif
            </ul>
        @endif

        @if(($diff['items_added'] ?? []) !== [])
            <h3 class="hub-tpl-subtitle">Items added</h3>
            <ul class="hub-tpl-list">
                @foreach($diff['items_added'] as $row)
                    <li data-diff="item-added"><strong>{{ $row['name'] }}</strong> <span class="hub-tpl-muted">in {{ $row['compartment'] }}</span></li>
                @endforeach
            </ul>
        @endif

        @if(($diff['items_removed'] ?? []) !== [])
            <h3 class="hub-tpl-subtitle">Items removed</h3>
            <ul class="hub-tpl-list">
                @foreach($diff['items_removed'] as $row)
                    <li data-diff="item-removed"><s>{{ $row['name'] }}</s> <span class="hub-tpl-muted">from {{ $row['compartment'] }}</span></li>
                @endforeach
            </ul>
        @endif

        @if(($diff['items_changed'] ?? []) !== [])
            <h3 class="hub-tpl-subtitle">Items changed</h3>
            <div class="hub-insp-table-wrap">
                <table data-hub-table class="hub-insp-table">
                    <caption class="sr-only">Changed items with each field before and after</caption>
                    <thead><tr><th scope="col">Item</th><th scope="col">Detail</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
                    <tbody>
                        @foreach($diff['items_changed'] as $row)
                            @foreach($row['changes'] ?? [] as $change)
                                <tr data-diff="item-changed" data-item-id="{{ $row['id'] }}">
                                    <td>@if($loop->first)<strong>{{ $row['name'] }}</strong><span class="is-value">{{ $row['compartment'] }}</span>@endif</td>
                                    <td>{{ $change['label'] ?? $change['field'] }}</td>
                                    <td><s>{{ $value($change['from'] ?? null) }}</s></td>
                                    <td><strong>{{ $value($change['to'] ?? null) }}</strong></td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
