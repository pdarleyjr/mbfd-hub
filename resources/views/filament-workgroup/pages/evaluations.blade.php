<x-filament-panels::page data-hub-ui="2" data-hub-portal="workgroups">
    {{-- Session switcher pill badges (quick-click between sessions) --}}
    @php
        $currentMember = method_exists($this, 'getCurrentMember') ? $this->getCurrentMember() : null;
        $attendedSessions = $currentMember ? $this->getAttendedSessions($currentMember) : collect();
    @endphp

    @if($attendedSessions->count() > 1)
    <div class="wg-context-switcher mb-4" role="group" aria-label="Select evaluation session">
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Session:</span>
        @foreach($attendedSessions as $session)
            <button
                type="button"
                wire:click="switchSession({{ $session->id }})"
                aria-pressed="{{ $selectedSession == $session->id ? 'true' : 'false' }}"
                @class([
                    'inline-flex items-center gap-1 px-3 py-1.5 rounded-md min-h-11 text-sm font-medium transition-colors',
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
