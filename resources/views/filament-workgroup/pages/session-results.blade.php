{{-- Anonymous Member Results View --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Aggregate Scores Section --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-chart-bar class="w-5 h-5 text-primary-500" />
                    <span>Product Score Overview</span>
                </div>
            </x-slot>
            <x-slot name="description">Average scores from all submitted evaluations.</x-slot>

            @php $products = $this->getProductScores(); @endphp

            @if (empty($products))
                <p class="text-gray-500 italic">No evaluation data available yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Product</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Manufacturer</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Category</th>
                                <th class="text-center py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Avg Score</th>
                                <th class="text-center py-3 px-4 font-semibold text-gray-600 dark:text-gray-400">Responses</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($products as $product)
                                <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td class="py-3 px-4 font-medium text-gray-900 dark:text-white">{{ $product['name'] }}</td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-gray-400">{{ $product['manufacturer'] }}</td>
                                    <td class="py-3 px-4 text-gray-600 dark:text-gray-400">{{ $product['category'] }}</td>
                                    <td class="py-3 px-4 text-center">
                                        @if ($product['avg_score'] !== 'N/A')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                {{ (float)$product['avg_score'] >= 80 ? 'bg-green-100 text-green-800' : ((float)$product['avg_score'] >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                                                {{ $product['avg_score'] }}/100
                                            </span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-center text-gray-600 dark:text-gray-400">{{ $product['response_count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- Anonymous Feedback Section --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-chat-bubble-left-right class="w-5 h-5 text-primary-500" />
                    <span>Anonymous Feedback</span>
                </div>
            </x-slot>
            <x-slot name="description">General feedback from evaluators (names hidden to protect evaluation integrity).</x-slot>

            @php $feedback = $this->getAnonymousFeedback(); @endphp

            @if (empty($feedback))
                <p class="text-gray-500 italic">No feedback submitted yet.</p>
            @else
                <div class="space-y-3">
                    @foreach ($feedback as $item)
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">{{ $item['product'] }}</span>
                                <span class="text-xs text-gray-400">{{ $item['type'] }}</span>
                            </div>
                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $item['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
