<x-filament-panels::page>
    @php
        $member = method_exists($this, 'getCurrentMember') ? $this->getCurrentMember() : null;
        $sessions = $member ? $this->getAccessibleSessions($member) : collect();
        $stats = $this->getWorkgroupStats();
    @endphp

    {{-- Session Switcher (shown when member has access to multiple sessions) --}}
    @if($sessions->count() > 1)
    <div class="mb-5 flex flex-wrap gap-2 items-center">
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400 mr-1">Session:</span>
        @foreach($sessions as $session)
            <button
                wire:click="$set('selectedSessionId', {{ $session->id }})"
                @class([
                    'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium transition-colors',
                    'bg-primary-600 text-white shadow-sm ring-1 ring-primary-700' => $selectedSessionId == $session->id,
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' => $selectedSessionId != $session->id,
                ])
            >
                @if($session->status === 'active')
                    <span class="w-2 h-2 rounded-full bg-green-400 flex-shrink-0"></span>
                @elseif($session->status === 'completed')
                    <span class="w-2 h-2 rounded-full bg-blue-400 flex-shrink-0"></span>
                @endif
                {{ $session->name }}
            </button>
        @endforeach
    </div>
    @elseif($sessions->count() === 1)
    <div class="mb-4">
        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 text-sm font-medium ring-1 ring-primary-200 dark:ring-primary-700/50">
            <x-heroicon-o-calendar class="w-4 h-4" />
            {{ $sessions->first()->name }}
        </span>
    </div>
    @endif

    {{-- Stats Overview Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">
        @foreach($stats as $stat)
        <div class="fi-wi-stats-overview-stat relative rounded-2xl bg-white dark:bg-gray-900 p-6 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
            <div class="flex items-start gap-x-3">
                @if($stat->getDescriptionIcon())
                <div class="flex h-10 w-10 items-center justify-center rounded-xl
                    {{ match($stat->getColor()) {
                        'primary' => 'bg-primary-50 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400',
                        'success' => 'bg-green-50 text-green-500 dark:bg-green-500/10 dark:text-green-400',
                        'warning' => 'bg-amber-50 text-amber-500 dark:bg-amber-500/10 dark:text-amber-400',
                        'info' => 'bg-blue-50 text-blue-500 dark:bg-blue-500/10 dark:text-blue-400',
                        'danger' => 'bg-red-50 text-red-500 dark:bg-red-500/10 dark:text-red-400',
                        default => 'bg-gray-50 text-gray-500 dark:bg-gray-500/10 dark:text-gray-400',
                    } }}">
                    <x-dynamic-component :component="$stat->getDescriptionIcon()" class="w-5 h-5" />
                </div>
                @endif
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">{{ $stat->getLabel() }}</p>
                    <p class="mt-1 text-2xl font-bold tracking-tight
                        {{ match($stat->getColor()) {
                            'success' => 'text-green-600 dark:text-green-400',
                            'warning' => 'text-amber-600 dark:text-amber-400',
                            'danger' => 'text-red-600 dark:text-red-400',
                            'primary' => 'text-primary-600 dark:text-primary-400',
                            'info' => 'text-blue-600 dark:text-blue-400',
                            default => 'text-gray-900 dark:text-white',
                        } }}">
                        {{ $stat->getValue() }}
                    </p>
                    @if($stat->getDescription())
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $stat->getDescription() }}</p>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Quick Nav Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <a href="{{ \App\Filament\Workgroup\Pages\Evaluations::getUrl() }}"
            class="group flex flex-col items-center justify-center p-6 rounded-2xl bg-primary-50 dark:bg-primary-900/20 ring-1 ring-primary-200 dark:ring-primary-700/50 hover:bg-primary-100 dark:hover:bg-primary-900/40 transition-colors text-center">
            <x-heroicon-o-clipboard-document-check class="w-8 h-8 text-primary-600 dark:text-primary-400 mb-2 group-hover:scale-110 transition-transform" />
            <span class="font-semibold text-primary-700 dark:text-primary-300">Evaluations</span>
            <span class="text-xs text-primary-500 dark:text-primary-400 mt-0.5">Evaluate products</span>
        </a>
        <a href="{{ \App\Filament\Workgroup\Pages\Files::getUrl() }}"
            class="group flex flex-col items-center justify-center p-6 rounded-2xl bg-blue-50 dark:bg-blue-900/20 ring-1 ring-blue-200 dark:ring-blue-700/50 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition-colors text-center">
            <x-heroicon-o-document-duplicate class="w-8 h-8 text-blue-600 dark:text-blue-400 mb-2 group-hover:scale-110 transition-transform" />
            <span class="font-semibold text-blue-700 dark:text-blue-300">Files</span>
            <span class="text-xs text-blue-500 dark:text-blue-400 mt-0.5">All session materials</span>
        </a>
        <a href="{{ \App\Filament\Workgroup\Pages\Notes::getUrl() }}"
            class="group flex flex-col items-center justify-center p-6 rounded-2xl bg-gray-50 dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700/50 transition-colors text-center">
            <x-heroicon-o-pencil-square class="w-8 h-8 text-gray-500 dark:text-gray-400 mb-2 group-hover:scale-110 transition-transform" />
            <span class="font-semibold text-gray-700 dark:text-gray-300">Notes</span>
            <span class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Personal & shared notes</span>
        </a>
    </div>
</x-filament-panels::page>
