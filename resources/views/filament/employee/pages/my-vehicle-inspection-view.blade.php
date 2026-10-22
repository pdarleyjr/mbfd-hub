@php
    $imageUrl = fn (string $key): string => route('employee.vehicle-inspections.image', ['inspection' => $inspectionId, 'key' => $key]);
    // Layout and colour live in resources/css/filament/hub-inspection-views.css (semantic hub-insp-* classes).
    $statusTone = fn (string $status): string => match ($status) {
        'Present' => 'success',
        'Missing' => 'warning',
        'Damaged' => 'danger',
        default => 'neutral',
    };
    $statusText = fn (string $status): string => $status === 'Present' ? 'Pass' : $status;
    $acceptance = $detail['acceptance'];
    $reviewTone = match ($acceptance['state']) {
        'accepted' => 'success',
        'rejected' => 'danger',
        'needs_approval' => 'warning',
        default => 'neutral',
    };
@endphp
<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <x-filament::section>
        <div class="hub-insp">
            <div class="hub-insp-head">
                <div class="hub-insp-head__main">
                    <h2 class="hub-insp-title">{{ $detail['apparatus_label'] }}
                        @if($detail['vehicle_number'])<small>· Vehicle #{{ $detail['vehicle_number'] }}</small>@endif
                    </h2>
                    <p class="hub-insp-sub">
                        {{ $detail['reference'] }}
                        @if($detail['station_label']) · {{ $detail['station_label'] }}@endif
                    </p>
                </div>
                <span class="hub-insp-badge hub-insp-badge--lg hub-insp-badge--{{ $reviewTone }}">{{ $acceptance['headline'] }}</span>
            </div>

            <div id="acceptance" class="hub-insp-accept" style="margin-top: 1rem;">
                <p>
                    @if($acceptance['state'] === 'needs_approval')
                        Needs approval{{ count($acceptance['reasons']) > 0 ? ': '.implode('; ', $acceptance['reasons']) : '' }}
                    @else
                        {{ $acceptance['headline'] }}
                    @endif
                </p>
            </div>

            @if($acceptance['state'] === 'rejected')
                <p class="hub-insp-note">
                    @if(filled($conversation['rejection_reason']))
                        Reason from Logistics: {{ $conversation['rejection_reason'] }}
                    @else
                        Logistics did not add a reason. Use the messages section below to ask.
                    @endif
                </p>
            @endif

            @if($conversation['clarification'])
                <div class="hub-insp-callout" role="region" aria-labelledby="clarification-title">
                    <h3 id="clarification-title">Latest message from Logistics</h3>
                    <p>{{ $conversation['clarification']['body'] }}</p>
                    <small>{{ $conversation['clarification']['at']->format('M j, Y g:i A') }}</small>
                    <a class="hub-insp-finding__link" href="#messages">Reply in messages</a>
                </div>
            @endif

            <dl class="hub-insp-facts">
                <div><dt>Submitted (Eastern)</dt><dd>{{ $detail['submitted_at']?->format('M j, Y g:i A') ?? '—' }}</dd></div>
                <div><dt>Inspector</dt><dd>{{ trim($detail['inspector_rank'].' '.$detail['inspector_name']) ?: '—' }}</dd></div>
                <div><dt>Shift</dt><dd>{{ $detail['shift'] ? $detail['shift'].' Shift' : '—' }}</dd></div>
                <div><dt>Processing</dt><dd>{{ $detail['processing_label'] }}</dd></div>
                <div><dt>Engine hours</dt><dd>{{ $detail['engine_hours'] ?? 'Not reported' }}</dd></div>
                <div><dt>Miles</dt><dd>{{ $detail['miles'] !== null ? number_format($detail['miles']) : 'Not reported' }}</dd></div>
                <div><dt>Checklist version</dt><dd style="font-family: var(--hub-font-mono);">{{ $detail['checklist_version_short'] ?? '—' }}</dd></div>
                <div><dt>Review</dt>
                    <dd>
                        {{ $detail['review_label'] }}
                        @if($detail['reviewed_at'])
                            <small>Reviewed {{ $detail['reviewed_at']->format('M j, Y g:i A') }}</small>
                        @elseif($detail['review_status'] === 'approved' && $detail['automatically_accepted'])
                            <small>Accepted automatically on submission</small>
                        @elseif($detail['review_status'] === 'pending_review')
                            <small>Waiting for an officer to review</small>
                        @endif
                    </dd>
                </div>
            </dl>

            <ul class="hub-insp-stats" aria-label="Checklist totals">
                <li class="hub-insp-stat hub-insp-stat--pass"><span class="hub-insp-stat__value">{{ $detail['totals']['present'] }}</span> Pass</li>
                <li class="hub-insp-stat hub-insp-stat--missing"><span class="hub-insp-stat__value">{{ $detail['totals']['missing'] }}</span> Missing</li>
                <li class="hub-insp-stat hub-insp-stat--damaged"><span class="hub-insp-stat__value">{{ $detail['totals']['damaged'] }}</span> Damaged</li>
            </ul>
        </div>
    </x-filament::section>

    <x-filament::section heading="Findings and follow-up">
        <div class="hub-insp">
            @if(count($detail['findings']) === 0 && count($detail['unmatched_defects']) === 0)
                <p class="hub-insp-note">No missing or damaged items were reported on this inspection.</p>
            @else
                <p class="hub-insp-note">
                    A reported defect, a service ticket and a completed repair are separate steps. Status shown is current as of now.
                </p>
                <ul class="hub-insp-findings">
                    @foreach(array_merge($detail['findings'], array_map(fn ($row) => $row + ['is_finding' => true, 'notes' => null, 'photo_key' => null], $detail['unmatched_defects'])) as $finding)
                        @php($defectId = $finding['follow_up']['defect_id'] ?? null)
                        <li class="hub-insp-finding" @if($defectId) id="issue-{{ $defectId }}" @endif>
                            <div class="hub-insp-finding__head">
                                <div>
                                    <p class="hub-insp-finding__name">{{ $finding['name'] }}</p>
                                    <p class="hub-insp-finding__where">{{ $finding['compartment'] }}</p>
                                </div>
                                <span class="hub-insp-badge hub-insp-badge--{{ $statusTone($finding['status']) }}">{{ $finding['status'] }}</span>
                            </div>
                            @if(filled($finding['notes'] ?? null))
                                <p class="hub-insp-finding__notes">{{ $finding['notes'] }}</p>
                            @endif
                            @php($followUp = $finding['follow_up'])
                            <p class="hub-insp-finding__follow">
                                @if($followUp['linked'])
                                    <strong>Current status:</strong> {{ $followUp['action_label'] }}
                                    @if($followUp['first_reported_at'])
                                        · First reported {{ $followUp['first_reported_at']->format('M j, Y') }}
                                    @endif
                                @else
                                    <strong>Defect status:</strong> {{ $followUp['stage'] }}
                                @endif
                                @if($followUp['ticket_number'])
                                    · <strong>Service ticket</strong> {{ $followUp['ticket_number'] }} ({{ $followUp['ticket_status'] }})
                                @endif
                                @if($followUp['repair_completed'])
                                    · <span class="hub-insp-finding__done">Repair completed</span>
                                @endif
                            </p>
                            @if($followUp['resolved_at'])
                                <p class="hub-insp-finding__follow">
                                    <span class="hub-insp-finding__done">Resolved on {{ $followUp['resolved_at']->format('M j, Y') }}.</span>
                                    What you recorded on this inspection is unchanged.
                                </p>
                            @endif
                            @if($defectId && isset($conversation['issues'][$defectId]))
                                @include('filament.employee.pages.partials.inspection-thread', [
                                    'messages' => $conversation['issues'][$defectId]['messages'],
                                    'canPost' => $conversation['issues'][$defectId]['can_post'],
                                    'heading' => 'Messages about '.$finding['name'],
                                    'emptyText' => 'No messages about this issue yet.',
                                    'fieldId' => 'issue-reply-'.$defectId,
                                    'model' => 'issueReplies.'.$defectId,
                                    'action' => 'sendIssueReply('.$defectId.')',
                                    'label' => 'Reply about '.$finding['name'],
                                ])
                            @endif
                            @if(! empty($finding['photo_key']))
                                <div class="hub-insp-photos">
                                    <a href="{{ $imageUrl($finding['photo_key']) }}" target="_blank" rel="noopener" class="hub-insp-photo" aria-label="Open photo of {{ $finding['name'] }} in a new tab">
                                        <img src="{{ $imageUrl($finding['photo_key']) }}" alt="Photo of {{ $finding['name'] }}" loading="lazy">
                                    </a>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section heading="Messages about this inspection" id="messages">
        <div class="hub-insp">
            @include('filament.employee.pages.partials.inspection-thread', [
                'messages' => $conversation['inspection']['messages'],
                'canPost' => $conversation['inspection']['can_post'],
                'heading' => 'Messages about this inspection',
                'emptyText' => 'No messages yet. Logistics writes here if they need more information.',
                'fieldId' => 'inspection-reply',
                'model' => 'inspectionReply',
                'action' => 'sendInspectionReply',
                'label' => 'Reply to Logistics',
            ])
        </div>
    </x-filament::section>

    @if(count($detail['paper_fields']) > 0)
        <x-filament::section heading="Recorded checkout fields">
            <div class="hub-insp">
                <dl class="hub-insp-fields">
                    @foreach($detail['paper_fields'] as $field)
                        <div><dt>{{ $field['name'] }}</dt><dd>{{ $field['value'] }}</dd></div>
                    @endforeach
                </dl>
            </div>
        </x-filament::section>
    @endif

    @forelse($detail['compartments'] as $compartment)
        <x-filament::section :heading="$compartment['name']" collapsible>
            <div class="hub-insp-table-wrap" tabindex="0" role="region" aria-label="{{ $compartment['name'] }} checklist results">
                <table class="hub-insp-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Status</th>
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
                                <td><span class="hub-insp-badge hub-insp-badge--{{ $statusTone($item['status']) }}">{{ $statusText($item['status']) }}</span></td>
                                <td class="is-muted">
                                    {{ $item['notes'] ?? '—' }}
                                    @if($item['photo_key'])
                                        <a href="{{ $imageUrl($item['photo_key']) }}" target="_blank" rel="noopener" class="hub-insp-table-link">View photo</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="hub-insp-note">No detailed checklist results were recorded for this inspection.</p>
        </x-filament::section>
    @endforelse

    @if($detail['signature_key'])
        <x-filament::section heading="Inspector signature">
            <a href="{{ $imageUrl($detail['signature_key']) }}" target="_blank" rel="noopener" class="hub-insp-signature" aria-label="Open inspector signature in a new tab">
                <img src="{{ $imageUrl($detail['signature_key']) }}" alt="Inspector signature" loading="lazy">
            </a>
        </x-filament::section>
    @endif
</x-filament-panels::page>
