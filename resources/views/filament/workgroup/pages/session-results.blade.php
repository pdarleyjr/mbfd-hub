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

    {{-- Session Progress Widgets --}}
    @if(true)
    <div wire:key="progress-{{ $selectedSessionId }}"><x-filament-widgets::widgets
        :widgets="$this->getHeaderWidgets()"
        :columns="$this->getHeaderWidgetsColumns()"
    /></div>

    {{-- AI Executive Report Panel --}}
    <div
        x-data="workgroupAIPanel()"
        class="my-6 bg-gradient-to-br from-violet-50 to-indigo-50 dark:from-violet-950/30 dark:to-indigo-950/30 border border-violet-200 dark:border-violet-700/50 rounded-xl p-5 shadow-sm"
    >
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-br from-violet-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-md flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-violet-900 dark:text-violet-100">AI Executive Intelligence Report</h3>
                    <p class="text-xs text-violet-500 dark:text-violet-400">Committee-ready summary for Health & Safety presentation · Powered by Llama 3.3 70B</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <button @click="generateReport(false)" :disabled="loading"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-50 transition-all shadow-sm hover:shadow-md">
                    <svg x-show="!loading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <svg x-show="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <span x-text="loading ? 'Generating...' : (report ? 'Regenerate' : 'Generate Report')"></span>
                </button>
                <button x-show="report" @click="copyReport()" title="Copy to clipboard"
                    class="inline-flex items-center gap-1 px-3 py-2 text-xs font-medium rounded-lg bg-white dark:bg-gray-800 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-600 hover:bg-violet-50 dark:hover:bg-violet-900/30 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                </button>
            </div>
        </div>
        <div x-show="loading" x-transition class="flex items-center gap-3 py-4">
            <div class="flex gap-1">
                <span class="w-2 h-2 bg-violet-500 rounded-full animate-bounce" style="animation-delay:0ms"></span>
                <span class="w-2 h-2 bg-violet-500 rounded-full animate-bounce" style="animation-delay:150ms"></span>
                <span class="w-2 h-2 bg-violet-500 rounded-full animate-bounce" style="animation-delay:300ms"></span>
            </div>
            <p class="text-sm text-violet-600 dark:text-violet-400">Analyzing {{ $progress['submitted_submissions'] ?? 0 }} evaluations across all categories...</p>
        </div>
        <div x-show="error && !loading" x-transition class="bg-red-50 dark:bg-red-950/30 rounded-lg p-3 border border-red-200 dark:border-red-700">
            <p class="text-sm text-red-600 dark:text-red-400" x-text="error"></p>
        </div>
        <div x-show="!loading && !report && !error" class="text-center py-4">
            <p class="text-sm text-violet-500 dark:text-violet-400 max-w-xl mx-auto">Click "Generate Report" for a comprehensive executive summary with rankings, finalist recommendations, SAVER score analysis, and category insights ready for presentation to Fire Chief Abello.</p>
        </div>
        <div x-show="report && !loading" x-transition>
            <div class="bg-white dark:bg-gray-900 rounded-lg p-5 border border-violet-100 dark:border-violet-800 text-gray-800 dark:text-gray-200 text-sm leading-relaxed whitespace-pre-wrap max-h-96 overflow-y-auto shadow-inner" x-text="report"></div>
        </div>
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
                            <p class="text-xs text-gray-500">{{ $cat['total_products'] }} products · {{ $cat['eligible_products'] }} meet threshold (≥{{ $progress['total_members'] ?? 3 }} responses)</p>
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

    {{-- 🛒 Brand Group Purchase Analysis --}}
    @if(!empty($brandGroupedAnalysis) && $session)
    <div class="mt-6">
        <div class="mb-4 flex items-center gap-3">
            <div class="w-9 h-9 bg-gradient-to-br from-amber-500 to-orange-600 rounded-xl flex items-center justify-center shadow-sm flex-shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Package Purchase Recommendation</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Brand rankings by composite score — best value when purchasing a complete tool set from a single manufacturer for fleet consistency</p>
            </div>
        </div>

        @foreach($brandGroupedAnalysis as $group)
        <div class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 mb-4 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 bg-amber-50 dark:bg-amber-950/20">
                <h3 class="text-base font-semibold text-amber-900 dark:text-amber-200">{{ $group['category_name'] }}</h3>
                <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">
                    {{ $group['brand_count'] }} brands · {{ $group['total_products'] }} products compared
                    — composite score averages all brand product evaluations
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
                        <span class="text-2xl" title="Rank {{ $rank+1 }}">{{ $medal }}</span>
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
                                @else
                                <span class="px-2 py-0.5 rounded text-xs text-gray-400 bg-gray-100 dark:bg-gray-800">No scores yet</span>
                                @endif
                                @if($isTop && $brandData['composite_score'] !== null)
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                    ✓ Best Complete Package
                                </span>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                {{ $brandData['scored_product_count'] }} / {{ $brandData['product_count'] }} products have evaluation data
                            </p>
                        </div>
                    </div>
                    {{-- Per-product breakdown --}}
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
            <div class="px-6 py-3 bg-amber-50/50 dark:bg-amber-950/10 border-t border-gray-100 dark:border-gray-800">
                <p class="text-xs text-gray-600 dark:text-gray-400">
                    <strong>⚠️ Package Purchase Note:</strong> Individual tools from different manufacturers may score higher in isolation (e.g. a member's preferred cutter is TNT), but the composite ranking above reflects the best-value choice when procuring a <em>complete {{ $group['category_name'] }}</em> set from one vendor — accounting for fleet consistency, training standardization, and parts/maintenance alignment.
                </p>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Footer Widgets (Finalists Table) --}}
    <div class="mt-6" wire:key="finalists-{{ $selectedSessionId }}">
        <x-filament-widgets::widgets
            :widgets="$this->getFooterWidgets()"
            :columns="$this->getFooterWidgetsColumns()"
        />
    </div>

    @else
    {{-- No Session State --}}
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
