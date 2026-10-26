@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ApparatusIdentificationEvent> $events */
    /** @var string $kind */
    $labels = \App\Models\ApparatusIdentificationEvent::EVENT_LABELS;
@endphp
@if ($events->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">Nothing recorded for this question yet.</p>
@else
    <div class="hub-insp hub-insp-table-wrap">
        <table class="hub-insp-table" data-identification-history>
            <thead>
                <tr>
                    <th>When (ET)</th>
                    <th>What happened</th>
                    <th>Value</th>
                    <th>Why</th>
                    <th>Who</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($events as $event)
                    <tr>
                        <td>{{ $event->created_at?->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A') }}</td>
                        <td>{{ $labels[$event->event] ?? $event->event }}</td>
                        <td>{{ $event->value ?? '' }}</td>
                        <td>
                            {{ \App\Support\Fleet\VehicleIdentificationFields::reasonLabel($event->reason) }}
                            @if ($event->note)
                                <br><small>{{ $event->note }}</small>
                            @endif
                        </td>
                        <td>{{ $event->actor?->name ?? 'System' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
