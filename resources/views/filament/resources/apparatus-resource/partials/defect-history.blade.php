@php
    /** @var list<array{at: string, title: string, actor: string|null, member_message: string|null, internal_note: string|null}> $events */
@endphp

<div data-defect-history>
    @if ($events === [])
        <p class="text-sm text-gray-600 dark:text-gray-400">No history has been recorded for this issue yet.</p>
    @else
        <ol class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($events as $event)
                <li class="space-y-1 py-3">
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $event['title'] }}</p>
                    <p class="text-xs text-gray-600 dark:text-gray-400">
                        {{ $event['at'] }}@if ($event['actor']) · {{ $event['actor'] }}@endif
                    </p>
                    @if ($event['member_message'])
                        <p class="text-sm text-gray-700 dark:text-gray-300">Message to member: {{ $event['member_message'] }}</p>
                    @endif
                    @if ($event['internal_note'])
                        <p class="text-sm text-gray-700 dark:text-gray-300">Internal note: {{ $event['internal_note'] }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
