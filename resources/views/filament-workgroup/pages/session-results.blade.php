{{-- Session Results View --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Finalists Section --}}
        <x-filament::card>
            <x-filament::card.heading>
                <div class="flex items-center gap-2">
                    <x-heroicon-o-trophy class="w-6 h-6 text-warning" />
                    <span>Finalists</span>
                </div>
            </x-filament::card.heading>
            <x-filament::card.content>
                @php
                    $finalistsData = $this->getFinalistsData();
                @endphp

                @if(empty($finalistsData))
                    <p class="text-gray-500 text-center py-4">No active session or no finalists yet.</p>
                @else
                    <div class="space-y-6">
                        @foreach($finalistsData as $categoryName => $finalists)
                            <div class="border rounded-lg p-4">
                                <h4 class="font-semibold text-lg mb-3">{{ $categoryName }}</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    @foreach($finalists as $index => $finalist)
                                        <div class="flex items-center gap-3 p-3 rounded-lg {{ $index === 0 ? 'bg-yellow-50 border-yellow-200' : 'bg-gray-50 border-gray-200' }} border">
                                            <div class="flex-shrink-0">
                                                @if($index === 0)
                                                    <x-heroicon-o-trophy class="w-8 h-8 text-yellow-500" />
                                                @else
                                                    <x-heroicon-o-medal class="w-8 h-8 text-gray-400" />
                                                @endif
                                            </div>
                                            <div>
                                                <p class="font-medium">{{ $finalist['product']['name'] }}</p>
                                                <p class="text-sm text-gray-600">
                                                    {{ $finalist['product']['manufacturer'] ?? '' }} 
                                                    {{ $finalist['product']['model'] ?? '' }}
                                                </p>
                                                <p class="text-sm font-semibold text-green-600">
                                                    Score: {{ number_format($finalist['weighted_score'], 2) }}
                                                    ({{ $finalist['response_count'] }} responses)
                                                </p>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::card.content>
        </x-filament::card>

        {{-- Category Rankings --}}
        <x-filament::card>
            <x-filament::card.heading>
                <div class="flex items-center gap-2">
                    <x-heroicon-o-chart-bar class="w-6 h-6 text-primary" />
                    <span>All Rankings</span>
                </div>
            </x-filament::card.heading>
            <x-filament::card.content>
                @foreach($this->getWidgets() as $widget)
                    @if($widget instanceof \App\Filament\Workgroup\Widgets\CategoryRankingsWidget)
                        {{ \Filament\Support\Facades\FilamentView::renderWidget($widget) }}
                    @endif
                @endforeach
            </x-filament::card.content>
        </x-filament::card>

        {{-- Non-Rankable Feedback --}}
        <x-filament::card>
            <x-filament::card.heading>
                <div class="flex items-center gap-2">
                    <x-heroicon-o-chat-bubble-left-right class="w-6 h-6 text-info" />
                    <span>Feedback Summary</span>
                </div>
            </x-filament::card.heading>
            <x-filament::card.content>
                @php
                    $feedbackData = $this->getFeedbackData();
                @endphp

                @if(empty($feedbackData))
                    <p class="text-gray-500 text-center py-4">No non-rankable categories or feedback yet.</p>
                @else
                    <div class="space-y-4">
                        @foreach($feedbackData as $feedback)
                            <div class="border rounded-lg p-4">
                                <h4 class="font-semibold mb-2">{{ $feedback['category']['name'] }}</h4>
                                <p class="text-sm text-gray-600 mb-3">
                                    {{ $feedback['product_count'] }} products evaluated, 
                                    {{ $feedback['total_comments'] }} total comments
                                </p>
                                
                                @foreach($feedback['products'] as $product)
                                    <div class="mb-3 pb-3 border-b last:border-b-0">
                                        <p class="font-medium">{{ $product['product'] }}</p>
                                        @if(!empty($product['comments']))
                                            <ul class="mt-2 space-y-1">
                                                @foreach($product['comments'] as $comment)
                                                    <li class="text-sm text-gray-600 italic">"{{ $comment }}"</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::card.content>
        </x-filament::card>
    </div>
</x-filament-panels::page>
