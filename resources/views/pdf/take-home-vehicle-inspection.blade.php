<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $inspection->reference }} - Take Home Vehicle Inspection</title>
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
        table.grid td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .label { color: #64748b; font-size: 7.5px; display: block; text-transform: uppercase; }
        .value { font-weight: bold; }
        .badge { padding: 1px 6px; border-radius: 8px; font-size: 8px; font-weight: bold; }
        .good { background: #d1fae5; color: #047857; }
        .issue { background: #fee2e2; color: #b91c1c; }
        .unchecked { background: #fef3c7; color: #92400e; }
        .callout { border: 1px solid #fca5a5; background: #fef2f2; color: #991b1b; padding: 6px 8px; margin: 8px 0; font-weight: bold; }
        .callout.ok { border-color: #a7f3d0; background: #ecfdf5; color: #065f46; }
        .muted { color: #64748b; }
        .photos { width: 100%; border-collapse: collapse; }
        .photos td { width: 50%; padding: 6px; vertical-align: top; text-align: center; }
        .photos .frame { border: 1px solid #cbd5e1; padding: 4px; page-break-inside: avoid; }
        .photos img { max-width: 255px; max-height: 210px; }
        .photos .cap { font-weight: bold; margin-top: 3px; }
        .missing { color: #b91c1c; font-weight: bold; padding: 40px 0; }
        .signature img { max-height: 70px; max-width: 260px; border-bottom: 1px solid #94a3b8; }
        .avoid-break { page-break-inside: avoid; }
        .pre { white-space: pre-wrap; }
    </style>
</head>
<body>
@php
    $statusClass = fn (?string $status): string => match ($status) { 'good' => 'good', 'issue' => 'issue', default => 'unchecked' };
    $statusText = fn (?string $status): string => \App\Models\TakeHomeVehicleInspection::statusLabel($status);
    $yesNo = fn (?bool $value): string => \App\Models\TakeHomeVehicleInspection::answerLabel($value);
    $field = fn (?string $value): string => filled($value) ? $value : 'Not recorded';
@endphp
<div class="page-header">
    <table>
        <tr>
            @if($logo)<td class="logo"><img src="{{ $logo }}" alt="MBFD"></td>@endif
            <td>
                <div class="dept">Miami Beach Fire Department</div>
                <div class="sub">Fleet · Support Services Division</div>
            </td>
            <td class="ref">
                <div>{{ $inspection->reference }}</div>
                <div class="sub">Vehicle #{{ $snapshot['veh_number'] ?? 'not recorded' }}</div>
            </td>
        </tr>
    </table>
</div>
<div class="page-footer">
    {{ $inspection->reference }} · Generated {{ $generatedAt->format('M j, Y g:i A T') }} · Record of the submitted inspection. Not a mechanic's safety certification.
</div>

<h1>Take Home Vehicle Inspection</h1>
<div class="muted">Submitted {{ $inspection->submittedAtLocal() }}@if($inspection->started_at) · Started {{ $inspection->started_at->clone()->timezone('America/New_York')->format('g:i A T') }}@endif</div>

@if($inspection->acceptable_for_use === null)
    <div class="callout">Acceptability for use was not answered.</div>
@elseif(! $inspection->acceptable_for_use)
    <div class="callout">Vehicle reported NOT acceptable for use.</div>
@elseif($inspection->has_issues || $inspection->has_not_checked)
    <div class="callout">Reported acceptable for use, with {{ $inspection->has_issues ? 'issues found' : '' }}{{ $inspection->has_issues && $inspection->has_not_checked ? ' and ' : '' }}{{ $inspection->has_not_checked ? 'items not checked' : '' }}.</div>
@else
    <div class="callout ok">All six checks reported Good; vehicle reported acceptable for use.</div>
@endif

<h2>Vehicle (as recorded at signing)</h2>
<table class="meta">
    <tr>
        <td><span class="label">Vehicle / Fleet #</span><span class="value">{{ $field($snapshot['veh_number'] ?? null) }}</span></td>
        <td><span class="label">Year · Make · Model</span><span class="value">{{ $field(trim(($snapshot['year'] ?? '').' '.($snapshot['make'] ?? '').' '.($snapshot['model'] ?? ''))) }}</span></td>
        <td><span class="label">Color</span><span class="value">{{ $field($snapshot['color'] ?? null) }}</span></td>
        <td><span class="label">Category</span><span class="value">{{ $field($snapshot['category_label'] ?? null) }}</span></td>
    </tr>
    <tr>
        <td><span class="label">License plate / Tag</span><span class="value">{{ $field($snapshot['tag_number'] ?? null) }}</span></td>
        <td colspan="2"><span class="label">VIN / Serial</span><span class="value">{{ $field($snapshot['serial_number'] ?? null) }}</span></td>
        <td><span class="label">Department</span><span class="value">{{ $field($snapshot['dept_code'] ?? null) }}</span></td>
    </tr>
    <tr>
        <td colspan="2"><span class="label">Assigned driver</span><span class="value">{{ $inspection->driverLabel() }}</span>
            <span class="muted">{{ $inspection->driver_employee_number ? '· Employee ID '.$inspection->driver_employee_number : '' }} · {{ $inspection->driver_verified ? 'Verified personnel record' : 'Entered manually (not verified)' }}</span></td>
        <td colspan="2"><span class="label">Inspector (signed-in account)</span><span class="value">{{ $inspection->inspector_name }}</span></td>
    </tr>
    <tr>
        <td colspan="2"><span class="label">Odometer</span><span class="value">
            @if($inspection->odometer !== null){{ number_format($inspection->odometer) }} mi @else {{ \App\Models\TakeHomeVehicleInspection::ODOMETER_EXCEPTIONS[$inspection->odometer_exception] ?? 'Not recorded' }} @endif
        </span>@if($inspection->odometer_flagged)<div class="muted">Flagged: {{ $inspection->odometer_note }}</div>@endif</td>
        <td><span class="label">Keys present</span><span class="value">{{ $yesNo($inspection->keys_present) }}</span></td>
        <td><span class="label">Acceptable for use</span><span class="value">{{ $yesNo($inspection->acceptable_for_use) }}</span></td>
    </tr>
</table>

<h2>Condition checks</h2>
<table class="grid">
    <thead><tr><th style="width: 28%">Check</th><th style="width: 16%">Result</th><th>Findings</th></tr></thead>
    <tbody>
    @foreach($inspection->checks as $check)
        <tr class="avoid-break">
            <td><strong>{{ $check['label'] }}</strong></td>
            <td><span class="badge {{ $statusClass($check['status']) }}">{{ $statusText($check['status']) }}</span></td>
            <td>
                @if($check['issue_description'])<div class="pre">{{ $check['issue_description'] }}</div>@endif
                @if($check['notes'])<div class="muted pre">Notes: {{ $check['notes'] }}</div>@endif
                @if($check['status'] === 'not_checked')<div class="muted">Not checked. No condition reported.</div>@elseif($check['status'] === null)<div class="muted">Not answered. No condition reported.</div>@endif
                @if(! $check['issue_description'] && ! $check['notes'] && $check['status'] === 'good')<span class="muted">No findings.</span>@endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Comments</h2>
<div class="pre">{{ $inspection->comments ?: 'None.' }}</div>

<div class="avoid-break">
    <h2>Acknowledgment and signature</h2>
    <p>I confirm that I inspected this vehicle and that this report is accurate to the best of my knowledge. This report does not certify mechanical safety.</p>
    <div class="signature">
        @if($signature)<img src="{{ $signature }}" alt="Signature">@else<span class="missing">Signature image unavailable</span>@endif
    </div>
    <div><strong>{{ $inspection->inspector_name }}</strong> · Signed {{ $inspection->acknowledged_at->clone()->timezone('America/New_York')->format('M j, Y g:i A T') }}</div>
    <div class="muted">Signature SHA-256 {{ $inspection->signature_sha256 }}</div>
</div>

<div style="page-break-before: always;"></div>
<h2>Standard photos</h2>
<table class="photos">
    @foreach(array_chunk($standardPhotos, 2) as $row)
        <tr>
            @foreach($row as $photo)
                <td><div class="frame">
                    @if($photo['src'])<img src="{{ $photo['src'] }}" alt="{{ $photo['label'] }}">@elseif($photo['provided'] ?? true)<div class="missing">Photo could not be loaded</div>@else<div class="muted" style="padding: 40px 0;">Not provided</div>@endif
                    <div class="cap">{{ $photo['label'] }}</div>
                </div></td>
            @endforeach
        </tr>
    @endforeach
</table>

@if(count($issuePhotos) > 0)
    <h2>Issue photos</h2>
    <table class="photos">
        @foreach(array_chunk($issuePhotos, 2) as $row)
            <tr>
                @foreach($row as $photo)
                    <td><div class="frame">
                        @if($photo['src'])<img src="{{ $photo['src'] }}" alt="{{ $photo['label'] }}">@elseif($photo['provided'] ?? true)<div class="missing">Photo could not be loaded</div>@else<div class="muted" style="padding: 40px 0;">Not provided</div>@endif
                        <div class="cap">{{ $photo['label'] }}</div>
                    </div></td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endif
</body>
</html>
