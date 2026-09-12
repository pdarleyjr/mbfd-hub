<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $survey->title }}</title><style>body{font-family:DejaVu Sans,sans-serif;color:#111827;font-size:11px}h1{font-size:20px}h2{font-size:14px;margin-top:22px}table{border-collapse:collapse;width:100%;margin-top:8px}th,td{border:1px solid #d1d5db;padding:6px;text-align:left;vertical-align:top}.muted{color:#4b5563}.metric{margin:4px 0}</style></head>
<body>
    <h1>{{ $survey->title }}</h1>
    <p class="muted">{{ $survey->workgroup->name }} · Revision {{ $analytics['revision'] }} · Generated {{ $analytics['generated_at'] }}</p>
    <h2>Summary</h2>
    <table><tbody>@foreach($analytics['summary'] as $label => $value)<tr><th>{{ str_replace('_', ' ', $label) }}</th><td>{{ $value ?? '—' }}@if($label === 'response_rate' && $value !== null)%@endif</td></tr>@endforeach</tbody></table>
    @foreach($analytics['questions'] as $question)
        <h2>{{ $question['position'] }}. {{ $question['prompt'] }}</h2>
        @if(isset($question['metrics']['distribution']))
            <table><thead><tr><th>Response</th><th>Count</th><th>Percent</th></tr></thead><tbody>@foreach($question['metrics']['distribution'] as $item)<tr><td>{{ $item['label'] }}</td><td>{{ $item['count'] }}</td><td>{{ $item['percentage'] ?? '—' }}@if($item['percentage'] !== null)%@endif</td></tr>@endforeach</tbody></table>
            <p class="metric">Answer N: {{ $question['metrics']['response_n'] }} · Scored N: {{ $question['metrics']['scored_n'] }}@if($question['metrics']['scored_n'] > 0) · Mean: {{ $question['metrics']['mean'] }} · Favorable: {{ $question['metrics']['favorable_percentage'] }}%@endif</p>
        @elseif(isset($question['metrics']['items']))
            <table><thead><tr><th>Response</th><th>Count</th><th>Percent</th></tr></thead><tbody>@foreach($question['metrics']['items'] as $item)<tr><td>{{ $item['label'] }}</td><td>{{ $item['count'] }}</td><td>{{ $item['percentage'] ?? '—' }}@if($item['percentage'] !== null)%@endif</td></tr>@endforeach</tbody></table>
        @elseif(isset($question['metrics']['rows']))
            @foreach($question['metrics']['rows'] as $row)<h3>{{ $row['label'] }}</h3><table><thead><tr><th>Response</th><th>Count</th><th>Percent</th></tr></thead><tbody>@foreach($row['metrics']['distribution'] as $item)<tr><td>{{ $item['label'] }}</td><td>{{ $item['count'] }}</td><td>{{ $item['percentage'] ?? '—' }}@if($item['percentage'] !== null)%@endif</td></tr>@endforeach</tbody></table>@endforeach
        @elseif(isset($question['metrics']['parts']))
            @foreach($question['metrics']['parts'] as $part)<h3>{{ $part['label'] }}</h3><table><thead><tr><th>Response</th><th>Count</th><th>Percent</th></tr></thead><tbody>@foreach($part['metrics']['distribution'] as $item)<tr><td>{{ $item['label'] }}</td><td>{{ $item['count'] }}</td><td>{{ $item['percentage'] ?? '—' }}@if($item['percentage'] !== null)%@endif</td></tr>@endforeach</tbody></table>@endforeach
        @endif
    @endforeach
    <p class="muted">This report contains aggregate de-identified analytics only. Suppressed small cells are not included.</p>
</body>
</html>
