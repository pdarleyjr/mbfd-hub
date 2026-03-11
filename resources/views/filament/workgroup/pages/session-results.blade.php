<x-filament-panels::page>
    {{-- Session Switcher Pill Navigation --}}
    @php $allSessions = $this->getAllSessions(); @endphp
    @if($allSessions->count() > 0)
    <div class="mb-5 flex flex-wrap gap-2 items-center">
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

    {{-- Session Progress Stats (inline — always fresh on Livewire re-render) --}}
    @if($progress)
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
        @php
            $pStats = [
                ['label' => 'Products',    'val' => $progress['total_products']],
                ['label' => 'Evaluators',  'val' => $progress['total_members']],
                ['label' => 'Submitted',   'val' => $progress['submitted_submissions']],
                ['label' => 'In Progress', 'val' => $progress['draft_submissions']],
                ['label' => 'Pending',     'val' => max(0, $progress['max_possible_submissions'] - $progress['submitted_submissions'])],
                ['label' => 'Completion',  'val' => $progress['completion_percentage'] . '%'],
            ];
        @endphp
        @foreach($pStats as $s)
        <div class="fi-wi-stats-overview-stat rounded-xl bg-white dark:bg-gray-900 p-4 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 text-center">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate">{{ $s['label'] }}</p>
            <p class="mt-1 text-xl font-bold text-gray-900 dark:text-white">{{ $s['val'] }}</p>
        </div>
        @endforeach
    </div>
    @endif

    {{-- AI Executive Report Panel — loads asynchronously via wire:init --}}
    <div class="my-6 bg-gradient-to-br from-violet-50 to-indigo-50 dark:from-violet-950/30 dark:to-indigo-950/30 border border-violet-200 dark:border-violet-700/50 rounded-xl p-5 shadow-sm"
         wire:init="loadAiReport">
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-br from-violet-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-md flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-violet-900 dark:text-violet-100">AI Executive Intelligence Report</h3>
                    <p class="text-xs text-violet-500 dark:text-violet-400">Committee-ready summary · Powered by Llama 3.3 70B</p>
                </div>
            </div>
            @if($aiReportLoaded && $aiReport)
            <div class="flex items-center gap-2 flex-shrink-0">
                <button wire:click="regenerateAiReport" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-50 transition-all shadow-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Regenerate
                </button>
                <button x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(@js($aiReport)); copied = true; setTimeout(() => copied = false, 2000)"
                    class="inline-flex items-center gap-1 px-3 py-2 text-xs font-medium rounded-lg bg-white dark:bg-gray-800 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-600 hover:bg-violet-50 dark:hover:bg-violet-900/30 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                </button>
            </div>
            @endif
        </div>

        {{-- Shimmer/skeleton placeholder while loading --}}
        @if(!$aiReportLoaded)
        <div class="space-y-3 py-4 animate-pulse" wire:loading.class.remove="animate-pulse">
            <div class="flex items-center gap-3">
                <div class="flex gap-1">
                    <span class="w-2 h-2 bg-violet-500 rounded-full animate-bounce" style="animation-delay:0ms"></span>
                    <span class="w-2 h-2 bg-violet-500 rounded-full animate-bounce" style="animation-delay:150ms"></span>
                    <span class="w-2 h-2 bg-violet-500 rounded-full animate-bounce" style="animation-delay:300ms"></span>
                </div>
                <p class="text-sm text-violet-600 dark:text-violet-400">Generating AI executive report...</p>
            </div>
            <div class="h-4 bg-violet-200/50 dark:bg-violet-800/30 rounded w-full"></div>
            <div class="h-4 bg-violet-200/50 dark:bg-violet-800/30 rounded w-5/6"></div>
            <div class="h-4 bg-violet-200/50 dark:bg-violet-800/30 rounded w-4/6"></div>
            <div class="h-4 bg-violet-200/50 dark:bg-violet-800/30 rounded w-full"></div>
            <div class="h-4 bg-violet-200/50 dark:bg-violet-800/30 rounded w-3/4"></div>
        </div>
        @elseif($aiReportError)
        <div class="bg-red-50 dark:bg-red-950/30 rounded-lg p-3 border border-red-200 dark:border-red-700">
            <p class="text-sm text-red-600 dark:text-red-400">{{ $aiReportError }}</p>
            <button wire:click="loadAiReport" class="mt-2 text-xs text-red-600 underline hover:no-underline">Retry</button>
        </div>
        @elseif($aiReport)
        <div class="bg-white dark:bg-gray-900 rounded-lg p-5 border border-violet-100 dark:border-violet-800 text-gray-800 dark:text-gray-200 text-sm leading-relaxed whitespace-pre-wrap max-h-96 overflow-y-auto shadow-inner">{{ $aiReport }}</div>
        @else
        <div class="text-center py-4">
            <p class="text-sm text-violet-500 dark:text-violet-400 max-w-xl mx-auto">Click "Generate Report" for a comprehensive executive summary.</p>
            <button wire:click="loadAiReport" class="mt-3 inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-violet-600 text-white hover:bg-violet-700 transition-all shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Generate Report
            </button>
        </div>
        @endif
    </div>

    {{-- Category Rankings Grid --}}
    @if($categoryResults->isNotEmpty())
    <div class="space-y-6">
        <h2 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
            <x-heroicon-o-chart-bar class="w-5 h-5 text-primary-500"/>
            Category Rankings & SAVER Breakdown
        </h2>

        @foreach($categoryResults as $cat)
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="px-5 py-4 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center">
                            <x-heroicon-o-squares-2x2 class="w-4 h-4 text-primary-600 dark:text-primary-400"/>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $cat['category_name'] }}</h3>
                            <p class="text-xs text-gray-500">{{ $cat['total_products'] }} products · {{ $cat['eligible_products'] }} meet threshold</p>
                        </div>
                    </div>
                    @if($cat['top_products']->isNotEmpty())
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300 rounded-full">
                        <x-heroicon-o-trophy class="w-3.5 h-3.5"/>
                        Top: {{ $cat['top_products']->first()['product']->name ?? 'N/A' }}
                    </span>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <th class="text-left px-4 py-2.5 text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                            <th class="text-left px-4 py-2.5 text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                            <th class="text-center px-3 py-2.5 text-xs font-medium text-gray-500 uppercase tracking-wider">Overall</th>
                            <th class="text-center px-2 py-2.5 text-xs font-medium text-violet-500 uppercase tracking-wider" title="Safety/Capability">S</th>
                            <th class="text-center px-2 py-2.5 text-xs font-medium text-blue-500 uppercase tracking-wider" title="Adaptability/Usability">A</th>
                            <th class="text-center px-2 py-2.5 text-xs font-medium text-emerald-500 uppercase tracking-wider" title="Value/Affordability">V</th>
                            <th class="text-center px-2 py-2.5 text-xs font-medium text-amber-500 uppercase tracking-wider" title="Endurance/Maintainability">E</th>
                            <th class="text-center px-2 py-2.5 text-xs font-medium text-rose-500 uppercase tracking-wider" title="Readiness/Deployability">R</th>
                            <th class="text-center px-3 py-2.5 text-xs font-medium text-gray-500 uppercase tracking-wider">Advance</th>
                            <th class="text-center px-3 py-2.5 text-xs font-medium text-gray-500 uppercase tracking-wider">Responses</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                        @foreach($cat['rankings'] as $index => $item)
                        @php
                            $rank = $index + 1;
                            $isFinalist = $rank <= 2 && $item['meets_threshold'];
                            $score = $item['weighted_average'];
                        @endphp
                        <tr class="{{ $isFinalist ? 'bg-amber-50/50 dark:bg-amber-950/20' : '' }} hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                            <td class="px-4 py-3 font-mono text-xs">
                                @if($rank === 1 && $isFinalist)
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-400 text-white text-xs font-bold">1</span>
                                @elseif($rank === 2 && $isFinalist)
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-400 text-white text-xs font-bold">2</span>
                                @else
                                    <span class="text-gray-400">{{ $rank }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-white">{{ $item['product']->name }}</div>
                                @if($item['product']->manufacturer)
                                <div class="text-xs text-gray-500">{{ $item['product']->manufacturer }} {{ $item['product']->model ? '· '.$item['product']->model : '' }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center">
                                @if($score !== null)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold
                                    {{ $score >= 80 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' :
                                       ($score >= 60 ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' :
                                       'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300') }}">
                                    {{ number_format($score, 1) }}
                                </span>
                                @else
                                <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            @foreach(['capability_avg', 'usability_avg', 'affordability_avg', 'maintainability_avg', 'deployability_avg'] as $saverKey)
                            <td class="px-2 py-3 text-center text-xs font-medium {{ $item[$saverKey] ? 'text-gray-700 dark:text-gray-300' : 'text-gray-300' }}">
                                {{ $item[$saverKey] ? number_format($item[$saverKey], 0) : '—' }}
                            </td>
                            @endforeach
                            <td class="px-3 py-3 text-center">
                                @if($item['advance_yes'] > 0 || $item['advance_no'] > 0)
                                <span class="text-xs">
                                    <span class="text-emerald-600 font-medium">{{ $item['advance_yes'] }}✓</span>
                                    @if($item['advance_no'] > 0)
                                    <span class="text-red-500 ml-1">{{ $item['advance_no'] }}✕</span>
                                    @endif
                                </span>
                                @if($item['deal_breakers'] > 0)
                                <span class="ml-1 text-xs text-red-500" title="{{ $item['deal_breakers'] }} deal-breaker(s)">⚠️</span>
                                @endif
                                @else
                                <span class="text-gray-300 text-xs">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                    {{ $item['meets_threshold'] ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">
                                    {{ $item['response_count'] }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    --}}

     Competitor Group Rankings --}}

    @if(!empty($competitorGroupRankings))
    <div class="mt-6">
        <div class="mb-4 flex items-center gap-3">
            <div class="w-9 h-9 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-xl flex items-center justify-center shadow-sm flex-shrink-0">
                <x-heroicon-o-scale class="w-5 h-5 text-white"/>
            </div>
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Competitor Group Rankings</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Products ranked against direct competitors within the same group</p>
            </div>
        </div>

        @foreach($competitorGroupRankings as $cgCategory)
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="px-5 py-3 bg-blue-50 dark:bg-blue-950/20 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-blue-900 dark:text-blue-200">{{ $cgCategory['category_name'] }}</h3>
            </div>

            @foreach($cgCategory['groups'] as $group)
            <div class="px-5 py-3 {{ !$loop->last ? 'border-b border-gray-100 dark:border-gray-800' : '' }}">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $group['group_name'] }} <span class="text-xs text-gray-400">({{ $group['product_count'] }} products)</span></h4>
                <div class="space-y-1.5">
                    @foreach($group['rankings'] as $rIdx => $ranking)
                    <div class="flex items-center gap-3 px-3 py-2 rounded-lg {{ $rIdx === 0 ? 'bg-blue-50/50 dark:bg-blue-950/10' : 'bg-gray-50 dark:bg-gray-800/30' }}">
                        <span class="text-sm font-bold {{ $rIdx === 0 ? 'text-blue-600' : 'text-gray-400' }}">{{ $rIdx + 1 }}</span>
                        <div class="flex-1 min-w-0">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $ranking['name'] }}</span>
                            @if($ranking['brand'])
                            <span class="text-xs text-gray-500 ml-1">({{ $ranking['brand'] }})</span>
                            @endif
                        </div>
                        @if($ranking['avg_score'] !== null)
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold {{ $ranking['avg_score'] >= 70 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">
                            {{ number_format($ranking['avg_score'], 1) }}
                        </span>
                        @else
                        <span class="text-xs text-gray-300">—</span>
                        @endif
                        <span class="text-xs text-gray-400">{{ $ranking['response_count'] }} resp.</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
        @endforeach
    </div>
    @endif

    --}}

     Brand Group Purchase Analysis --}}

    @if(!empty($brandGroupedAnalysis) && $session)
    <div class="mt-6">
        <div class="mb-4 flex items-center gap-3">
            <div class="w-9 h-9 bg-gradient-to-br from-amber-500 to-orange-600 rounded-xl flex items-center justify-center shadow-sm flex-shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Package Purchase Recommendation</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Brand rankings by composite score — best value for complete tool set from single manufacturer</p>
            </div>
        </div>

        @foreach($brandGroupedAnalysis as $group)
        <div class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 mb-4 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-amber-50 dark:bg-amber-950/20">
                <h3 class="text-base font-semibold text-amber-900 dark:text-amber-200">{{ $group['category_name'] }}</h3>
                <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">
                    {{ $group['brand_count'] }} brands · {{ $group['total_products'] }} products compared
                </p>
            </div>
            <div class="p-4">
                @foreach($group['brand_rankings'] as $rank => $brandData)
                @php
                    $isTop = $rank === 0;
                    $medal = match($rank) { 0 => '🥇', 1 => '🥈', 2 => '🥉', default => '#'.($rank+1) };
                @endphp
                <div class="mb-4 {{ !$loop->last ? 'pb-4 border-b border-gray-100 dark:border-gray-800' : '' }}">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="text-2xl">{{ $medal }}</span>
                        <div class="flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-base font-bold {{ $isTop ? 'text-amber-700 dark:text-amber-300' : 'text-gray-700 dark:text-gray-300' }}">
                                    {{ $brandData['brand'] }}
                                </span>
                                @if($brandData['composite_score'] !== null)
                                <span class="px-2.5 py-0.5 rounded-full text-sm font-bold
                                    {{ $isTop ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">
                                    {{ number_format($brandData['composite_score'], 1) }} composite avg
                                </span>
                                @endif
                                @if($isTop && $brandData['composite_score'] !== null)
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                    ✓ Best Complete Package
                                </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="ml-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach($brandData['product_scores'] as $ps)
                        <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800/50 text-xs">
                            <span class="text-gray-600 dark:text-gray-400 truncate mr-2">{{ $ps['product']->name }}</span>
                            @if($ps['avg_score'] !== null)
                            <span class="font-semibold flex-shrink-0
                                {{ $ps['avg_score'] >= 70 ? 'text-green-600 dark:text-green-400' : ($ps['avg_score'] >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">
                                {{ number_format($ps['avg_score'], 1) }}
                            </span>
                            @else
                            <span class="text-gray-300 flex-shrink-0">—</span>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
    @endif

    --}}

     Isolated / Standalone Products --}}

    @if(!empty($isolatedProducts))
    <div class="mt-6">
        <div class="mb-4 flex items-center gap-3">
            <div class="w-9 h-9 bg-gradient-to-br from-gray-500 to-slate-600 rounded-xl flex items-center justify-center shadow-sm flex-shrink-0">
                <x-heroicon-o-cube class="w-5 h-5 text-white"/>
            </div>
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Standalone Product Analysis</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Products evaluated independently — no direct competitors for ranking</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($isolatedProducts as $iso)
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-4">
                <div class="flex items-start justify-between mb-2">
                    <div>
                        <h4 class="font-semibold text-gray-900 dark:text-white">{{ $iso['name'] }}</h4>
                        @if($iso['brand'])
                        <p class="text-xs text-gray-500">{{ $iso['brand'] }} · {{ $iso['category_name'] }}</p>
                        @else
                        <p class="text-xs text-gray-500">{{ $iso['category_name'] }}</p>
                        @endif
                    </div>
                    @if($iso['avg_score'] !== null)
                    <span class="px-2.5 py-1 rounded-full text-sm font-bold {{ $iso['avg_score'] >= 70 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">
                        {{ number_format($iso['avg_score'], 1) }}
                    </span>
                    @endif
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 italic mb-2">{{ $iso['note'] }}</p>
                <div class="flex items-center gap-3 text-xs text-gray-500">
                    <span>{{ $iso['response_count'] }} responses</span>
                    @if($iso['meets_threshold'])
                    <span class="text-green-600">✓ Meets threshold</span>
                    @else
                    <span class="text-amber-600">Below threshold</span>
                    @endif
                </div>
                @if(!empty($iso['saver_breakdown']) && $iso['saver_breakdown']['capability'] !== null)
                <div class="mt-2 flex gap-2 text-xs">
                    @foreach(['capability' => 'S', 'usability' => 'A', 'affordability' => 'V', 'maintainability' => 'E', 'deployability' => 'R'] as $key => $label)
                    <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400" title="{{ ucfirst($key) }}">
                        {{ $label }}: {{ $iso['saver_breakdown'][$key] !== null ? number_format($iso['saver_breakdown'][$key], 0) : '—' }}
                    </span>
                    @endforeach
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    --}}

     Non-Rankable Feedback --}}

    @if($nonRankableFeedback->isNotEmpty())
    <div class="mt-6 space-y-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-9 h-9 bg-gradient-to-br from-teal-500 to-green-600 rounded-xl flex items-center justify-center shadow-sm flex-shrink-0">
                <x-heroicon-o-chat-bubble-left-right class="w-5 h-5 text-white"/>
            </div>
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Non-Rankable Category Feedback</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Qualitative feedback for categories not eligible for competitive ranking</p>
            </div>
        </div>

        @foreach($nonRankableFeedback as $nrCat)
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="px-5 py-3 bg-teal-50 dark:bg-teal-950/20 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-teal-900 dark:text-teal-200">{{ $nrCat['category_name'] }}</h3>
                <p class="text-xs text-teal-600 dark:text-teal-400">{{ $nrCat['submissions_count'] }} submissions</p>
            </div>
            <div class="p-4 space-y-3">
                @foreach($nrCat['feedback'] as $fb)
                <div class="px-4 py-3 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $fb['product'] }}</span>
                        <div class="flex items-center gap-2 text-xs text-gray-500">
                            @if($fb['score'] !== null)
                            <span class="font-semibold">Score: {{ number_format($fb['score'], 1) }}</span>
                            @endif
                            <span>by {{ $fb['evaluator'] ?? 'Unknown' }}</span>
                        </div>
                    </div>
                    @if(!empty($fb['comments']))
                    <div class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                        @foreach($fb['comments'] as $comment)
                        <p>{{ $comment }}</p>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
    @endif

    --}}

     Finalists Summary --}}

    @php
        $finalists = collect();
        foreach($categoryResults as $cat) {
            if (!empty($cat['rankings'])) {
                $top = collect($cat['rankings'])->filter(fn($r) => $r['meets_threshold'])->take(2);
                foreach($top as $idx => $item) {
                    $finalists->push([
                        'category' => $cat['category_name'],
                        'rank' => $idx + 1,
                        'product' => $item['product'],
                        'score' => $item['weighted_average'],
                        'responses' => $item['response_count'],
                    ]);
                }
            }
        }
    @endphp
    @if($finalists->isNotEmpty())
    <div class="mt-6">
        <div class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                <x-heroicon-o-trophy class="w-5 h-5 text-amber-500 flex-shrink-0" />
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Top Finalists</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Rank</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Category</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Manufacturer</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Avg Score</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Responses</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($finalists as $finalist)
                        <tr class="{{ $finalist['rank'] === 1 ? 'bg-amber-50/40 dark:bg-amber-950/10' : '' }}">
                            <td class="px-4 py-3">
                                <span class="text-xl">{{ $finalist['rank'] === 1 ? '🥇' : '🥈' }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $finalist['category'] }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $finalist['product']->name }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $finalist['product']->manufacturer ?? '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold {{ $finalist['rank'] === 1 ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                    {{ number_format($finalist['score'], 1) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center text-gray-500">{{ $finalist['responses'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- No Session State --}}
    @else
    <div class="text-center py-16">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
            <x-heroicon-o-calendar class="w-8 h-8 text-gray-400"/>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">No Active Session</h3>
        <p class="text-sm text-gray-500 max-w-md mx-auto">There are no evaluation sessions available. Use the "Switch Session" button above to select a session, or ask an admin to create one.</p>
    </div>
    @endif

    <script>
    function workgroupAIPanel() {
        return {
            loading: false, report: null, error: null, copied: false,
            init() {
                // Auto-generate report on first page load
                this.generateReport(false);
            },
            async generateReport(force = false) {
                this.loading = true; this.error = null;
                if (force) this.report = null;
                try {
                    const resp = await fetch('/api/workgroup/ai/executive-report', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': this.getCsrf() },
                        body: JSON.stringify({ force })
                    });
                    if (!resp.ok) throw new Error((await resp.json().catch(() => ({}))).error || 'Server error');
                    const data = await resp.json();
                    this.report = data.report || 'No report generated';
                } catch (e) { this.error = 'Failed: ' + e.message; } finally { this.loading = false; }
            },
            async copyReport() {
                if (!this.report) return;
                await navigator.clipboard.writeText(this.report).catch(() => {});
                this.copied = true; setTimeout(() => { this.copied = false; }, 3000);
            },
            getCsrf() {
                const m = document.querySelector('meta[name="csrf-token"]');
                if (m) return m.getAttribute('content');
                const c = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
                return c ? decodeURIComponent(c[1]) : '';
            }
        };
    }
    </script>
</x-filament-panels::page>
