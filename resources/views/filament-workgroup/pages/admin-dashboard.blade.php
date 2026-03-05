{{-- Admin Dashboard View --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- AI Intelligence Summary Panel --}}
        <div 
            x-data="workgroupAIPanel()"
            class="bg-gradient-to-br from-violet-50 to-indigo-50 dark:from-violet-950/30 dark:to-indigo-950/30 border border-violet-200 dark:border-violet-700/50 rounded-xl p-5"
        >
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-violet-600 rounded-lg flex items-center justify-center shadow-sm flex-shrink-0">
                        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2M9 5H7a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2zm0 0h6a2 2 0 012 2v6a2 2 0 01-2 2h-6"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-violet-900 dark:text-violet-100">AI Intelligence Summary</h3>
                        <p class="text-xs text-violet-600 dark:text-violet-400">Powered by Llama 3.3 70B · Cloudflare Workers AI</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        @click="generateReport(false)"
                        :disabled="loading"
                        class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium rounded-lg bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        <svg x-show="!loading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <svg x-show="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span x-text="loading ? 'Generating...' : (report ? 'Regenerate' : 'Generate Report')"></span>
                    </button>
                    <button
                        x-show="report"
                        @click="copyReport()"
                        class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-lg bg-white dark:bg-gray-800 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-700 hover:bg-violet-50 transition-colors"
                        title="Copy report to clipboard"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                        Copy
                    </button>
                </div>
            </div>

            {{-- Loading State --}}
            <div x-show="loading" class="flex items-center gap-3 py-6">
                <div class="flex gap-1">
                    <span class="w-2 h-2 bg-violet-500 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                    <span class="w-2 h-2 bg-violet-500 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                    <span class="w-2 h-2 bg-violet-500 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
                </div>
                <p class="text-sm text-violet-600 dark:text-violet-400">Analyzing evaluation data and generating executive report...</p>
            </div>

            {{-- Error State --}}
            <div x-show="error && !loading" class="bg-red-50 dark:bg-red-950/30 border border-red-200 rounded-lg p-3">
                <p class="text-sm text-red-600 dark:text-red-400" x-text="error"></p>
            </div>

            {{-- Empty State --}}
            <div x-show="!loading && !report && !error" class="text-center py-6">
                <svg class="w-10 h-10 text-violet-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                </svg>
                <p class="text-sm text-violet-600 dark:text-violet-400 mb-1 font-medium">AI Executive Report Ready</p>
                <p class="text-xs text-violet-500">Click "Generate Report" to get an AI-powered executive summary with product rankings, finalist recommendations, and analysis for the Health & Safety Committee.</p>
            </div>

            {{-- Report Content --}}
            <div x-show="report && !loading" class="prose prose-sm dark:prose-invert max-w-none">
                <div class="bg-white dark:bg-gray-900 rounded-lg p-4 border border-violet-100 dark:border-violet-800 text-gray-800 dark:text-gray-200 text-sm leading-relaxed whitespace-pre-wrap font-sans max-h-96 overflow-y-auto" x-text="report"></div>
                <p class="text-xs text-violet-500 mt-2 flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Generated <span x-text="generatedAt"></span> · Use "🤖 Export AI Report" above for full spreadsheet with per-product analysis
                </p>
            </div>
            
            {{-- Copy success toast --}}
            <div x-show="copied" x-transition class="mt-2 text-xs text-emerald-600 flex items-center gap-1">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Report copied to clipboard!
            </div>
        </div>

        <script>
        function workgroupAIPanel() {
            return {
                loading: false,
                report: null,
                error: null,
                generatedAt: '',
                copied: false,
                
                async generateReport(force = false) {
                    this.loading = true;
                    this.error = null;
                    this.report = null;
                    
                    try {
                        const resp = await fetch('/api/workgroup/ai/executive-report', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-XSRF-TOKEN': this.getCsrfToken(),
                            },
                            body: JSON.stringify({ force })
                        });
                        
                        if (!resp.ok) {
                            const err = await resp.json().catch(() => ({}));
                            throw new Error(err.error || `Server error (${resp.status})`);
                        }
                        
                        const data = await resp.json();
                        this.report = data.report || data.error || 'No report generated';
                        this.generatedAt = data.fromCache ? 'from cache' : 'just now';
                    } catch (e) {
                        this.error = 'Failed to generate report: ' + e.message;
                    } finally {
                        this.loading = false;
                    }
                },
                
                async copyReport() {
                    if (!this.report) return;
                    try {
                        await navigator.clipboard.writeText(this.report);
                        this.copied = true;
                        setTimeout(() => { this.copied = false; }, 3000);
                    } catch (e) {
                        console.error('Copy failed', e);
                    }
                },
                
                getCsrfToken() {
                    const meta = document.querySelector('meta[name="csrf-token"]');
                    if (meta) return meta.getAttribute('content');
                    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
                    return match ? decodeURIComponent(match[1]) : '';
                }
            };
        }
        </script>

        {{-- Stats Overview --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($this->getWidgets() as $widget)
                @if($widget instanceof \App\Filament\Workgroup\Widgets\WorkgroupAdminStatsWidget)
                    {{ \Filament\Support\Facades\FilamentView::renderWidget($widget) }}
                @endif
            @endforeach
        </div>

        {{-- Session Progress --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($this->getWidgets() as $widget)
                @if($widget instanceof \App\Filament\Workgroup\Widgets\SessionProgressWidget)
                    {{ \Filament\Support\Facades\FilamentView::renderWidget($widget) }}
                @endif
            @endforeach
        </div>

        {{-- Quick Links --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center gap-2 mb-3">
                    <x-heroicon-o-trophy class="w-5 h-5 text-warning-500" />
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">View Results</h3>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    View detailed rankings, finalists, and export evaluation results.
                </p>
                <x-filament::button
                    :href="\App\Filament\Workgroup\Pages\SessionResultsPage::getUrl()"
                    color="primary"
                >
                    Go to Results
                </x-filament::button>
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center gap-2 mb-3">
                    <x-heroicon-o-users class="w-5 h-5 text-primary-500" />
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Manage Sessions</h3>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Create and manage evaluation sessions, add products, and configure categories.
                </p>
                <x-filament::button
                    :href="route('filament.workgroups.resources.workgroup-sessions.index')"
                    color="gray"
                >
                    Manage Sessions
                </x-filament::button>
            </div>
        </div>
    </div>
</x-filament-panels::page>
