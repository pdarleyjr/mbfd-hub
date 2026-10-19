<x-filament-panels::page>
    {{-- Print-only styles --}}
    <style media="print">
        .fi-sidebar, .fi-topbar, .fi-header-actions, .fi-breadcrumbs,
        nav, [data-turbo-permanent], .fi-page-header-actions, [data-hub-page-nav],
        button, .fi-btn { display: none !important; }
        body { background: white !important; }
        .fi-page { padding: 0 !important; }
        .hub-insp-print-only { display: block !important; }
    </style>

    {{-- Layout and colour: semantic hub-insp-* classes in resources/css/filament/hub-inspection-views.css. --}}
    <div class="hub-insp hub-insp-stack">
        {{-- Letterhead: shown only when printing. --}}
        <div class="hub-insp-print-only hub-insp-letterhead">
            <img src="/images/mbfd_logo-256.png" alt="MBFD Logo" width="80" height="80">
            <h1>Miami Beach Fire Department</h1>
            <p>Daily Vehicle Inspection Report</p>
        </div>

        {{-- Inspection Summary Card --}}
        <div class="hub-insp-card">
            <div class="hub-insp-summary">
                <img src="/images/mbfd_logo-256.png" alt="MBFD Logo" width="64" height="64">
                <div>
                    <h2>Inspection Report: {{ $currentDesignation }}</h2>
                    <p>
                        Vehicle #{{ $inspection->vehicle_number ?? $apparatus->vehicle_number }}
                        &bull; {{ $inspection->completed_at?->format('F j, Y \a\t g:i A') ?? 'N/A' }}
                    </p>
                    <p class="hub-insp-summary__status">{{ $inspection->displayStatus() }}</p>
                </div>
            </div>

            <ul class="hub-insp-statcards" aria-label="Checklist totals">
                <li class="hub-insp-statcard">
                    <p class="hub-insp-statcard__value">{{ $totalItems }}</p>
                    <p class="hub-insp-statcard__label">Total Items</p>
                </li>
                <li class="hub-insp-statcard hub-insp-statcard--pass">
                    <p class="hub-insp-statcard__value">{{ $presentCount }}</p>
                    <p class="hub-insp-statcard__label">Present</p>
                </li>
                <li class="hub-insp-statcard hub-insp-statcard--missing">
                    <p class="hub-insp-statcard__value">{{ $missingCount }}</p>
                    <p class="hub-insp-statcard__label">Missing</p>
                </li>
                <li class="hub-insp-statcard hub-insp-statcard--damaged">
                    <p class="hub-insp-statcard__value">{{ $damagedCount }}</p>
                    <p class="hub-insp-statcard__label">Damaged</p>
                </li>
            </ul>
        </div>

        {{-- Officer Info --}}
        <div class="hub-insp-card">
            <h3 class="hub-insp-card__title">Officer Information</h3>
            <dl class="hub-insp-facts hub-insp-facts--plain">
                <div>
                    <dt>Operator</dt>
                    <dd>{{ $inspection->operator_name }}</dd>
                </div>
                <div>
                    <dt>Rank</dt>
                    <dd>{{ $inspection->rank }}</dd>
                </div>
                <div>
                    <dt>Shift</dt>
                    <dd>{{ $inspection->shift }} Shift</dd>
                </div>
                <div>
                    <dt>Physical vehicle #</dt>
                    <dd>{{ $inspection->unit_number ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        {{-- Compartment Results --}}
        @if(count($inspection->checklist_evidence ?? []) > 0)
            <section class="hub-insp-card">
                <h3 class="hub-insp-card__title">Recorded paper checkout fields</h3>
                <dl class="hub-insp-fields">
                    @foreach($inspection->checklist_evidence as $field)
                        <div><dt>{{ $field['name'] }}</dt><dd>{{ is_bool($field['value']) ? ($field['value'] ? 'Yes' : 'No') : ($field['value'] === null || $field['value'] === '' ? 'Not entered' : $field['value']) }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endif
        @if(count($results) > 0)
            @foreach($results as $compartment)
                <div class="hub-insp-card">
                    <h3 class="hub-insp-card__title">{{ $compartment['name'] ?? 'Compartment' }}</h3>
                    <div class="hub-insp-table-wrap">
                        <table class="hub-insp-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="is-center">Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($compartment['items'] ?? [] as $item)
                                    @php
                                        $status = $item['status'] ?? 'Present';
                                        $tone = $status === 'Present' ? 'success' : ($status === 'Missing' ? 'warning' : 'danger');
                                    @endphp
                                    <tr>
                                        <td>{{ $item['name'] ?? '—' }}
                                            @if(isset($item['value']) && $item['value'] !== '')
                                                <span class="is-value">{{ $item['value'] }}</span>
                                            @endif
                                        </td>
                                        <td class="is-center">
                                            <span class="hub-insp-badge hub-insp-badge--{{ $tone }}">
                                                {{ $status === 'Present' ? '✓ Pass' : ($status === 'Missing' ? '✕ Missing' : '⚠ Damaged') }}
                                            </span>
                                        </td>
                                        <td class="is-muted">{{ $item['notes'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @else
            <div class="hub-insp-card">
                <p class="hub-insp-note">No detailed checklist results recorded for this inspection.</p>
            </div>
        @endif

        {{-- Pending-review evidence has not changed apparatus readiness, defects, or meters. --}}
        @if($inspection->review_status === 'pending_review')
            <div class="hub-insp-card hub-insp-card--warning">
                <h3 class="hub-insp-card__title">Pending officer review</h3>
                <p class="hub-insp-card__lead">
                    This submission is evidence only until an authorized reviewer approves it. Approval applies the reported meter readings and defects to the apparatus.
                </p>
                <div class="hub-insp-twocol">
                    <div>
                        <span>Reported engine hours</span>
                        <p>{{ $inspection->engine_hours ?? 'Not reported' }}</p>
                    </div>
                    <div>
                        <span>Reported miles</span>
                        <p>{{ $inspection->miles ?? 'Not reported' }}</p>
                    </div>
                </div>

                @if($pendingChecklistV2 !== null)
                    <x-inspection-checklist-v2-evidence :evidence="$pendingChecklistV2" />
                @endif

                @if(count($pendingDefects) > 0)
                    <h4 class="hub-insp-card__title">Reported pending defects ({{ count($pendingDefects) }})</h4>
                    <div class="hub-insp-items">
                        @foreach($pendingDefects as $defect)
                            <div class="hub-insp-item">
                                <div class="hub-insp-item__head">
                                    <div>
                                        <p class="hub-insp-item__title">{{ $defect['item'] ?? 'Unknown item' }}</p>
                                        <p class="hub-insp-item__sub">{{ $defect['compartment'] ?? 'Unknown compartment' }}</p>
                                    </div>
                                    <span class="hub-insp-badge hub-insp-badge--warning">{{ $defect['status'] ?? 'Reported' }}</span>
                                </div>
                                @if(filled($defect['notes'] ?? null))
                                    <p class="hub-insp-item__text">{{ $defect['notes'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="hub-insp-card__lead">No defects were reported with this pending submission.</p>
                @endif
            </div>
        @endif

        @if($reviewEvents->isNotEmpty())
            <div class="hub-insp-card">
                <h3 class="hub-insp-card__title">Review History</h3>
                <div class="hub-insp-items">
                    @foreach($reviewEvents as $reviewEvent)
                        <div class="hub-insp-item">
                            <div class="hub-insp-item__head">
                                <p class="hub-insp-item__title">
                                    {{ ucfirst(str_replace('_', ' ', $reviewEvent->previous_status)) }} → {{ ucfirst(str_replace('_', ' ', $reviewEvent->status)) }}
                                </p>
                                <p class="hub-insp-item__sub">{{ $reviewEvent->created_at?->format('M j, Y g:i A') }}</p>
                            </div>
                            <p class="hub-insp-item__text">
                                Reviewer: {{ $reviewEvent->changedByUser?->name ?? data_get($reviewEvent->metadata, 'reviewer_name', 'User no longer active') }}
                            </p>
                            @if(filled($reviewEvent->internal_note))
                                <p class="hub-insp-item__text">{{ $reviewEvent->internal_note }}</p>
                            @endif
                            @php($reviewChecklistV2 = data_get($reviewEvent->metadata, 'submitted_effects.checklist_v2'))
                            @if(is_array($reviewChecklistV2))
                                <x-inspection-checklist-v2-evidence :evidence="$reviewChecklistV2" />
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Defects Section --}}
        @if($defects->count() > 0)
            <div class="hub-insp-card hub-insp-card--danger">
                <h3 class="hub-insp-card__title">⚠ Reported Defects ({{ $defects->count() }})</h3>
                <div class="hub-insp-items">
                    @foreach($defects as $defect)
                        <div class="hub-insp-item">
                            <div class="hub-insp-item__head">
                                <div>
                                    <p class="hub-insp-item__title">{{ $defect->item ?? 'Unknown Item' }}</p>
                                    <p class="hub-insp-item__sub">{{ $defect->compartment ?? '—' }}</p>
                                </div>
                                <span class="hub-insp-badge hub-insp-badge--{{ $defect->resolved ? 'success' : 'danger' }}">
                                    {{ $defect->resolved ? 'Resolved' : 'Open' }}
                                </span>
                            </div>
                            @if($defect->notes)
                                <p class="hub-insp-item__text">{{ $defect->notes }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
