<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <x-filament::section>
        <p class="text-sm text-gray-600">
            Daily Checkouts you submitted, newest first. This is a read-only record: use View for the full checklist and
            follow-up status, or PDF for a printable copy. Review status shows whether an officer has approved the
            submission. Inspections still queued on a device appear here after that device syncs.
        </p>
    </x-filament::section>
    {{ $this->table }}

    @if(count($inventoryCorrections) > 0)
        <section id="inventory-corrections" class="hub-insp hub-insp-section hub-insp-anchor" aria-labelledby="my-inventory-corrections-title">
            <div class="hub-insp-section__head">
                <h2 class="hub-insp-section__title" id="my-inventory-corrections-title">Inventory corrections you reported</h2>
                <p class="hub-insp-note">Checklist entries you told Logistics were wrong.</p>
            </div>
            <div class="hub-insp-card">
                <ul class="hub-insp-linklist">
                    @foreach($inventoryCorrections as $correction)
                        <li class="hub-insp-history__row" data-inventory-correction-row="{{ $correction['id'] }}">
                            <div class="hub-insp-history__main">
                                <a class="hub-insp-link hub-insp-history__title" href="{{ $correction['url'] }}">{{ $correction['apparatus'] }} · {{ $correction['item'] }}</a>
                                <p class="hub-insp-item__sub">{{ $correction['reason'] }}@if($correction['compartment']) · {{ $correction['compartment'] }}@endif · {{ $correction['reported_at']?->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y') }}</p>
                            </div>
                            <span @class(['hub-insp-badge', 'hub-insp-badge--info' => $correction['needs_reply'], 'hub-insp-badge--success' => $correction['status'] === 'applied', 'hub-insp-badge--warning' => $correction['status'] === 'submitted'])>{{ $correction['needs_reply'] ? 'Reply needed' : $correction['status_label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif
</x-filament-panels::page>
