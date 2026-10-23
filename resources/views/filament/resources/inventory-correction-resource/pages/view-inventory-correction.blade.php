@php
    /** @var \App\Models\ApparatusInventoryCorrection $correction */
    $snapshot = is_array($correction->target_snapshot) ? $correction->target_snapshot : [];
    $proposal = is_array($correction->proposal) ? $correction->proposal : [];
    $decision = is_array($correction->decision) ? $correction->decision : [];
    $reporter = $correction->reporter;
    $reporterName = $reporter?->display_name ?: $reporter?->name ?: 'Member';
    $tone = match ($correction->status) {
        'submitted' => 'warning',
        'clarification_requested' => 'info',
        'applied' => 'success',
        default => 'neutral',
    };
    $value = fn ($v) => filled($v) ? $v : 'Not set';
    $fieldLabels = ['compartment' => 'Compartment', 'name' => 'Name', 'expectedQuantity' => 'Expected quantity', 'manufacturer' => 'Manufacturer', 'model' => 'Model', 'description' => 'Description'];
    $proposalLabels = ['to_compartment_id' => 'Moved to', 'to_location_text' => 'Where it is now (member\'s words)', 'name' => 'Name', 'manufacturer' => 'Manufacturer', 'model' => 'Model', 'description' => 'Description', 'quantity' => 'Quantity'];
    $eventTitles = [
        'submitted' => 'Reported by the member',
        'clarification_requested' => 'Logistics asked a question',
        'member_replied' => 'Member replied',
        'logistics_replied' => 'Logistics replied',
        'internal_note' => 'Internal note',
        'approved' => 'Approved',
        'applied' => 'Applied to the checklist',
        'rejected' => 'Not approved',
        'reversed' => 'Change reversed',
        'linked_to_inspection' => 'Linked to the submitted inspection',
    ];
