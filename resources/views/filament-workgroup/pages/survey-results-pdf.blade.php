<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $survey->title }}</title><style>body{font-family:DejaVu Sans,sans-serif;color:#111827;font-size:11px}h1{font-size:20px}h2{font-size:14px;margin-top:22px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #d1d5db;padding:6px;text-align:left;vertical-align:top}.muted{color:#4b5563}.metrics{white-space:pre-wrap;font-family:monospace;font-size:9px}</style></head>
<body>
    <h1>{{ $survey->title }}</h1>
    <p class="muted">{{ $survey->workgroup->name }} · Revision {{ $analytics['revision'] }} · Generated {{ $analytics['generated_at'] }}</p>
    <h2>Summary</h2>
    <table><tbody>@foreach($analytics['summary'] as $label => $value)<tr><th>{{ str_replace('_', ' ', $label) }}</th><td>{{ $value ?? '—' }}@if($label === 'response_rate' && $value !== null)%@endif</td></tr>@endforeach</tbody></table>
    @foreach($analytics['questions'] as $question)
        <h2>{{ $question['position'] }}. {{ $question['prompt'] }}</h2>
        <div class="metrics">{{ json_encode($question['metrics'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
    @endforeach
    <p class="muted">This report contains aggregate de-identified analytics only. Suppressed small cells are not included.</p>
</body>
</html>
