@php
    $data = $this->getAiSummary();
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div class="relative overflow-hidden rounded-xl bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 p-1">
            <div class="rounded-lg bg-white dark:bg-gray-900 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center">
                        <x-heroicon-s-sparkles class="w-6 h-6 text-white" />
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">AI Intelligence Summary</h3>
                        <p class="text-xs text-gray-500">
                            @if ($data['is_ai'] ?? false)
                                <span class="inline-flex items-center gap-1 text-green-600"><x-heroicon-s-bolt class="w-3 h-3" /> Powered by Workgroup AI</span>
                            @else
                                <span class="inline-flex items-center gap-1 text-gray-400"><x-heroicon-s-cpu-chip class="w-3 h-3" /> Statistical Summary</span>
                            @endif
                        </p>
                    </div>
                </div>

                @if ($data['status'] === 'no_session')
                    <p class="text-gray-500 italic">{{ $data['summary'] }}</p>
                @else
                    <p class="text-gray-700 dark:text-gray-300 leading-relaxed mb-4">{{ $data['summary'] }}</p>

                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                        @foreach (['submitted' => 'Submitted', 'drafts' => 'Drafts', 'locked' => 'Locked', 'avg_score' => 'Avg Score', 'top_product' => 'Top Product'] as $key => $label)
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-center">
                                <div class="text-xs text-gray-500 uppercase tracking-wider">{{ $label }}</div>
                                <div class="text-lg font-bold text-gray-900 dark:text-white mt-1">{{ $data['stats'][$key] ?? 'N/A' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
