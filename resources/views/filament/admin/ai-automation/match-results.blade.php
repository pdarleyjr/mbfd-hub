@php
    /** @var list<array{title: string, used: bool, why: string}> $matches */
@endphp
<div class="space-y-2" role="status">
    @forelse ($matches as $match)
        <div class="rounded-lg border p-3 text-sm {{ $match['used'] ? 'border-success-300 bg-success-50 dark:border-success-500/40 dark:bg-success-500/10' : 'border-gray-200 dark:border-white/10' }}">
            <p class="font-semibold text-gray-950 dark:text-white">{{ $match['title'] }}</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $match['why'] }}</p>
        </div>
    @empty
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ $message === '' ? 'Type a message above to see which instructions would be used.' : 'No published instructions match that message, so a standard reply would be used.' }}
        </p>
    @endforelse
</div>
