<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Session Info --}}
        @php $session = $this->getSessionInfo(); @endphp
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-calendar class="w-5 h-5 text-primary-500" />
                    <span>Active Session</span>
                </div>
            </x-slot>
            @if ($session)
                <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                    @foreach (['name' => 'Session', 'workgroup' => 'Workgroup', 'status' => 'Status', 'products' => 'Products', 'submitted' => 'Submitted', 'total_submissions' => 'Total'] as $key => $label)
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-center">
                            <div class="text-xs text-gray-500 uppercase tracking-wider">{{ $label }}</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white mt-1">{{ $session[$key] }}</div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-gray-500 italic">No active evaluation session.</p>
            @endif
        </x-filament::section>

        {{-- AI Summary --}}
        @php $aiSummary = $this->getAiSummary(); @endphp
        @if ($aiSummary)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-sparkles class="w-5 h-5 text-purple-500" />
                        <span>AI Analysis</span>
                    </div>
                </x-slot>
                <p class="text-gray-700 dark:text-gray-300">{{ $aiSummary }}</p>
            </x-filament::section>
        @endif

        {{-- Product Scores --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-chart-bar class="w-5 h-5 text-primary-500" />
                    <span>Product Rankings</span>
                </div>
            </x-slot>
            @php $products = $this->getProductScores(); @endphp
            @if (empty($products))
                <p class="text-gray-500 italic">No evaluation data available.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Rank</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Product</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Manufacturer</th>
                                <th class="text-left py-3 px-4 font-semibold text-gray-600">Category</th>
                                <th class="text-center py-3 px-4 font-semibold text-gray-600">Avg Score</th>
                                <th class="text-center py-3 px-4 font-semibold text-gray-600">Evaluations</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($products as $index => $product)
                                <tr class="border-b border-gray-100 hover:bg-gray-50">
                                    <td class="py-3 px-4 font-bold text-gray-500">{{ $index + 1 }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-900">{{ $product['name'] }}</td>
                                    <td class="py-3 px-4 text-gray-600">{{ $product['manufacturer'] }}</td>
                                    <td class="py-3 px-4 text-gray-600">{{ $product['category'] }}</td>
                                    <td class="py-3 px-4 text-center">
                                        @if ($product['avg_score'] !== 'N/A')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                {{ (float)$product['avg_score'] >= 80 ? 'bg-green-100 text-green-800' : ((float)$product['avg_score'] >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                                                {{ $product['avg_score'] }}/100
                                            </span>
                                        @else
                                            <span class="text-gray-400">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-center text-gray-600">{{ $product['response_count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
