@php
    /** @var array<string, mixed> $workspace */
    $inspection = $workspace['inspection'];
    $header = $workspace['header'];
    $status = $workspace['status'];
    $checklist = $workspace['checklist'];
    $defectsById = collect($workspace['defects'])->keyBy('id');
    $imageUrl = fn (string $key): string => route('employee.vehicle-inspections.image', ['inspection' => $inspection->getKey(), 'key' => $key]);
    $itemTone = fn (string $value): string => match ($value) {
        'Present' => 'success',
        'Missing' => 'warning',
        'Damaged' => 'danger',
        default => 'neutral',
    };
    $itemText = fn (string $value): string => $value === 'Present' ? 'Pass' : $value;
    $holdLabels = \App\Filament\Support\InspectionHoldActions::LABELS;
    $actionLabels = [
        'acknowledgeIssue' => 'Acknowledge',
        'updateIssue' => 'Update action',
        'verifyCorrection' => 'Verify correction',
        'resolveIssue' => 'Resolve',
        'classifyImpact' => 'Classify impact',
        'correctSeverity' => \App\Support\Fleet\DefectSeverityCorrection::ACTION_LABEL,
        'linkServiceTicket' => 'Create service ticket',
        'dismissIssue' => \App\Support\Fleet\DefectNoActionClosure::ACTION_LABEL,
        'reopenIssue' => 'Reopen',
    ];
    $hasIssues = $workspace['legacy_pending'] || $workspace['swap'] || count($workspace['holds']) > 0
        || $workspace['member_flag'] || count($workspace['damage_holds']) > 0 || count($workspace['defects']) > 0;
