{{-- Layout and colour: semantic hub-insp-* classes in resources/css/filament/hub-inspection-views.css. --}}
<x-filament-widgets::widget>
    @php($zone = \App\Support\HubTimezone::NAME)
    <div class="hub-insp" wire:poll.30s.visible="poll" data-equipment-change-requests>
        <section class="hub-insp-card" aria-labelledby="ecr-title">
            <div class="hub-insp-head">
                <div class="hub-insp-head__main">
                    <h2 class="hub-insp-title" id="ecr-title">Equipment Change Requests</h2>
                    <p class="hub-insp-sub">Crews ask for a change to a vehicle's equipment list. Nothing changes until you approve it.</p>
                </div>
                <span @class(['hub-insp-badge', 'hub-insp-badge--lg', 'hub-insp-badge--warning' => $open > 0, 'hub-insp-badge--success' => $open === 0]) data-pending-count="{{ $open }}" role="status">
                    {{ $open === 0 ? 'All caught up' : ($open.' '.\Illuminate\Support\Str::plural('request', $open).' pending') }}
                </span>
            </div>

            @if ($rows === [])
                <p class="hub-insp-note" data-empty>No equipment change requests are waiting. When a crew member reports a wrong, moved or missing item during a Daily Checkout, it shows up here.</p>
            @else
                @if ($waitingOnMember > 0)
                    <p class="hub-insp-note">{{ $waitingOnMember }} of these {{ $waitingOnMember === 1 ? 'is' : 'are' }} waiting for the member to answer your question.</p>
                @endif
                <ul class="hub-insp-linklist" aria-label="Newest equipment change requests">
                    @foreach ($rows as $row)
                        <li class="hub-insp-history__row" wire:key="ecr-row-{{ $row['id'] }}" data-request="{{ $row['id'] }}" data-request-status="{{ $row['status'] }}">
                            <div class="hub-insp-history__main">
                                <a class="hub-insp-link hub-insp-history__title" href="{{ $row['url'] }}">{{ $row['unit'] }} · {{ $row['item'] }}</a>
                                @foreach ($row['diff'] as $change)
                                    <p class="hub-insp-item__text" data-diff>
                                        <span class="hub-insp-item__sub">{{ $change['label'] }}:</span>
                                        <s>{{ $change['before'] }}</s> → <strong>{{ $change['after'] }}</strong>
                                    </p>
                                @endforeach
                                @if (filled($row['note']))
                                    <p class="hub-insp-item__sub">"{{ \Illuminate\Support\Str::limit($row['note'], 140) }}"</p>
                                @endif
                                <p class="hub-insp-item__sub">
                                    {{ $row['reference'] }} · {{ $row['member'] }} ·
                                    <time datetime="{{ $row['reported_at']?->toIso8601String() }}" title="{{ $row['reported_at']?->timezone($zone)->format('l, F j, Y g:i A') }}">{{ $row['reported_at']?->timezone($zone)->diffForHumans() }}</time>
                                </p>
                            </div>
                            <div>
                                <span @class(['hub-insp-badge', 'hub-insp-badge--warning' => $row['status'] === 'submitted', 'hub-insp-badge--info' => $row['status'] !== 'submitted'])>{{ $row['status_label'] }}</span>
                                <a class="hub-insp-btn" href="{{ $row['url'] }}" aria-label="Review {{ $row['reference'] }}: {{ $row['unit'] }}, {{ $row['item'] }}">Review</a>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p><a class="hub-insp-link" href="{{ $listUrl }}">View all equipment change requests</a></p>
            @endif
        </section>
    </div>
</x-filament-widgets::widget>
