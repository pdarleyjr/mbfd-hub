<x-filament-panels::page>
    {{-- Session Switcher Pill Navigation --}}
    @php $allSessions = $this->getAllSessions(); @endphp
    @if($allSessions->count() > 0)
    <div class="mb-5 flex flex-wrap gap-2 items-center">
        {{-- "Overall / All Sessions" option --}}
        <button
            wire:click="switchSession(null)"
            @class([
                'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium transition-colors',
                'bg-gray-800 text-white shadow-sm ring-1 ring-gray-900' => $selectedSessionId === null,
                'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' => $selectedSessionId !== null,
            ])
        >
            <x-heroicon-o-squares-2x2 class="w-3.5 h-3.5" />
            Overall Results
        </button>

        {{-- Individual session pills --}}
        @foreach($allSessions as $daySess)
        <button
            wire:click="switchSession({{ $daySess->id }})"
            @class([
                'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium transition-colors',
                'bg-primary-600 text-white shadow-sm ring-1 ring-primary-700' => $selectedSessionId === $daySess->id,
                'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' => $selectedSessionId !== $daySess->id,
            ])
        >
            @if($daySess->status === 'active')
                <span class="w-2 h-2 rounded-full bg-green-400 flex-shrink-0"></span>
            @elseif($daySess->status === 'completed')
                <span class="w-2 h-2 rounded-full bg-blue-400 flex-shrink-0"></span>
            @endif
            {{ $daySess->name }}
        </button>
        @endforeach
    </div>
    @endif

    {{-- Session Progress Widgets --}}
    @if($session || !empty($categoryResults))
