@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\AccidentReport> $reports */
    $tz = \App\Support\HubTimezone::NAME;
@endphp
@if ($reports->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No accident reports for this vehicle.</p>
@else
    <div class="hub-insp hub-insp-table-wrap">
        <table data-hub-table class="hub-insp-table" data-accident-history>
            <thead>
                <tr>
                    <th>Accident (ET)</th>
                    <th>Report</th>
                    <th>Employee involved</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reports as $report)
                    <tr class="{{ $report->resolved_at === null ? 'is-finding' : '' }}">
                        <td>{{ $report->occurred_at?->copy()->setTimezone($tz)->format('M j, Y g:i A') ?? 'Not recorded' }}</td>
                        <td>{{ $report->typeLabel() }} {{ $report->reference }}</td>
                        <td>{{ $report->subject?->name ?? 'Not recorded' }}</td>
                        <td>{{ $report->statusLabel() }}</td>
                        <td class="is-end">
                            <a href="{{ \App\Filament\Resources\AccidentReportResource::getUrl('view', ['record' => $report]) }}" class="text-primary-600 underline-offset-2 hover:underline dark:text-primary-400">Open report</a>
                            @if ($report->document)
                                · <a href="{{ route('personnel-documents.view', $report->document) }}" data-hub-viewer="pdf" data-hub-viewer-title="{{ $report->typeLabel() }} {{ $report->reference }}" data-hub-viewer-download="{{ route('personnel-documents.download', $report->document) }}" class="text-primary-600 underline-offset-2 hover:underline dark:text-primary-400">PDF</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