@endphp
<x-filament-panels::page data-hub-ui="2" data-inventory-correction="{{ $correction->getKey() }}">
    <div class="hub-insp hub-insp-stack">
        <section class="hub-insp-card" aria-labelledby="inv-header-title" data-section="header">
            <div class="hub-insp-head">
                <div class="hub-insp-head__main">
                    <h2 class="hub-insp-title" id="inv-header-title">{{ $snapshot['name'] ?? ('New item: '.($proposal['name'] ?? 'unnamed')) }}
                        <small>· {{ $snapshot['compartment'] ?? 'Compartment not recorded' }}</small>
                    </h2>
                    <p class="hub-insp-sub">{{ $correction->reference() }} · {{ $reasonLabel }}</p>
                </div>
                <span class="hub-insp-badge hub-insp-badge--lg hub-insp-badge--{{ $tone }}" data-correction-status="{{ $correction->status }}">{{ $statusLabel }}</span>
            </div>
            <dl class="hub-insp-facts">
                <div><dt>Apparatus</dt><dd>
                    @if($apparatusUrl)<a class="hub-insp-link" href="{{ $apparatusUrl }}">{{ \App\Filament\Resources\InventoryCorrectionResource::unitLabel($correction) }}</a>@else{{ \App\Filament\Resources\InventoryCorrectionResource::unitLabel($correction) }}@endif
                </dd></div>
                <div><dt>Reported (Eastern)</dt><dd>{{ $correction->created_at?->timezone('America/New_York')->format('M j, Y g:i A T') }}</dd></div>
                <div><dt>Member</dt><dd>{{ $reporterName }}@if($correction->reporterEmployee?->rank) · {{ $correction->reporterEmployee->rank }}@endif</dd></div>
                <div><dt>Inspection</dt><dd>
                    @if($inspectionUrl)<a class="hub-insp-link" href="{{ $inspectionUrl }}">Open the inspection</a>@else Reported while the inspection was in progress @endif
                </dd></div>
            </dl>
            <p class="hub-insp-note">An inventory correction is separate from the inspection's findings: it never marks equipment missing or damaged, never holds the inspection and never changes the unit's status.</p>
        </section>

        <section class="hub-insp-section" aria-labelledby="inv-compare-title" data-section="compare">
            <div class="hub-insp-section__head">
                <h2 class="hub-insp-section__title" id="inv-compare-title">What the checklist says and what the member reported</h2>
            </div>
            <div class="hub-insp-twocol">
                <div class="hub-insp-card" data-before>
                    <h3 class="hub-insp-card__title">Checklist now</h3>
                    @if($current)
                        <dl class="hub-insp-fields">
                            @foreach($fieldLabels as $key => $label)
                                <div><dt>{{ $label }}</dt><dd>{{ $value($current[$key] ?? null) }}</dd></div>
                            @endforeach
                        </dl>
                    @elseif($correction->item_key === null)
                        <p class="hub-insp-note">Not on the checklist. The member found it in {{ $snapshot['compartment'] ?? 'an unlisted place' }}.</p>
                    @else
                        <p class="hub-insp-note">This item is no longer on the apparatus checklist.</p>
                    @endif
                </div>
                <div class="hub-insp-card" data-proposal>
                    <h3 class="hub-insp-card__title">Member's report</h3>
                    <p class="hub-insp-item__text"><strong>{{ $reasonLabel }}</strong></p>
                    @if($proposal !== [])
                        <dl class="hub-insp-fields">
                            @foreach($proposal as $key => $proposed)
                                <div><dt>{{ $proposalLabels[$key] ?? $key }}</dt><dd>{{ $key === 'to_compartment_id' ? ($compartmentNames[$proposed] ?? $proposed) : $proposed }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                    @if(filled($correction->member_note))
                        <p class="hub-insp-item__sub">Member's note</p>
                        <p class="hub-insp-item__text">{{ $correction->member_note }}</p>
                    @endif
                    @if($photoUrl)
                        <a class="hub-insp-table-link" href="{{ $photoUrl }}" target="_blank" rel="noopener">View the member's photo</a>
                    @endif
                </div>
            </div>
            @if($correction->isOpen())
                <div class="hub-insp-card" data-suggested>
                    <h3 class="hub-insp-card__title">If approved as reported</h3>
                    @if($suggestedAfter)
                        <dl class="hub-insp-fields">
                            @foreach($fieldLabels as $key => $label)
                                <div><dt>{{ $label }}</dt><dd>{{ $value($suggestedAfter[$key] ?? null) }}</dd></div>
                            @endforeach
                        </dl>
                    @elseif($correction->reason === 'no_longer_carried')
                        <p class="hub-insp-item__text">The item is removed from this apparatus's checklist.</p>
                    @else
                        <p class="hub-insp-note">{{ $suggestedVerdict ? $suggestedVerdict.'.' : '' }} Choose the exact change in "Review and approve".</p>
                    @endif
                </div>
            @endif
        </section>

        @if($changes->isNotEmpty())
            <section class="hub-insp-section" aria-labelledby="inv-applied-title" data-section="applied">
                <div class="hub-insp-section__head">
                    <h2 class="hub-insp-section__title" id="inv-applied-title">Inventory changes made</h2>
                    @if(($decision['modified'] ?? false) === true)
                        <p class="hub-insp-note">Logistics edited the member's proposal before approving. Both are kept.</p>
                    @endif
                </div>
                <div class="hub-insp-card">
                    <ul class="hub-insp-linklist">
                        @foreach($changes as $change)
                            <li class="hub-insp-history__row" data-change="{{ $change->getKey() }}" data-change-status="{{ $change->status }}">
                                <div class="hub-insp-history__main">
                                    <p class="hub-insp-history__title">{{ $change->apparatus?->designation ?: $change->apparatus?->name }} · change #{{ $change->getKey() }}</p>
                                    <p class="hub-insp-item__sub">
                                        {{ ['remove' => 'Removed', 'modify' => 'Changed', 'add' => 'Added'][$change->operation] ?? $change->operation }}
                                        @foreach($change->payload ?? [] as $key => $new)
                                            · {{ $fieldLabels[$key] ?? ($key === 'compartment_id' ? 'Compartment' : $key) }}: {{ $key === 'compartment_id' ? ($compartmentNames[$new] ?? $new) : $value($new) }}
                                        @endforeach
                                    </p>
                                </div>
                                <span class="hub-insp-badge hub-insp-badge--{{ $change->status === 'active' ? 'success' : 'neutral' }}">{{ ['active' => 'In effect', 'superseded' => 'Replaced by a later change', 'reversed' => 'Reversed'][$change->status] ?? $change->status }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        <section id="messages" class="hub-insp-section hub-insp-anchor" aria-labelledby="inv-thread-title" data-section="thread">
            <div class="hub-insp-section__head">
                <h2 class="hub-insp-section__title" id="inv-thread-title">Conversation and history</h2>
                <button type="button" class="hub-insp-btn" wire:click="mountAction('reply')">Reply or add note</button>
            </div>
            <div class="hub-insp-card">
                <ol class="hub-insp-timeline">
                    @foreach($events as $event)
                        <li class="hub-insp-timeline__item" data-event="{{ $event->event_type }}" data-visibility="{{ $event->visibility }}">
                            <div class="hub-insp-timeline__head">
                                <p class="hub-insp-timeline__title">{{ $eventTitles[$event->event_type] ?? $event->event_type }}</p>
                                @if($event->visibility === 'internal')
                                    <span class="hub-insp-badge hub-insp-badge--warning">Internal</span>
                                @else
                                    <span class="hub-insp-badge">Member-visible</span>
                                @endif
                            </div>
                            <p class="hub-insp-item__sub">{{ $event->created_at?->timezone('America/New_York')->format('M j, Y g:i A') }} · {{ $event->actor?->display_name ?: $event->actor?->name ?: 'System' }}</p>
                            @if(filled($event->body))
                                <p class="hub-insp-item__text">{{ $event->body }}</p>
                            @endif
                            @if($event->event_type === 'applied' && is_array($event->details['results'] ?? null))
                                <ul class="hub-insp-linklist">
                                    @foreach($event->details['results'] as $result)
                                        <li class="hub-insp-item__sub">{{ $result['label'] }}: {{ ($result['applied'] ?? false) ? 'changed' : (($result['selected'] ?? false) ? 'not changed' : 'excluded ('.(\App\Services\Inventory\InventoryChangePlanner::VERDICTS[$result['verdict']] ?? $result['verdict']).')') }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>
    </div>
</x-filament-panels::page>