@endphp
<x-filament-panels::page data-hub-ui="2" data-inspection-workspace>
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
    <div
        class="hub-insp hub-insp-stack"
        x-data="{
            newId() {
                if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID() }
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                    const r = Math.floor(Math.random() * 16)
                    return (c === 'x' ? r : (r % 4) + 8).toString(16)
                })
            },
        }"
    >
        <div class="hub-insp-print-only hub-insp-letterhead">
            <img src="/images/mbfd_logo-256.png" alt="MBFD Logo" width="80" height="80">
            <h1>Miami Beach Fire Department</h1>
            <p>Daily Vehicle Inspection Report</p>
        </div>

        {{-- A. Header --}}
        @if($inspection->test_excluded_at)
            <section class="hub-insp-card" data-inspection-test-exclusion>
                <h2 class="hub-insp-subtitle">Excluded test — retained for audit</h2>
                <p>This submission does not count toward operational inspection history or readiness.</p>
                <p>{{ $inspection->test_exclusion_reason }}</p>
            </section>
        @endif
        @if($inspection->testClassificationEvents->isNotEmpty())
            <details class="hub-insp-card" data-inspection-test-history>
                <summary>Test classification history</summary>
                @foreach($inspection->testClassificationEvents as $event)
                    <p>{{ ucfirst($event->action) }} {{ $event->created_at->clone()->timezone('America/New_York')->format('M j, Y g:i A T') }}
                        by {{ $event->actor?->display_name ?: $event->actor?->name }} — {{ $event->reason }}</p>
                @endforeach
            </details>
        @endif
        <section class="hub-insp-card" aria-labelledby="insp-header-title" data-section="header">
            <div class="hub-insp-head">
                <div class="hub-insp-head__main">
                    <h2 class="hub-insp-title" id="insp-header-title">{{ $header['designation'] }}
                        @if($header['vehicle_number'])<small>· Vehicle #{{ $header['vehicle_number'] }}</small>@endif
                    </h2>
                    <p class="hub-insp-sub">
                        {{ $header['reference'] }}
                        @if($header['station']) · {{ $header['station'] }}@endif
                    </p>
                </div>
                <span class="hub-insp-badge hub-insp-badge--lg hub-insp-badge--{{ $status['tone'] }}" data-inspection-status>{{ $status['label'] }}</span>
            </div>

            <dl class="hub-insp-facts">
                <div><dt>Submitted (Eastern)</dt><dd>{{ $header['submitted_at']?->format('M j, Y g:i A T') ?? 'Not recorded' }}</dd></div>
                <div><dt>Shift</dt><dd>{{ $header['shift'] ? $header['shift'].' Shift' : 'Not recorded' }}</dd></div>
                <div><dt>Odometer</dt><dd>{{ $header['miles'] !== null ? number_format($header['miles']).' mi' : 'Not reported' }}</dd></div>
                <div><dt>Engine hours</dt><dd>{{ $header['engine_hours'] !== null ? $header['engine_hours'] : 'Not reported' }}</dd></div>
            </dl>

            <h3 class="hub-insp-subtitle">Officer information</h3>
            <dl class="hub-insp-facts hub-insp-facts--plain">
                <div><dt>Member</dt><dd>{{ $header['member_name'] ?: 'Not recorded' }}</dd></div>
                <div><dt>Rank</dt><dd>{{ $header['member_rank'] ?: 'Not recorded' }}</dd></div>
                @if($header['show_employee_id'])
                    <div><dt>Employee ID</dt><dd>{{ $header['employee_id'] ?: 'Not linked' }}</dd></div>
                @endif
            </dl>

            <ul class="hub-insp-stats" aria-label="Inspection summary">
                <li class="hub-insp-stat @if($header['open_holds'] > 0) hub-insp-stat--missing @endif" data-open-holds="{{ $header['open_holds'] }}">
                    <span class="hub-insp-stat__value">{{ $header['open_holds'] }}</span> Open {{ $header['open_holds'] === 1 ? 'hold' : 'holds' }}
                </li>
                <li class="hub-insp-stat @if($header['open_issues'] > 0) hub-insp-stat--damaged @endif" data-open-issues="{{ $header['open_issues'] }}">
                    <span class="hub-insp-stat__value">{{ $header['open_issues'] }}</span> Open {{ $header['open_issues'] === 1 ? 'issue' : 'issues' }}
                </li>
                <li class="hub-insp-stat hub-insp-stat--pass"><span class="hub-insp-stat__value">{{ $header['totals']['present'] }}</span> Pass</li>
                <li class="hub-insp-stat hub-insp-stat--missing"><span class="hub-insp-stat__value">{{ $header['totals']['missing'] }}</span> Missing</li>
                <li class="hub-insp-stat hub-insp-stat--damaged"><span class="hub-insp-stat__value">{{ $header['totals']['damaged'] }}</span> Damaged</li>
            </ul>
        </section>

        {{-- C. Issues requiring attention (B, the actions, sit in the page header above) --}}
        <section id="approval" class="hub-insp-section hub-insp-anchor" aria-labelledby="insp-issues-title" data-section="issues">
            <div class="hub-insp-section__head">
                <h2 class="hub-insp-section__title" id="insp-issues-title">Issues requiring attention</h2>
                @if($workspace['can_review'] && $inspection->review_status === 'pending_review' && ! $inspection->isHeldForVehicleSwap())
                    <p class="hub-insp-note">
                        @if($workspace['meter_hold_open'])
                            Decide the meter reading first. Approve inspection is unavailable until you do.
                        @else
                            Use Approve inspection or Reject inspection at the top of this page once you have reviewed the items below.
                        @endif
                    </p>
                @endif
            </div>

            @unless($hasIssues)
                <p class="hub-insp-note">No holds and no missing or damaged equipment were reported on this inspection.</p>
            @endunless

            <div class="hub-insp-stack">
                @if($workspace['legacy_pending'] && $workspace['pending_effects'])
                    <div class="hub-insp-card hub-insp-card--warning" data-pending-review>
                        <h3 class="hub-insp-card__title">Pending officer review</h3>
                        <p class="hub-insp-card__lead">
                            This submission is evidence only until an authorized reviewer approves it. Approval applies the reported meter readings and defects to the apparatus.
                        </p>
                        <div class="hub-insp-twocol">
                            <div><span>Reported engine hours</span><p>{{ $inspection->engine_hours ?? 'Not reported' }}</p></div>
                            <div><span>Reported miles</span><p>{{ $inspection->miles ?? 'Not reported' }}</p></div>
                        </div>
                        @if(count($workspace['pending_effects']['defects']) > 0)
                            <h4 class="hub-insp-card__title">Reported pending defects ({{ count($workspace['pending_effects']['defects']) }})</h4>
                            <div class="hub-insp-items">
                                @foreach($workspace['pending_effects']['defects'] as $pending)
                                    <div class="hub-insp-item">
                                        <div class="hub-insp-item__head">
                                            <div>
                                                <p class="hub-insp-item__title">{{ $pending['item'] ?? 'Unknown item' }}</p>
                                                <p class="hub-insp-item__sub">{{ $pending['compartment'] ?? 'Unknown compartment' }}</p>
                                            </div>
                                            <span class="hub-insp-badge hub-insp-badge--warning">{{ $pending['status'] ?? 'Reported' }}</span>
                                        </div>
                                        @if(filled($pending['notes'] ?? null))
                                            <p class="hub-insp-item__text">{{ $pending['notes'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="hub-insp-card__lead">No defects were reported with this pending submission.</p>
                        @endif
                    </div>
                @endif

                @if($workspace['swap'])
                    @php
                        $swap = $workspace['swap'];
                    @endphp
                    <div class="hub-insp-card {{ $swap['pending'] ? 'hub-insp-card--warning' : '' }}" data-vehicle-swap-report>
                        <h3 class="hub-insp-card__title">Physical vehicle reported: {{ $swap['reported_vehicle'] }}</h3>
                        <p class="hub-insp-card__lead">
                            @if($swap['pending'])
                                The member reported a different vehicle than the one assigned to this unit ({{ $swap['assigned_vehicle'] }}). The findings are on hold until Fleet decides it from
                                <a class="hub-insp-link" href="{{ $workspace['swaps_url'] }}">Pending vehicle swaps</a>.
                            @else
                                {{ $swap['status_label'] }} by {{ $swap['decided_by'] }}{{ $swap['decided_at'] ? ' on '.$swap['decided_at'] : '' }}.
                                The vehicle ID recorded at checkout was {{ $swap['assigned_vehicle'] }}.
                            @endif
                        </p>
                        <dl class="hub-insp-fields">
                            <div><dt>Was a truck swap completed?</dt><dd>{{ $swap['answer'] }}</dd></div>
                            <div><dt>Note from the member</dt><dd class="is-prose">{{ $swap['note'] ?: 'No note' }}</dd></div>
                        </dl>
                    </div>
                @endif

                @foreach($workspace['holds'] as $hold)
                    <article id="{{ $hold['anchor'] }}" class="hub-insp-card {{ $hold['blocking'] ? 'hub-insp-card--warning' : '' }} hub-insp-anchor" aria-labelledby="{{ $hold['anchor'] }}-title" data-hold-card>
                        <div class="hub-insp-head">
                            <div class="hub-insp-head__main">
                                <h3 class="hub-insp-card__title" id="{{ $hold['anchor'] }}-title">{{ $hold['is_hold'] ? 'Meter reading hold' : 'Meter reading follow-up' }}: {{ $hold['field_label'] }}</h3>
                                <p class="hub-insp-sub">{{ $hold['reason_label'] }}</p>
                            </div>
                            <span class="hub-insp-badge hub-insp-badge--{{ $hold['blocking'] ? 'warning' : 'neutral' }}">{{ $hold['status_label'] }}</span>
                        </div>
                        <dl class="hub-insp-compare">
                            <div><dt>Previous accepted reading</dt><dd>{{ $hold['previous_value'] ?? 'Not recorded' }}</dd></div>
                            <div><dt>Submitted reading</dt><dd>{{ $hold['submitted_value'] ?? 'Not recorded' }}</dd></div>
                            <div><dt>Difference</dt><dd>{{ $hold['difference'] ?? 'Not available' }}</dd></div>
                            @if($hold['loaded_value'])
                                <div><dt>Reading when the form loaded</dt><dd>{{ $hold['loaded_value'] }}</dd></div>
                            @endif
                        </dl>
                        <dl class="hub-insp-fields">
                            <div>
                                <dt>Previous reading recorded</dt>
                                <dd>
                                    {{ $hold['previous_at'] ?? 'Date not recorded' }}
                                    @if($hold['previous_url'])
                                        <a class="hub-insp-link" href="{{ $hold['previous_url'] }}">{{ $hold['previous_reference'] }}</a>
                                    @endif
                                </dd>
                            </div>
                            <div><dt>Reporting member</dt><dd>{{ $hold['member'] ?: 'Not recorded' }}</dd></div>
                            <div><dt>Vehicle</dt><dd>{{ $hold['vehicle'] ?: 'Not recorded' }}</dd></div>
                            <div><dt>Member explanation</dt><dd class="is-prose">{{ $hold['explanation'] ?? 'No explanation recorded.' }}</dd></div>
                        </dl>
                        @foreach($hold['decisions'] as $decision)
                            <p class="hub-insp-item__text">
                                {{ $decision['at'] }} · {{ $decision['actor'] }}
                                @if($decision['revision']) · Revised reading {{ $decision['revision'] }}@endif
                                @if(filled($decision['note'])): {{ $decision['note'] }}@endif
                            </p>
                        @endforeach
                        @if($hold['blocking'] && $hold['current_value'])
                            <p class="hub-insp-note">The vehicle's reading right now is <strong>{{ $hold['current_value'] }}</strong>. This inspection cannot be approved until the reading is decided.</p>
                        @endif
                        @if(count($hold['actions']) > 0)
                            <div class="hub-insp-actions" role="group" aria-label="Decide the {{ strtolower($hold['field_label']) }} reading" data-hold-actions>
                                @foreach($hold['actions'] as $holdAction)
                                    <button
                                        type="button"
                                        class="hub-insp-btn"
                                        wire:click="mountAction('{{ $holdAction }}', { exception: {{ $hold['id'] }} })"
                                        data-hold-action="{{ $holdAction }}"
                                    >{{ $holdLabels[$holdAction] ?? $holdAction }}</button>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach

                @if($workspace['member_flag'])
                    @php
                        $flag = $workspace['member_flag'];
                    @endphp
                    <article class="hub-insp-card {{ $flag['blocking'] ? 'hub-insp-card--warning' : '' }}" data-member-flag>
                        <div class="hub-insp-head">
                            <h3 class="hub-insp-card__title">Flagged by the member for further review</h3>
                            <span class="hub-insp-badge hub-insp-badge--{{ $flag['blocking'] ? 'warning' : 'neutral' }}">{{ $flag['status_label'] }}</span>
                        </div>
                        <dl class="hub-insp-fields">
                            <div><dt>Explanation</dt><dd class="is-prose">{{ $flag['explanation'] }}</dd></div>
                            <div>
                                <dt>Items the member pointed to</dt>
                                <dd>
                                    @forelse($flag['items'] as $flagged)
                                        <span class="hub-insp-chip">{{ $flagged }}</span>
                                    @empty
                                        No specific items selected
                                    @endforelse
                                </dd>
                            </div>
                        </dl>
                        @if($flag['blocking'])
                            <p class="hub-insp-note">Approving this inspection clears the member's flag.</p>
                        @endif
                    </article>
                @endif

                @foreach($workspace['damage_holds'] as $damage)
                    <article class="hub-insp-card {{ $damage['blocking'] ? 'hub-insp-card--danger' : '' }}" data-damage-hold>
                        <div class="hub-insp-head">
                            <h3 class="hub-insp-card__title">New significant damage reported</h3>
                            <span class="hub-insp-badge hub-insp-badge--{{ $damage['blocking'] ? 'danger' : 'neutral' }}">{{ $damage['status_label'] }}</span>
                        </div>
                        <ul class="hub-insp-linklist">
                            @foreach($damage['defect_ids'] as $defectId)
                                @if($defectsById->has($defectId))
                                    <li><a class="hub-insp-link" href="#issue-{{ $defectId }}">{{ $defectsById[$defectId]['item'] }} ({{ $defectsById[$defectId]['compartment'] }})</a></li>
                                @endif
                            @endforeach
                        </ul>
                        @if($damage['blocking'])
                            <p class="hub-insp-note">Approving this inspection clears this hold. Each item below keeps its own corrective action.</p>
                        @endif
                    </article>
                @endforeach

                @foreach($workspace['defects'] as $defect)
                    <article id="{{ $defect['anchor'] }}" class="hub-insp-issue hub-insp-anchor {{ $defect['closed'] ? 'hub-insp-issue--closed' : '' }}" aria-labelledby="{{ $defect['anchor'] }}-title" data-defect-card data-defect-id="{{ $defect['id'] }}">
                        <div class="hub-insp-item__head">
                            <div>
                                <h3 class="hub-insp-issue__title" id="{{ $defect['anchor'] }}-title">{{ $defect['item'] }}</h3>
                                <p class="hub-insp-item__sub">{{ $defect['compartment'] }} · {{ $defect['issue_label'] }}</p>
                            </div>
                            <div class="hub-insp-badges">
                                <span class="hub-insp-badge hub-insp-badge--{{ $defect['action_tone'] }}">{{ $defect['action_label'] }}</span>
                                @if($defect['significant'])
                                    <span class="hub-insp-badge hub-insp-badge--danger">{{ $defect['severity_label'] }}</span>
                                @endif
                                @if($defect['recurrence_after_no_action'] ?? false)
                                    <span class="hub-insp-badge hub-insp-badge--danger">Reported again after it was closed with no action</span>
                                @elseif($defect['recurrence'])
                                    <span class="hub-insp-badge hub-insp-badge--warning">Recurring issue</span>
                                @endif
                            </div>
                        </div>

                        <dl class="hub-insp-fields">
                            <div>
                                <dt>First reported</dt>
                                <dd>
                                    {{ $defect['first_reported_at'] ?? 'Date not recorded' }}@if($defect['first_reported_by']) by {{ $defect['first_reported_by'] }}@endif
                                    @if($defect['first_inspection_url'])
                                        <a class="hub-insp-link" href="{{ $defect['first_inspection_url'] }}">{{ $defect['first_inspection_reference'] }}</a>
                                    @endif
                                </dd>
                            </div>
                            <div><dt>Observed in</dt><dd>{{ $defect['trail_count'] }} {{ $defect['trail_count'] === 1 ? 'inspection' : 'inspections' }}</dd></div>
                            <div>
                                <dt>Service ticket</dt>
                                <dd>
                                    @if($defect['ticket'])
                                        @if($defect['ticket']['url'])
                                            <a class="hub-insp-link" href="{{ $defect['ticket']['url'] }}">{{ $defect['ticket']['number'] }}</a>
                                        @else
                                            {{ $defect['ticket']['number'] }}
                                        @endif
                                        ({{ $defect['ticket']['status'] }})
                                    @else
                                        None linked
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        <div class="hub-insp-observation">
                            <p class="hub-insp-observation__label">Reported in this inspection</p>
                            <p class="hub-insp-item__text">
                                <strong>{{ $defect['observation']['status'] }}</strong> · {{ $defect['observation']['kind'] }}
                            </p>
                            @if($defect['observation']['notes'])
                                <p class="hub-insp-item__text">{{ $defect['observation']['notes'] }}</p>
                            @endif
                            @if($defect['photo_key'])
                                <div class="hub-insp-photos">
                                    <a href="{{ $imageUrl($defect['photo_key']) }}" target="_blank" rel="noopener" class="hub-insp-photo" aria-label="Open photo of {{ $defect['item'] }} in a new tab">
                                        <img src="{{ $imageUrl($defect['photo_key']) }}" alt="Photo of {{ $defect['item'] }}" loading="lazy">
                                    </a>
                                </div>
                            @endif
                        </div>

                        @if($defect['trail_count'] > 1)
                            <div class="hub-insp-trail" data-observation-trail>
                                <p class="hub-insp-observation__label">Observation trail</p>
                                <ol class="hub-insp-trail__list">
                                    @foreach($defect['trail'] as $step)
                                        <li>
                                            {{ $step['at'] ?? 'Date not recorded' }} · {{ $step['kind'] }}
                                            @if($step['current'])
                                                <span class="hub-insp-badge">This inspection</span>
                                            @elseif($step['url'])
                                                <a class="hub-insp-link" href="{{ $step['url'] }}">{{ $step['reference'] }}</a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif

                        @if($defect['closure'])
                            <div class="hub-insp-observation" data-issue-closure>
                                <p class="hub-insp-observation__label">
                                    {{ $defect['closed_no_action'] ? \App\Support\Fleet\DefectNoActionClosure::STATUS_LABEL : 'Resolved' }}@if($defect['closure']['at']) · {{ $defect['closure']['at'] }}@endif
                                </p>
                                @if($defect['closed_no_action'])
                                    <p class="hub-insp-item__text">Why no corrective action was necessary: {{ $defect['closure']['reason'] ?? 'Not recorded' }}</p>
                                    <p class="hub-insp-note">Nothing was repaired or replaced. The reason and the original report stay in the apparatus history.</p>
                                @elseif($defect['closure']['notes'])
                                    <p class="hub-insp-item__text">{{ $defect['closure']['notes'] }}</p>
                                @endif
                            </div>
                        @endif

                        @if(count($defect['actions']) > 0)
                            <div class="hub-insp-actions" role="group" aria-label="Actions for {{ $defect['item'] }}" data-issue-actions>
                                @foreach($defect['actions'] as $actionName)
                                    <button
                                        type="button"
                                        class="hub-insp-btn @if(in_array($actionName, ['acknowledgeIssue', 'resolveIssue', 'verifyCorrection', 'reopenIssue'], true)) hub-insp-btn--primary @endif"
                                        wire:click="mountAction('{{ $actionName }}', { defect: {{ $defect['id'] }} })"
                                        data-issue-action="{{ $actionName }}"
                                    >{{ $actionLabels[$actionName] ?? $actionName }}</button>
                                @endforeach
                            </div>
                        @endif
                        @if(! empty($defect['close_blocked']))
                            <p class="hub-insp-note" data-close-blocked>{{ \App\Support\Fleet\DefectNoActionClosure::ACTION_LABEL }} is not available: {{ $defect['close_blocked'] }}</p>
                        @endif

                        @if(is_array($defect['thread']))
                            <div class="hub-insp-thread" data-issue-thread>
                                <p class="hub-insp-observation__label">Conversation about this issue</p>
                                @forelse($defect['thread'] as $message)
                                    <div @class(['hub-insp-message', 'hub-insp-message--internal' => $message['internal']])>
                                        <p class="hub-insp-message__meta">
                                            @if($message['internal'])
                                                <span class="hub-insp-badge hub-insp-badge--warning">Internal note</span>
                                            @else
                                                <span class="hub-insp-badge">Member-visible</span>
                                            @endif
                                            {{ $message['author'] }} · {{ $message['at'] }}@if($message['correction']) · Correction @endif
                                        </p>
                                        <p class="hub-insp-message__body">{{ $message['body'] }}</p>
                                    </div>
                                @empty
                                    <p class="hub-insp-note">No messages about this issue yet.</p>
                                @endforelse

                                <div class="hub-insp-composer" x-data="{ cid: null }" x-init="cid = newId()">
                                    <label class="hub-insp-label" for="issue-{{ $defect['id'] }}-body">Write a message</label>
                                    <textarea id="issue-{{ $defect['id'] }}-body" class="hub-insp-input" rows="3" maxlength="4000" wire:model="issueDrafts.{{ $defect['id'] }}.body"></textarea>
                                    <label class="hub-insp-label" for="issue-{{ $defect['id'] }}-visibility">Who can read it</label>
                                    <select id="issue-{{ $defect['id'] }}-visibility" class="hub-insp-input" wire:model="issueDrafts.{{ $defect['id'] }}.visibility">
                                        <option value="member">Member-visible</option>
                                        <option value="internal">Internal note (staff only)</option>
                                    </select>
                                    <div>
                                        <button type="button" class="hub-insp-btn hub-insp-btn--primary" x-on:click="$wire.postIssueMessage({{ $defect['id'] }}, cid).then(() => { cid = newId() })">Send message</button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        {{-- General conversation with the member who submitted this inspection --}}
        @if(is_array($workspace['general_thread']))
            <section id="messages" class="hub-insp-section hub-insp-anchor" aria-labelledby="insp-messages-title" data-section="messages">
                <div class="hub-insp-section__head">
                    <h2 class="hub-insp-section__title" id="insp-messages-title">Messages with the member</h2>
                    <button type="button" class="hub-insp-btn" wire:click="mountAction('replyToMember')">Reply to member</button>
                </div>
                <div class="hub-insp-card">
                    @forelse($workspace['general_thread'] as $message)
                        <div @class(['hub-insp-message', 'hub-insp-message--internal' => $message['internal']])>
                            <p class="hub-insp-message__meta">
                                @if($message['internal'])
                                    <span class="hub-insp-badge hub-insp-badge--warning">Internal note</span>
                                @else
                                    <span class="hub-insp-badge">Member-visible</span>
                                @endif
                                {{ $message['author'] }} · {{ $message['at'] }}
                            </p>
                            <p class="hub-insp-message__body">{{ $message['body'] }}</p>
                        </div>
                    @empty
                        <p class="hub-insp-note">No messages about this inspection yet. Replies you send here are visible to the member.</p>
                    @endforelse
                </div>
            </section>
        @endif

        {{-- Inventory corrections the member reported during this checkout: a separate review, never a finding --}}
        @if(count($workspace['inventory_corrections']) > 0)
            <section id="inventory-corrections" class="hub-insp-section hub-insp-anchor" aria-labelledby="insp-inventory-title" data-section="inventory-corrections">
                <div class="hub-insp-section__head">
                    <h2 class="hub-insp-section__title" id="insp-inventory-title">Inventory corrections reported</h2>
                    <p class="hub-insp-note">The member said the checklist itself is wrong. Approving one changes future checklists, not this inspection.</p>
                </div>
                <div class="hub-insp-card">
                    <ul class="hub-insp-linklist">
                        @foreach($workspace['inventory_corrections'] as $correction)
                            <li class="hub-insp-history__row" data-inventory-correction="{{ $correction['id'] }}">
                                <div class="hub-insp-history__main">
                                    <a class="hub-insp-link hub-insp-history__title" href="{{ $correction['url'] }}">{{ $correction['item'] }}</a>
                                    <p class="hub-insp-item__sub">{{ $correction['reference'] }} · {{ $correction['reason'] }}</p>
                                </div>
                                <span class="hub-insp-badge hub-insp-badge--{{ $correction['open'] ? 'warning' : 'neutral' }}">{{ $correction['status'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        {{-- D. Original checklist, exactly as submitted --}}
        <section class="hub-insp-section" aria-labelledby="insp-checklist-title" data-section="checklist">
            <div class="hub-insp-section__head">
                <h2 class="hub-insp-section__title" id="insp-checklist-title">Original checklist</h2>
                <p class="hub-insp-note">Shown as the member submitted it. Later changes to an issue never alter this record.</p>
            </div>

            <div class="hub-insp-stack">
                @if(count($checklist['paper_fields']) > 0)
                    <div class="hub-insp-card">
                        <h3 class="hub-insp-card__title">Recorded paper checkout fields</h3>
                        <dl class="hub-insp-fields">
                            @foreach($checklist['paper_fields'] as $field)
                                <div><dt>{{ $field['name'] }}</dt><dd>{{ $field['value'] }}</dd></div>
                            @endforeach
                        </dl>
                    </div>
                @endif

                @if($workspace['pending_effects'] && $workspace['pending_effects']['checklist_v2'] !== null)
                    <x-inspection-checklist-v2-evidence :evidence="$workspace['pending_effects']['checklist_v2']" />
                @endif

                @forelse($checklist['compartments'] as $compartment)
                    <div class="hub-insp-card">
                        <h3 class="hub-insp-card__title">{{ $compartment['name'] }}</h3>
                        <div class="hub-insp-table-wrap" tabindex="0" role="region" aria-label="{{ $compartment['name'] }} checklist">
                            <table class="hub-insp-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="is-center">Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($compartment['items'] as $item)
                                        <tr @class(['is-finding' => $item['is_finding']])>
                                            <td>
                                                {{ $item['name'] }}
                                                @if($item['value'])<span class="is-value">{{ $item['value'] }}</span>@endif
                                            </td>
                                            <td class="is-center">
                                                <span class="hub-insp-badge hub-insp-badge--{{ $itemTone($item['status']) }}">{{ $itemText($item['status']) }}</span>
                                            </td>
                                            <td class="is-muted">
                                                {{ $item['notes'] ?? 'No notes' }}
                                                @if($item['photo_key'])
                                                    <a href="{{ $imageUrl($item['photo_key']) }}" target="_blank" rel="noopener" class="hub-insp-table-link">View photo</a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @empty
                    <div class="hub-insp-card">
                        <p class="hub-insp-note">No detailed checklist results were recorded for this inspection.</p>
                    </div>
                @endforelse

                @if($checklist['signature_key'])
                    <div class="hub-insp-card">
                        <h3 class="hub-insp-card__title">Officer signature</h3>
                        <a href="{{ $imageUrl($checklist['signature_key']) }}" target="_blank" rel="noopener" class="hub-insp-signature" aria-label="Open officer signature in a new tab">
                            <img src="{{ $imageUrl($checklist['signature_key']) }}" alt="Officer signature" loading="lazy">
                        </a>
                    </div>
                @endif
            </div>
        </section>

        {{-- E. Review history and activity --}}
        <section class="hub-insp-section" aria-labelledby="insp-activity-title" data-section="activity">
            <div class="hub-insp-section__head">
                <h2 class="hub-insp-section__title" id="insp-activity-title">Review history and activity</h2>
            </div>
            <div class="hub-insp-card">
                <ol class="hub-insp-timeline">
                    @foreach($workspace['timeline'] as $entry)
                        <li class="hub-insp-timeline__item" data-timeline-kind="{{ $entry['kind'] }}">
                            <div class="hub-insp-timeline__head">
                                <p class="hub-insp-timeline__title">{{ $entry['title'] }}</p>
                                @if($entry['internal'])
                                    <span class="hub-insp-badge hub-insp-badge--warning">Internal</span>
                                @endif
                            </div>
                            <p class="hub-insp-item__sub">
                                {{ $entry['at'] ?? 'Time not recorded' }}@if($entry['actor']) · {{ $entry['actor'] }}@endif
                                @if($entry['anchor'])
                                    · <a class="hub-insp-link" href="#{{ $entry['anchor'] }}">Go to issue</a>
                                @endif
                            </p>
                            @if(filled($entry['body']))
                                <p class="hub-insp-item__text" style="white-space: pre-line;">{{ $entry['body'] }}</p>
                            @endif
                            @if(is_array($entry['evidence']))
                                <x-inspection-checklist-v2-evidence :evidence="$entry['evidence']" />
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- F. Related vehicle history --}}
        <section class="hub-insp-section" aria-labelledby="insp-history-title" data-section="history">
            <div class="hub-insp-section__head">
                <h2 class="hub-insp-section__title" id="insp-history-title">Recent inspections of this vehicle</h2>
                @if($workspace['apparatus_url'])
                    <a class="hub-insp-link" href="{{ $workspace['apparatus_url'] }}">Open apparatus profile</a>
                @endif
            </div>
            <div class="hub-insp-card">
                <ul class="hub-insp-history">
                    @foreach($workspace['history'] as $row)
                        <li class="hub-insp-history__row" @if($row['current']) aria-current="page" @endif>
                            <div class="hub-insp-history__main">
                                @if($row['current'])
                                    <p class="hub-insp-history__title">This inspection</p>
                                @else
                                    <a class="hub-insp-link" href="{{ $row['url'] }}">{{ $row['at'] ?? $row['reference'] }}</a>
                                @endif
                                <p class="hub-insp-item__sub">{{ $row['reference'] }}@if($row['member']) · {{ $row['member'] }}@endif</p>
                            </div>
                            <div class="hub-insp-badges">
                                <span class="hub-insp-badge hub-insp-badge--{{ $row['status']['tone'] }}">{{ $row['status']['label'] }}</span>
                                <span class="hub-insp-badge">{{ $row['issues'] }} {{ $row['issues'] === 1 ? 'issue' : 'issues' }} reported</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    </div>
</x-filament-panels::page>
