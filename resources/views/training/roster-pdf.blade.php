<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MBFD Training Roster</title>
    <style>
        @page { size: letter portrait; margin: 25pt 30pt; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .heading { position: relative; width: 100%; height: 58pt; }
        .logo { position: absolute; left: 0; top: 0; width: 52pt; height: 52pt; }
        h1 { position: absolute; left: 65pt; top: 15pt; width: 487pt; text-align: center; font-family: "DejaVu Serif", serif; font-size: 21pt; text-decoration: underline; margin: 0; line-height: 26pt; white-space: nowrap; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        .classes td { border: .6pt solid #222; padding: 2pt; font-size: 7.3pt; height: 10pt; }
        .custom-classes { font-size: 6.5pt; line-height: 6.5pt; margin-bottom: 0; }
        .custom-classes td { width: 50%; padding: 1pt; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
        .custom-classes caption { font-size: 7pt; line-height: 8pt; }
        .custom-classes div { font-size: 6.5pt; line-height: 6.5pt; margin: 0; }
        .course { background: #ff0; font-weight: bold; margin: 9pt 0 5pt; padding: 3pt 5pt; overflow-wrap: break-word; }
        .metadata { font-size: 8pt; margin: 0 0 7pt; overflow-wrap: break-word; word-wrap: break-word; }
        .roster th, .roster td { border: .6pt solid #222; padding: 2pt 5pt; }
        .roster th { height: 21pt; font-family: "DejaVu Serif", serif; font-size: 10pt; }
        .roster td { height: {{ $row_height }}pt; font-size: 8pt; line-height: 9.6pt; overflow-wrap: break-word; word-wrap: break-word; vertical-align: middle; }
        .number { color: #555; font-size: 7pt; }
        .signature-time { font-size: 6.5pt; line-height: 8pt; white-space: nowrap; }
        .signature { max-width: 165pt; max-height: 17pt; }
        .oic { margin-top: 11pt; font-size: 8pt; }
        .oic td { height: 34pt; vertical-align: middle; }
        .oic .signature { max-width: 150pt; max-height: 28pt; }
        .footer { font-size: 7pt; text-align: center; margin-top: 7pt; }
    </style>
</head>
<body>
@foreach ($pages as $pageIndex => $entries)
    <div class="page">
        <div class="heading"><img class="logo" src="{{ $logo }}" alt="Miami Beach Fire Department"><h1>MBFD TRAINING ROSTER</h1></div>
        @if ($custom_classification_columns !== [] && $custom_classifications_inline === '')
            <table class="custom-classes"><caption style="text-align: left">Additional selected classifications:</caption><tr>
                @foreach ($custom_classification_columns as $labels)
                    <td>@foreach ($labels as $label)<div>[X] {{ $label }}</div>@endforeach</td>
                @endforeach
                @if (count($custom_classification_columns) === 1)<td></td>@endif
            </tr></table>
        @endif
        <table class="classes">
            @foreach (array_chunk($classifications, 4) as $labels)
                <tr>@foreach ($labels as $label)<td>{{ in_array($label, $session['classifications'], true) ? '[X]' : '[ ]' }} {{ $label }}</td>@endforeach</tr>
            @endforeach
        </table>
        <div class="course">COURSE NAME: {{ $session['course_name'] }}</div>
        <p class="metadata">Session: {{ $session['held_on'] }} @if ($session['location'] ?? null) · {{ $session['location'] }} @endif<br>{{ $completion_label }} · Revision {{ $revision }} · {{ count($rows) }} attendees @if ($custom_classifications_inline !== '') · Additional: {{ $custom_classifications_inline }} @endif</p>
        <table class="roster">
            <thead><tr><th style="width: 25%">Last Name</th><th style="width: 25%">First Name</th><th style="width: 13%">DATE</th><th style="width: 37%">SIGNATURE</th></tr></thead>
            <tbody>
            @for ($i = 0; $i < $rows_per_page; $i++)
                @php($entry = $entries[$i] ?? null)
                <tr>
                    <td>@if ($entry)<span class="number">{{ $entry['number'] }}.</span> {{ $entry['snapshot']['last_name'] }}@endif</td>
                    <td>{{ $entry['snapshot']['first_name'] ?? '' }}</td>
                    <td>{{ $entry['date'] ?? '' }}@if ($entry)<br><span class="signature-time">{{ $entry['time'] }}</span>@endif</td>
                    <td>@if ($entry)<img class="signature" src="{{ $entry['signature_url'] }}" alt="Handwritten signature">@endif</td>
                </tr>
            @endfor
            </tbody>
        </table>
        <table class="oic"><tr>
            <td style="width: 52%"><strong>OIC NAME (PRINT):</strong> {{ $certification['oic_snapshot']['display_name'] ?? '____________________' }}<br><strong>ID#</strong> {{ $certification['oic_snapshot']['employee_id'] ?? '________' }}</td>
            <td><strong>SIGNATURE:</strong> @if ($certification)<img class="signature" src="{{ $certification['signature_url'] }}" alt="OIC handwritten signature"><br><span class="signature-time">{{ $certification['signed_at_local'] }}</span>@else ________________ @endif</td>
        </tr></table>
        <div class="footer">{{ $session['public_id'] }} · {{ $session['status'] }} · Page {{ $pageIndex + 1 }} of {{ count($pages) }}<br>Snapshot {{ $captured_at }}@if($certification) · Certified {{ $certification['certified_at'] }}@endif @if($submission)<br>Submitted {{ $submission['submitted_at'] }}@endif</div>
    </div>
@endforeach
</body>
</html>
