<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $survey->title }}</title>
    <style>
        @page { margin: 0.55in 0.5in 0.65in; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; }
        h1 { margin: 0 0 5px; font-size: 20px; line-height: 1.2; }
        h2 { margin: 20px 0 8px; font-size: 14px; }
        h3, h4 { margin: 12px 0 7px; line-height: 1.35; }
        h3 { font-size: 12px; }
        h4 { font-size: 11.5px; }
        table { width: 100%; margin-top: 7px; border-collapse: collapse; }
        th, td { padding: 6px; border: 1px solid #d1d5db; text-align: left; vertical-align: top; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; break-inside: avoid; }
        .report-header { margin: 0 0 14px; page-break-inside: avoid; break-inside: avoid; }
        .report-header td { padding: 0; border: 0; vertical-align: middle; }
        .report-header .logo-cell { width: 64px; padding-right: 12px; }
        .report-logo { display: block; width: 54px; height: 54px; }
        .report-subtitle, .muted { color: #4b5563; }
        .report-subtitle { margin: 0; }
        .section-title, .question-title, .result-title { page-break-after: avoid; break-after: avoid; }
        .result-block, .summary-block { page-break-inside: avoid; break-inside: avoid; }
        .result-block { margin-bottom: 12px; }
        .result-block .section-title { margin-top: 20px; }
        .result-block .question-title { margin-top: 0; }
        .result-block .section-title + .question-title { margin-top: 8px; }
        .response-table col.response { width: 74%; }
        .response-table col.number { width: 13%; }
        .metric { margin: 4px 0 0; }
        .privacy-note { margin: 14px 0 0; }
    </style>
</head>
<body>
    @php($logoData = base64_encode(file_get_contents(resource_path('images/mbfd-logo.png'))))
    <table class="report-header">
        <tbody>
            <tr>
                <td class="logo-cell">
                    <img class="report-logo" src="data:image/png;base64,{{ $logoData }}" alt="Miami Beach Fire Department">
                </td>
                <td>
                    <h1>{{ $survey->title }}</h1>
                    <p class="report-subtitle">{{ $survey->workgroup->name }} · Revision {{ $analytics['revision'] }} · Generated {{ $analytics['generated_at'] }}</p>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="summary-block">
        <h2 class="section-title">Summary</h2>
        <table>
            <tbody>
                @foreach($analytics['summary'] as $label => $value)
                    <tr>
                        <th>{{ str_replace('_', ' ', $label) }}</th>
                        <td>{{ $value ?? '—' }}@if($label === 'response_rate' && $value !== null)%@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @foreach($analytics['questions'] as $question)
        @php($startsSection = $loop->first || $analytics['questions'][$loop->index - 1]['section'] !== $question['section'])

        @if(isset($question['metrics']['distribution']))
            <div class="result-block{{ $startsSection ? ' section-start' : '' }}">
                @if($startsSection)
                    <h2 class="section-title">{{ $question['section'] }}</h2>
                @endif
                <h3 class="question-title">{{ $question['position'] }}. {{ $question['prompt'] }}</h3>
                <table class="response-table">
                    <colgroup><col class="response"><col class="number"><col class="number"></colgroup>
                    <thead><tr><th>Response</th><th>Count</th><th>Percent</th></tr></thead>
                    <tbody>
                        @foreach($question['metrics']['distribution'] as $item)
                            <tr><td>{{ $item['label'] }}</td><td>{{ $item['count'] }}</td><td>{{ $item['percentage'] ?? '—' }}@if($item['percentage'] !== null)%@endif</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="metric">Answer N: {{ $question['metrics']['response_n'] }} · Scored N: {{ $question['metrics']['scored_n'] }}@if($question['metrics']['scored_n'] > 0) · Mean: {{ $question['metrics']['mean'] }} · Favorable: {{ $question['metrics']['favorable_percentage'] }}%@endif</p>
            </div>
        @elseif(isset($question['metrics']['items']))
            <div class="result-block{{ $startsSection ? ' section-start' : '' }}">
                @if($startsSection)
                    <h2 class="section-title">{{ $question['section'] }}</h2>
                @endif
                <h3 class="question-title">{{ $question['position'] }}. {{ $question['prompt'] }}</h3>
                <table class="response-table">
                    <colgroup><col class="response"><col class="number"><col class="number"></colgroup>
                    <thead><tr><th>Response</th><th>Count</th><th>Percent</th></tr></thead>
                    <tbody>
                        @foreach($question['metrics']['items'] as $item)
                            <tr><td>{{ $item['label'] }}</td><td>{{ $item['count'] }}</td><td>{{ $item['percentage'] ?? '—' }}@if($item['percentage'] !== null)%@endif</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif(isset($question['metrics']['rows']))
            @foreach($question['metrics']['rows'] as $row)
                <div class="result-block{{ $loop->first && $startsSection ? ' section-start' : '' }}">
                    @if($loop->first)
                        @if($startsSection)
                            <h2 class="section-title">{{ $question['section'] }}</h2>
                        @endif
                        <h3 class="question-title">{{ $question['position'] }}. {{ $question['prompt'] }}</h3>
                    @endif
                    <h4 class="result-title">{{ $row['label'] }}</h4>
                    <table class="response-table">
                        <colgroup><col class="response"><col class="number"><col class="number"></colgroup>
                        <thead><tr><th>Response</th><th>Count</th><th>Percent</th></tr></thead>
                        <tbody>
                            @foreach($row['metrics']['distribution'] as $item)
                                <tr><td>{{ $item['label'] }}</td><td>{{ $item['count'] }}</td><td>{{ $item['percentage'] ?? '—' }}@if($item['percentage'] !== null)%@endif</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @elseif(isset($question['metrics']['parts']))
            @foreach($question['metrics']['parts'] as $part)
                <div class="result-block{{ $loop->first && $startsSection ? ' section-start' : '' }}">
                    @if($loop->first)
                        @if($startsSection)
                            <h2 class="section-title">{{ $question['section'] }}</h2>
                        @endif
                        <h3 class="question-title">{{ $question['position'] }}. {{ $question['prompt'] }}</h3>
                    @endif
                    <h4 class="result-title">{{ $part['label'] }}</h4>
                    <table class="response-table">
                        <colgroup><col class="response"><col class="number"><col class="number"></colgroup>
                        <thead><tr><th>Response</th><th>Count</th><th>Percent</th></tr></thead>
                        <tbody>
                            @foreach($part['metrics']['distribution'] as $item)
                                <tr><td>{{ $item['label'] }}</td><td>{{ $item['count'] }}</td><td>{{ $item['percentage'] ?? '—' }}@if($item['percentage'] !== null)%@endif</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @endif
    @endforeach

    <p class="muted privacy-note">This report contains aggregate de-identified analytics only. Suppressed small cells are not included.</p>
</body>
</html>
