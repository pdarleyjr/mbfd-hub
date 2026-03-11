<x-filament-panels::page>
    {{-- Admin Stats Overview (inline — no child widgets) --}}
    @if(!empty($stats))
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        @foreach($stats as $stat)
        @php
            $colorMap = [
                'primary' => 'bg-primary-50 dark:bg-primary-950/20 text-primary-700 dark:text-primary-300 ring-primary-200 dark:ring-primary-800',
                'success' => 'bg-emerald-50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-300 ring-emerald-200 dark:ring-emerald-800',
                'warning' => 'bg-amber-50 dark:bg-amber-950/20 text-amber-700 dark:text-amber-300 ring-amber-200 dark:ring-amber-800',
                'info' => 'bg-blue-50 dark:bg-blue-950/20 text-blue-700 dark:text-blue-300 ring-blue-200 dark:ring-blue-800',
                'gray' => 'bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-gray-200 dark:ring-gray-700',
            ];
            $colorClass = $colorMap[$stat['color']] ?? $colorMap['gray'];
        @endphp
        <div class="rounded-xl p-4 shadow-sm ring-1 {{ $colorClass }}">
            <div class="flex items-center gap-2 mb-2">
                <x-dynamic-component :component="$stat['icon']" class="w-5 h-5 opacity-70"/>
                <p class="text-xs font-medium opacity-80 truncate">{{ $stat['label'] }}</p>
            </div>
            <p class="text-2xl font-bold">{{ $stat['value'] }}</p>
            <p class="text-xs opacity-70 mt-1 truncate">{{ $stat['desc'] }}</p>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Session Progress --}}
    @if($progress)
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-5">
        <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <x-heroicon-o-chart-bar class="w-5 h-5 text-primary-500"/>
            Active Session Progress{{ $activeSession ? ': ' . $activeSession->name : '' }}
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            @php
                $progressItems = [
                    ['label' => 'Products', 'val' => $progress['total_products']],
                    ['label' => 'Evaluators', 'val' => $progress['total_members']],
                    ['label' => 'Submitted', 'val' => $progress['submitted_submissions']],
                    ['label' => 'Completion', 'val' => $progress['completion_percentage'] . '%'],
                ];
            @endphp
            @foreach($progressItems as $pi)
            <div class="text-center p-3 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $pi['label'] }}</p>
                <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">{{ $pi['val'] }}</p>
            </div>
            @endforeach
        </div>
        @if($progress['completion_percentage'] > 0)
        <div class="mt-4 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
            <div class="bg-primary-600 h-2.5 rounded-full transition-all" style="width: {{ min(100, $progress['completion_percentage']) }}%"></div>
        </div>
        @endif
    </div>
    @endif
</x-filament-panels::page>