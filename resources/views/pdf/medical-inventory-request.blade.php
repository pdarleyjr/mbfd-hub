<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $number }} - Medical Inventory Request</title>
    <style>
        @page { margin: 92px 36px 56px 36px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.45; color: #1e293b; }
        .page-header { position: fixed; top: -80px; left: 0; right: 0; height: 66px; background: #012766; color: #ffffff; padding: 8px 12px; }
        .page-header table { width: 100%; }
        .page-header td { vertical-align: middle; border: 0; padding: 0; }
        .page-header .logo { width: 56px; }
        .page-header img { height: 50px; width: 50px; }
        .page-header .dept { font-size: 14px; font-weight: bold; }
        .page-header .sub { font-size: 9px; color: #cbd5e1; }
        .page-header .ref { text-align: right; font-size: 10px; font-weight: bold; }
        .page-footer { position: fixed; bottom: -38px; left: 0; right: 0; height: 24px; border-top: 1px solid #cbd5e1; padding-top: 4px; font-size: 7.5px; color: #64748b; }
        h1 { font-size: 15px; color: #012766; margin: 0 0 2px 0; }
        h2 { font-size: 11px; color: #012766; margin: 14px 0 6px 0; padding: 3px 6px; background: #e8edf5; }
        table.meta { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 4px 6px; border: 1px solid #e2e8f0; vertical-align: top; width: 25%; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #f1f5f9; text-align: left; padding: 4px 6px; border-bottom: 1px solid #cbd5e1; font-size: 8px; text-transform: uppercase; color: #475569; }
        table.grid td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table.grid td.qty { text-align: right; font-weight: bold; width: 70px; }
        .label { color: #64748b; font-size: 7.5px; display: block; text-transform: uppercase; }
        .value { font-weight: bold; }
        .muted { color: #64748b; }
        .tag { font-size: 7.5px; color: #92400e; }
        .note { border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 8px; margin: 8px 0; white-space: pre-wrap; }
        .avoid-break { page-break-inside: avoid; }
    </style>
</head>
<body>
<div class="page-header">
    <table>
        <tr>
            @if($logo)<td class="logo"><img src="{{ $logo }}" alt="MBFD"></td>@endif
            <td>
                <div class="dept">Miami Beach Fire Department</div>
                <div class="sub">EMS Division - Medical Inventory Request</div>
            </td>
            <td class="ref">
                <div>{{ $number }}</div>
                <div class="sub">{{ $targetLabel }}</div>
            </td>
        </tr>
    </table>
</div>
<div class="page-footer">
    {{ $number }} - Record of the request as submitted on {{ $submittedAt }}. Quantities issued are tracked by the EMS supply room, not on this copy. Snapshot {{ substr($snapshotSha, 0, 12) }}.
</div>

<h1>Medical Inventory Request</h1>
<div class="muted">Submitted {{ $submittedAt }}</div>

<h2>Request</h2>
<table class="meta">
    <tr>
        <td><span class="label">Request number</span><span class="value">{{ $number }}</span></td>
        <td><span class="label">Unit</span><span class="value">{{ $targetLabel }}</span></td>
        <td colspan="2"><span class="label">Event or assignment</span><span class="value">{{ filled($eventAssignment) ? $eventAssignment : 'Not given' }}</span></td>
    </tr>
    <tr>
        <td colspan="2"><span class="label">Submitted by</span><span class="value">{{ $submitterName }}@if(filled($submitterRank)) <span class="muted">({{ $submitterRank }})</span>@endif</span></td>
        <td colspan="2"><span class="label">Items requested</span><span class="value">{{ $lineCount }}</span></td>
    </tr>
</table>

@if(filled($memberNote))
    <h2>Note</h2>
    <div class="note">{{ $memberNote }}</div>
@endif

@forelse($bags as $bag)
    <h2>{{ $bag['title'] }}@if(filled($bag['version_label'])) <span class="muted">({{ $bag['version_label'] }})</span>@endif</h2>
    <table class="grid">
        <thead><tr><th>Item</th><th style="width: 90px">Bag minimum</th><th style="width: 70px; text-align: right">Requested</th></tr></thead>
        <tbody>
        @foreach($bag['sections'] as $section)
            <tr class="avoid-break"><td colspan="3"><strong>{{ $section['label'] }}</strong></td></tr>
            @foreach($section['lines'] as $line)
                <tr class="avoid-break">
                    <td>{{ $line['label'] }}@if($line['refrigerated']) <span class="tag">Refrigerated</span>@endif</td>
                    <td class="muted">{{ filled($line['min_text']) ? $line['min_text'] : '' }}</td>
                    <td class="qty">{{ $line['answer'] }}</td>
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
@empty
    <h2>Items</h2>
    <div class="muted">No items were requested. See the note.</div>
@endforelse
</body>
</html>
