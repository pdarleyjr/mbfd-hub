<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $detail['reference'] }} - Daily Vehicle Inspection</title>
    <style>
        @page { margin: 90px 36px 54px 36px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.4; color: #1f2937; }
        .page-header { position: fixed; top: -78px; left: 0; right: 0; height: 66px; background: #14284b; color: #ffffff; padding: 8px 12px; }
        .page-header table { width: 100%; }
        .page-header td { vertical-align: middle; border: 0; padding: 0; }
        .page-header .logo { width: 52px; }
        .page-header img { height: 48px; width: 48px; }
        .page-header .dept { font-size: 14px; font-weight: bold; }
        .page-header .sub { font-size: 9px; color: #cbd5e1; }
        .page-header .ref { text-align: right; font-size: 9px; }
        .page-footer { position: fixed; bottom: -36px; left: 0; right: 0; height: 24px; border-top: 1px solid #cbd5e1; padding-top: 4px; font-size: 8px; color: #64748b; }
        .page-footer table { width: 100%; }
        .page-footer td { border: 0; padding: 0; }
        .page-footer .right { text-align: right; }
        .pagenum:before { content: counter(page); }
        h2 { font-size: 11px; color: #14284b; margin: 14px 0 5px 0; padding: 3px 6px; background: #e8edf5; border-left: 3px solid #14284b; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #f1f5f9; text-align: left; padding: 4px 6px; border-bottom: 1px solid #cbd5e1; font-size: 8px; text-transform: uppercase; color: #475569; }
        table.grid td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.meta { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 3px 6px; border: 1px solid #e5e7eb; vertical-align: top; width: 25%; }
        .label { color: #64748b; font-size: 8px; display: block; }
        .value { font-weight: bold; }
        .badge { padding: 1px 6px; border-radius: 8px; font-size: 8px; font-weight: bold; }
        .pass { background: #d1fae5; color: #047857; }
        .missing { background: #fef3c7; color: #92400e; }
        .damaged { background: #fee2e2; color: #b91c1c; }
        .neutral { background: #e5e7eb; color: #374151; }
        tr.finding td { background: #fffbeb; }
        .muted { color: #64748b; }
        .photo { max-width: 200px; max-height: 140px; margin-top: 4px; border: 1px solid #cbd5e1; }
        .avoid-break { page-break-inside: avoid; }
        .summary td { text-align: center; padding: 6px; border: 1px solid #e5e7eb; }
        .summary .num { font-size: 16px; font-weight: bold; }
    </style>
</head>
<body>
@php
    $badge = fn (string $status): string => match ($status) {
        'Present' => 'pass',
        'Missing' => 'missing',
        'Damaged' => 'damaged',
        default => 'neutral',
    };
    $statusText = fn (string $status): string => $status === 'Present' ? 'PASS' : strtoupper($status);
@endphp
    <div class="page-header">
        <table>
            <tr>
                @if($logo)<td class="logo"><img src="{{ $logo }}" alt="MBFD"></td>@endif
                <td>
                    <div class="dept">Miami Beach Fire Department</div>
                    <div class="sub">Daily Vehicle Inspection Report</div>
                </td>
                <td class="ref">
                    <div>{{ $detail['reference'] }}</div>
                    <div class="sub">{{ $detail['apparatus_label'] }}</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="page-footer">
        <table>
            <tr>
                <td>{{ $detail['reference'] }} &middot; Generated {{ $generatedAt->format('M j, Y g:i A') }} Eastern &middot; Record of the submitted inspection</td>
                <td class="right">Page <span class="pagenum"></span></td>
            </tr>
        </table>
    </div>

    @if($detail['inspection']->test_excluded_at)
        <p><strong>Excluded test — retained for audit.</strong> This submission does not count toward operational inspections.</p>
    @endif
    <h2>Inspection summary</h2>
    <table class="meta">
        <tr>
            <td><span class="label">Apparatus</span><span class="value">{{ $detail['apparatus_label'] }}</span></td>
            <td><span class="label">Vehicle #</span><span class="value">{{ $detail['vehicle_number'] ?? '—' }}</span></td>
            <td><span class="label">Station</span><span class="value">{{ $detail['station_label'] ?? '—' }}</span></td>
            <td><span class="label">Reference</span><span class="value">{{ $detail['reference'] }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Submitted (Eastern)</span><span class="value">{{ $detail['submitted_at']?->format('M j, Y g:i A') ?? '—' }}</span></td>
            <td><span class="label">Inspector</span><span class="value">{{ trim($detail['inspector_rank'].' '.$detail['inspector_name']) ?: '—' }}</span></td>
            <td><span class="label">Shift</span><span class="value">{{ $detail['shift'] ? $detail['shift'].' Shift' : '—' }}</span></td>
            <td><span class="label">Checklist version</span><span class="value">{{ $detail['checklist_version_short'] ?? '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Engine hours</span><span class="value">{{ $detail['engine_hours'] ?? 'Not reported' }}</span></td>
            <td><span class="label">Miles</span><span class="value">{{ $detail['miles'] !== null ? number_format($detail['miles']) : 'Not reported' }}</span></td>
            <td><span class="label">Review status</span><span class="value">{{ $detail['review_label'] }}</span></td>
            <td><span class="label">Processing</span><span class="value">{{ $detail['processing_label'] }}</span></td>
        </tr>
        <tr>
            <td colspan="4">
                <span class="label">Reviewed</span>
                <span class="value">
                    @if($detail['reviewed_at'])
                        {{ $detail['reviewed_at']->format('M j, Y g:i A') }} Eastern
                    @elseif($detail['review_status'] === 'approved' && $detail['automatically_accepted'])
                        Accepted automatically on submission
                    @elseif($detail['review_status'] === 'pending_review')
                        Waiting for an officer to review
                    @else
                        —
                    @endif
                </span>
            </td>
        </tr>
    </table>

    <table class="summary" style="width:100%; border-collapse: collapse; margin-top: 8px;">
        <tr>
            <td><div class="num">{{ $detail['totals']['items'] }}</div>Items checked</td>
            <td><div class="num" style="color:#047857;">{{ $detail['totals']['present'] }}</div>Pass</td>
            <td><div class="num" style="color:#92400e;">{{ $detail['totals']['missing'] }}</div>Missing</td>
            <td><div class="num" style="color:#b91c1c;">{{ $detail['totals']['damaged'] }}</div>Damaged</td>
        </tr>
    </table>

    <h2>Findings and follow-up</h2>
    @php($allFindings = array_merge($detail['findings'], array_map(fn ($row) => $row + ['notes' => null, 'photo_key' => null], $detail['unmatched_defects'])))
    @if(count($allFindings) === 0)
        <p class="muted">No missing or damaged items were reported on this inspection.</p>
    @else
        <p class="muted" style="margin-bottom:4px;">A reported defect, a service ticket and a completed repair are separate steps. Follow-up is current as of {{ $generatedAt->format('M j, Y g:i A') }} Eastern.</p>
        <table class="grid">
            <thead><tr><th>Compartment / item</th><th>Finding</th><th>Notes</th><th>Follow-up</th></tr></thead>
            <tbody>
            @foreach($allFindings as $finding)
                <tr class="finding avoid-break">
                    <td><strong>{{ $finding['name'] }}</strong><br><span class="muted">{{ $finding['compartment'] }}</span></td>
                    <td><span class="badge {{ $badge($finding['status']) }}">{{ $statusText($finding['status']) }}</span></td>
                    <td>
                        {{ $finding['notes'] ?? '—' }}
                        @if(! empty($finding['photo_key']) && isset($images[$finding['photo_key']]))
                            <br><img class="photo" src="{{ $images[$finding['photo_key']] }}" alt="Photo">
                        @endif
                    </td>
                    <td>
                        {{ $finding['follow_up']['stage'] }}
                        @if($finding['follow_up']['ticket_number'])
                            <br>Ticket {{ $finding['follow_up']['ticket_number'] }}: {{ $finding['follow_up']['ticket_status'] }}
                        @endif
                        @if($finding['follow_up']['repair_completed'])
                            <br><strong>Repair completed</strong>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if(count($detail['paper_fields']) > 0)
        <h2>Recorded checkout fields</h2>
        <table class="grid">
            <tbody>
            @foreach($detail['paper_fields'] as $field)
                <tr><td style="width:40%;" class="muted">{{ $field['name'] }}</td><td><strong>{{ $field['value'] }}</strong></td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @forelse($detail['compartments'] as $compartment)
        {{-- The section heading travels with the first compartment table, and no compartment table is split
             across pages, so the heading and table header are never stranded at the bottom of a page. --}}
        <div class="avoid-break">
        @if($loop->first)<h2>Checklist results</h2>@endif
        <table class="grid avoid-break" style="margin-bottom:8px;">
            <thead>
                <tr><th colspan="3" style="background:#dbe4f0; color:#14284b; font-size:9px;">{{ $compartment['name'] }}</th></tr>
                <tr><th style="width:44%;">Item</th><th style="width:14%;">Status</th><th>Notes</th></tr>
            </thead>
            <tbody>
            @foreach($compartment['items'] as $item)
                <tr class="{{ $item['is_finding'] ? 'finding' : '' }}">
                    <td>
                        {{ $item['name'] }}
                        @if($item['value'])<br><span class="muted">{{ $item['value'] }}</span>@endif
                    </td>
                    <td><span class="badge {{ $badge($item['status']) }}">{{ $statusText($item['status']) }}</span></td>
                    <td>{{ $item['notes'] ?? '—' }}
                        @if($item['photo_key'] && ! isset($images[$item['photo_key']]))
                            <br><span class="muted">Photo on file (not embedded in this copy)</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @empty
        <h2>Checklist results</h2>
        <p class="muted">No detailed checklist results were recorded for this inspection.</p>
    @endforelse

    @if($detail['signature_key'])
        <h2>Inspector signature</h2>
        @if(isset($images['signature']))
            <img src="{{ $images['signature'] }}" alt="Inspector signature" style="max-height:70px; max-width:240px; border:1px solid #cbd5e1; background:#fff;">
        @else
            <p class="muted">A signature is on file but could not be embedded in this copy.</p>
        @endif
        <p class="muted">{{ trim($detail['inspector_rank'].' '.$detail['inspector_name']) }}</p>
    @endif

    @if($omittedImages > 0)
        <p class="muted" style="margin-top:8px;">{{ $omittedImages }} image(s) on file were not embedded (missing or too large for this copy).</p>
    @endif
</body>
</html>
