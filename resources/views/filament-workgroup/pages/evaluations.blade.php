<x-filament-panels::page>
    {{-- Session switcher pill badges (quick-click between sessions) --}}
    @php
        $currentMember = method_exists($this, 'getCurrentMember') ? $this->getCurrentMember() : null;
        $attendedSessions = $currentMember ? $this->getAttendedSessions($currentMember) : collect();
    @endphp

    @if($attendedSessions->count() > 1)
    <div class="mb-4 flex flex-wrap gap-2 items-center">
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Session:</span>
        @foreach($attendedSessions as $session)
            <button
                wire:click="$set('selectedSession', '{{ $session->id }}')"
                @class([
                    'inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-medium transition-colors',
                    'bg-primary-600 text-white shadow-sm' => $selectedSession == $session->id,
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' => $selectedSession != $session->id,
                ])
            >
                @if($session->status === 'active')
                    <span class="inline-block w-2 h-2 rounded-full bg-green-400"></span>
                @endif
                {{ $session->name }}
            </button>
        @endforeach
    </div>
    @elseif($attendedSessions->count() === 1)
    <div class="mb-4">
        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300 text-sm font-medium">
            <x-heroicon-o-calendar class="w-4 h-4" />
            {{ $attendedSessions->first()->name }}
        </span>
    </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
