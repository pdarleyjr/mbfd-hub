<x-filament-panels::page class="wg-session-results" data-hub-ui="2" data-hub-portal="workgroups">
    {{-- Session Switcher Pill Navigation --}}
    @php $allSessions = $this->getAllSessions(); @endphp
    @if($allSessions->count() > 0)
    <div class="wg-session-pills">
        @foreach($allSessions as $daySess)
        <button
            wire:click="switchSession({{ $daySess->id }})"
            class="wg-session-pill {{ $selectedSessionId === $daySess->id ? 'wg-session-pill--active' : '' }}"
        >
            @if($daySess->status === 'active')
                <span class="wg-status-dot wg-status-dot--active"></span>
            @elseif($daySess->status === 'completed')
                <span class="wg-status-dot wg-status-dot--completed"></span>
            @endif
            {{ $daySess->name }}
        </button>
        @endforeach
    </div>
    @endif

    {{-- Session Progress Stats --}}
    @if($progress)
    <div class="wg-stats-row">
        @php
            $pStats = [
                ['label' => 'Products',    'val' => $progress['total_products']],
                ['label' => 'Evaluators',  'val' => $progress['total_members']],
                ['label' => 'Submitted',   'val' => $progress['submitted_submissions']],
                ['label' => 'In Progress', 'val' => $progress['draft_submissions']],
                ['label' => 'Pending',     'val' => max(0, $progress['max_possible_submissions'] - $progress['submitted_submissions'] - $progress['draft_submissions'])],
                ['label' => 'Completion',  'val' => $progress['completion_percentage'] . '%'],
            ];
        @endphp
        @foreach($pStats as $s)
        <div class="wg-stat-card">
            <p class="wg-stat-label">{{ $s['label'] }}</p>
            <p class="wg-stat-value">{{ $s['val'] }}</p>
        </div>
        @endforeach
    </div>
    @endif

    {{-- AI generation is an explicit manager-only action. --}}
    @if($this->canManageSelectedSession())
    <div class="wg-ai-panel" wire:init="loadAiReport">
        <div class="wg-ai-panel-header">
            <div class="sr-ai-heading">
                <div class="wg-ai-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v12a2 2 0 01-2 2h-2"/>
                    </svg>
                </div>
                <div>
                    <h3 class="wg-ai-title">AI Executive Intelligence Report</h3>
                    <p class="wg-ai-subtitle">Committee-ready summary · Powered by Llama 3.3 70B</p>
                </div>
            </div>
            @if($aiReportLoaded && $aiReport)
            <div class="wg-report-actions sr-actions">
                <a href="{{ route('reports.executive.pdf', ['session_id' => $selectedSessionId]) }}" target="_blank" class="wg-ai-btn wg-ai-btn--secondary">
                    <svg class="sr-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Export PDF
                </a>
                <button wire:click="regenerateAiReport" wire:loading.attr="disabled" class="wg-ai-btn wg-ai-btn--primary">
                    <svg class="sr-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Regenerate
                </button>
                <button x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(@js($aiReport)); copied = true; setTimeout(() => copied = false, 2000)" class="wg-ai-btn wg-ai-btn--secondary">
                    <svg class="sr-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                </button>
            </div>
            @endif
        </div>

        {{-- Shimmer skeleton while loading --}}
        @if(!$aiReportLoaded)
        <div class="sr-skeleton">
            <div class="sr-skeleton__lead">
                <div class="sr-skeleton__dots">
                    <span class="wg-shimmer sr-skeleton__dot"></span>
                    <span class="wg-shimmer sr-skeleton__dot"></span>
                    <span class="wg-shimmer sr-skeleton__dot"></span>
                </div>
                <p>Generating AI executive report...</p>
            </div>
            <div class="wg-shimmer sr-skeleton__line"></div>
            <div class="wg-shimmer sr-skeleton__line sr-skeleton__line--85"></div>
            <div class="wg-shimmer sr-skeleton__line sr-skeleton__line--70"></div>
            <div class="wg-shimmer sr-skeleton__line"></div>
            <div class="wg-shimmer sr-skeleton__line sr-skeleton__line--75"></div>
        </div>
        @elseif($aiReportError)
        <div class="sr-error">
            <p>{{ $aiReportError }}</p>
            <button wire:click="loadAiReport" class="sr-error__retry">Retry</button>
        </div>
        @elseif($aiReport)
        <div class="wg-ai-body">{{ $aiReport }}</div>
        @else
        <div class="sr-ai-empty">
            <p>Click "Generate Report" for a comprehensive executive summary.</p>
            <button wire:click="loadAiReport" class="wg-ai-btn wg-ai-btn--primary">
                <svg class="sr-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Generate Report
            </button>
        </div>
        @endif
    </div>
    @endif

    {{-- SAVER Executive Report Generator --}}
    @if($this->canManageSelectedSession())
    <div class="wg-section sr-section">
        <div class="wg-section-header wg-saver-header sr-saver__header">
            <div class="wg-section-header-icon sr-saver__icon">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div class="sr-saver__intro">
                <h3 class="sr-saver__title">SAVER Executive Purchasing Report</h3>
                <p class="sr-saver__subtitle">DHS-style assessment: Capability · Usability · Affordability · Maintainability · Deployability</p>
            </div>
            <div class="wg-report-actions sr-actions">
                @if($saverReportHtml)
                <a href="{{ route('reports.saver.pdf', ['session_id' => $selectedSessionId]) }}" target="_blank" class="wg-ai-btn wg-ai-btn--secondary sr-saver__btn">
                    <svg class="sr-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Export PDF
                </a>
                @endif
                <button
                    wire:click="generateSaverReport"
                    wire:loading.attr="disabled"
                    wire:target="generateSaverReport"
                    class="wg-ai-btn wg-ai-btn--primary sr-saver__btn"
                >
                    <div wire:loading.flex wire:target="generateSaverReport" class="sr-btn-state">
                        <svg class="sr-icon sr-spin" fill="none" viewBox="0 0 24 24"><circle class="sr-spin-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="sr-spin-arc" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path></svg>
                        Generating...
                    </div>
                    <div wire:loading.remove wire:target="generateSaverReport" class="sr-btn-state sr-btn-state--idle">
                        <svg class="sr-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        {{ $saverReportHtml ? 'Regenerate' : 'Generate SAVER Report' }}
                    </div>
                </button>
            </div>
        </div>

        {{-- SAVER Report Loading State --}}
        @if($saverReportLoading)
        <div class="sr-saver__loading">
            <div class="sr-saver__loading-lead">
                <svg class="sr-icon sr-icon--lg sr-spin sr-spin--accent" fill="none" viewBox="0 0 24 24"><circle class="sr-spin-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="sr-spin-arc" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path></svg>
                <p>Analyzing evaluation data and generating SAVER report...</p>
            </div>
            <p class="sr-caption">This may take 30-60 seconds depending on the amount of data.</p>
        </div>
        @endif

        {{-- SAVER Report Error --}}
        @if($saverReportError)
        <div class="sr-error sr-error--inset">
            <p>{{ $saverReportError }}</p>
            <button wire:click="generateSaverReport" class="sr-error__retry">Retry</button>
        </div>
        @endif

        {{-- SAVER Report Content --}}
        @if($saverReportHtml && !$saverReportLoading)
        <div class="sr-saver__content">
            <div class="wg-saver-content">
                {{-- SECURITY: render-boundary sanitization in addition to
                     WorkgroupAIService::sanitizeReportPayload(). --}}
                {!! \App\Support\Security\SafeHtml::report($saverReportHtml) !!}
            </div>
        </div>
        @endif
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════
         GRANULAR TOOL GROUPINGS — Keyword-filtered presentation tables
         Data source: $granularToolGroupings from EvaluationService
    ═══════════════════════════════════════════════════════════════════ --}}
    @if(!empty($granularToolGroupings))
    @php $gtg = $granularToolGroupings; @endphp

    {{-- ── T1 Standalone Table ── --}}
    @if($gtg['t1_standalone'])
    <div class="wg-section sr-section">
        <div class="wg-section-header">
            <div class="wg-section-header-icon">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
            </div>
            <div class="sr-grow">
                <h3 class="wg-section-title">T1 — Forcible Entry Tool</h3>
                <p class="wg-section-subtitle sr-weight-medium">For consideration in replacing the <strong>Rabbit Tool</strong> (Forcible entry tool currently in use)</p>
            </div>
            <a href="/workgroup-export/t1_standalone{{ $selectedSessionId ? '?session_id='.$selectedSessionId : '' }}" class="wg-export-link" title="Export CSV">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                CSV
            </a>
        </div>
        <div class="wg-section-body">
            @php $t1 = $gtg['t1_standalone']; @endphp
            <div class="sr-t1">
                <div>
                    <h4 class="sr-t1__name">{{ $t1['name'] }}</h4>
                    @if($t1['brand'])
                    <p class="sr-caption">{{ $t1['brand'] }}</p>
                    @endif
                </div>
                <div class="sr-t1__score">
                    @if($t1['avg_score'] !== null)
                    <span class="wg-score-badge {{ $t1['avg_score'] >= 70 ? 'wg-score-badge--high' : ($t1['avg_score'] >= 50 ? 'wg-score-badge--mid' : 'wg-score-badge--low') }} sr-badge--lg">
                        {{ number_format($t1['avg_score'], 1) }}
                    </span>
                    @endif
                    <p class="sr-caption">{{ $t1['response_count'] }} responses</p>
                </div>
            </div>
            @if($t1['saver_breakdown']['capability'] !== null)
            <div class="sr-saver-cells">
                @foreach(['capability' => 'Capability', 'usability' => 'Usability', 'affordability' => 'Afford.', 'maintainability' => 'Maintain.', 'deployability' => 'Deploy.'] as $key => $label)
                <div class="sr-saver-cell">
                    <p class="sr-saver-cell__label">{{ $label }}</p>
                    <p class="wg-score sr-saver-cell__value">
                        {{ $t1['saver_breakdown'][$key] !== null ? number_format($t1['saver_breakdown'][$key], 1) : '—' }}
                    </p>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ── Forcible Entry Cut-off Saws ── --}}
    @if(!empty($gtg['cutoff_saws']))
    <div class="wg-section sr-section">
        <div class="wg-section-header">
            <div class="wg-section-header-icon">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l4 4m4-4l4-4m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <div class="sr-grow">
                <h3 class="wg-section-title">Forcible Entry — Cut-off Saws</h3>
                <p class="wg-section-subtitle">{{ count($gtg['cutoff_saws']) }} products ranked by overall score</p>
            </div>
            <a href="/workgroup-export/cutoff_saws{{ $selectedSessionId ? '?session_id='.$selectedSessionId : '' }}" class="wg-export-link" title="Export CSV">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                CSV
            </a>
        </div>
        @include('filament.workgroup.pages.partials.granular-tool-table', ['items' => $gtg['cutoff_saws']])
    </div>
    @endif

    {{-- ── Extrication Tool Brands — Overall Summary ── --}}
    @if(!empty($gtg['brand_overall']))
    <div class="wg-section sr-section">
        <div class="wg-section-header">
            <div class="wg-section-header-icon">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            </div>
            <div class="sr-grow">
                <h3 class="wg-section-title">Battery-Operated Extrication Tools — Brand Rankings</h3>
                <p class="wg-section-subtitle">Overall average score combining all tools per brand · Ranked #1 to #{{ count($gtg['brand_overall']) }}</p>
            </div>
            <a href="/workgroup-export/brand_overall{{ $selectedSessionId ? '?session_id='.$selectedSessionId : '' }}" class="wg-export-link" title="Export CSV">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                CSV
            </a>
        </div>
        <div>
            @foreach($gtg['brand_overall'] as $brandRank)
            @php
                $brandMedalClass = match($brandRank['rank']) {
                    1 => 'wg-brand-rank--gold',
                    2 => 'wg-brand-rank--silver',
                    3 => 'wg-brand-rank--bronze',
                    default => '',
                };
                $brandMedalTone = match($brandRank['rank']) {
                    1 => 'sr-medal--gold',
                    2 => 'sr-medal--silver',
                    3 => 'sr-medal--bronze',
                    default => '',
                };
            @endphp
            <div class="wg-brand-rank {{ $brandMedalClass }}">
                <span class="wg-rank-medal {{ $brandMedalTone }}">{{ $brandRank['rank'] }}</span>
                <div class="sr-grow">
                    <div class="sr-brand-row">
                        <span class="wg-brand-label sr-brand-label--lg">{{ $brandRank['brand'] }}</span>
                        @if($brandRank['overall_avg'] !== null)
                        <span class="wg-brand-composite">{{ number_format($brandRank['overall_avg'], 1) }}</span>
                        @endif
                        <span class="sr-pill">{{ $brandRank['tool_count'] }} tools</span>
                        @if($brandRank['rank'] === 1 && $brandRank['overall_avg'] !== null)
                        <span class="wg-best-package">🥇 Top Brand</span>
                        @endif
                    </div>
                </div>
                @if($brandRank['overall_avg'] !== null)
                <span class="wg-score-badge {{ $brandRank['overall_avg'] >= 70 ? 'wg-score-badge--high' : ($brandRank['overall_avg'] >= 50 ? 'wg-score-badge--mid' : 'wg-score-badge--low') }} sr-badge--lg">
                    {{ number_format($brandRank['overall_avg'], 1) }}
                </span>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ── Spreaders Table ── --}}
    @if(!empty($gtg['spreaders']))
    <div class="wg-section sr-section">
        <div class="wg-section-header">
            <div class="wg-section-header-icon">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div class="sr-grow">
                <h3 class="wg-section-title">Extrication — Spreaders</h3>
                <p class="wg-section-subtitle">{{ count($gtg['spreaders']) }} spreaders ranked independently</p>
            </div>
            <a href="/workgroup-export/spreaders{{ $selectedSessionId ? '?session_id='.$selectedSessionId : '' }}" class="wg-export-link" title="Export CSV">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                CSV
            </a>
        </div>
        @include('filament.workgroup.pages.partials.granular-tool-table', ['items' => $gtg['spreaders']])
    </div>
    @endif

    {{-- ── Cutters Table ── --}}
    @if(!empty($gtg['cutters']))
    <div class="wg-section sr-section">
        <div class="wg-section-header">
            <div class="wg-section-header-icon">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243z"/></svg>
            </div>
            <div class="sr-grow">
                <h3 class="wg-section-title">Extrication — Cutters</h3>
                <p class="wg-section-subtitle">{{ count($gtg['cutters']) }} cutters ranked independently</p>
            </div>
            <a href="/workgroup-export/cutters{{ $selectedSessionId ? '?session_id='.$selectedSessionId : '' }}" class="wg-export-link" title="Export CSV">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                CSV
            </a>
        </div>
        @include('filament.workgroup.pages.partials.granular-tool-table', ['items' => $gtg['cutters']])
    </div>
    @endif

    {{-- ── Rams Table ── --}}
    @if(!empty($gtg['rams']))
    <div class="wg-section sr-section">
        <div class="wg-section-header">
            <div class="wg-section-header-icon">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div class="sr-grow">
                <h3 class="wg-section-title">Extrication — Rams</h3>
                <p class="wg-section-subtitle">{{ count($gtg['rams']) }} rams ranked independently</p>
            </div>
            <a href="/workgroup-export/rams{{ $selectedSessionId ? '?session_id='.$selectedSessionId : '' }}" class="wg-export-link" title="Export CSV">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                CSV
            </a>
        </div>
        @include('filament.workgroup.pages.partials.granular-tool-table', ['items' => $gtg['rams']])
    </div>
    @endif

    @endif {{-- end granularToolGroupings --}}

    {{-- Category Rankings Grid --}}
    @if($categoryResults->isNotEmpty())
    <div class="sr-stack">
        @foreach($categoryResults as $cat)
        <div class="wg-section">
            <div class="wg-section-header">
                <div class="wg-section-header-icon">
                    <x-heroicon-o-squares-2x2 class="w-5 h-5"/>
                </div>
                <div class="sr-grow">
                    <h3 class="wg-section-title">{{ $cat['category_name'] }}</h3>
                    <p class="wg-section-subtitle">{{ $cat['total_products'] }} products · {{ $cat['eligible_products'] }} meet threshold</p>
                </div>
                <div class="wg-header-actions">
                    @if($cat['top_products']->isNotEmpty())
                    <span class="sr-top-pill">
                        <x-heroicon-o-trophy class="w-3.5 h-3.5"/>
                        Top: {{ $cat['top_products']->first()['product']->name ?? 'N/A' }}
                    </span>
                    @endif
                    <a href="/workgroup-export/category_{{ urlencode($cat['category_name']) }}{{ $selectedSessionId ? '?session_id='.$selectedSessionId : '' }}" class="wg-export-link" title="Export CSV">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                        CSV
                    </a>
                </div>
            </div>

            <div class="sr-scroll" tabindex="0" role="region" aria-label="Category rankings">
                <table class="wg-table">
                    <thead>
                        <tr>
                            <th class="sr-col-rank">#</th>
                            <th>Product</th>
                            <th class="sr-center">Overall</th>
                            <th class="sr-center wg-saver-s">S</th>
                            <th class="sr-center wg-saver-a">A</th>
                            <th class="sr-center wg-saver-v">V</th>
                            <th class="sr-center wg-saver-e">E</th>
                            <th class="sr-center wg-saver-r">R</th>
                            <th class="sr-center">Advance</th>
                            <th class="sr-center">Responses</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($cat['rankings'] as $index => $item)
                        @php
                            $rank = $index + 1;
                            $isFinalist = $rank <= 2 && $item['meets_threshold'];
                            $score = $item['weighted_average'];
                            $medalClass = match(true) {
                                $rank === 1 && $isFinalist => 'wg-brand-rank--gold',
                                $rank === 2 && $isFinalist => 'wg-brand-rank--silver',
                                $rank === 3 && $isFinalist => 'wg-brand-rank--bronze',
                                default => '',
                            };
                            $medalTone = match(true) {
                                $rank === 1 && $isFinalist => 'sr-medal--gold',
                                $rank === 2 && $isFinalist => 'sr-medal--silver',
                                $rank === 3 && $isFinalist => 'sr-medal--bronze',
                                default => '',
                            };
                        @endphp
                        <tr>
                            <td class="sr-center">
                                @if($rank <= 3 && $isFinalist)
                                    <span class="wg-rank-medal sr-medal--xs {{ $medalClass }} {{ $medalTone }}">{{ $rank }}</span>
                                @else
                                    <span class="sr-rank-plain">{{ $rank }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="sr-product-name">{{ $item['product']->name }}</div>
                                @if($item['product']->manufacturer)
                                <div class="sr-product-sub">{{ $item['product']->manufacturer }} {{ $item['product']->model ? '· '.$item['product']->model : '' }}</div>
                                @endif
                            </td>
                            <td class="sr-center">
                                @if($score !== null)
                                <span class="wg-score-badge {{ $score >= 80 ? 'wg-score-badge--high' : ($score >= 60 ? 'wg-score-badge--mid' : 'wg-score-badge--low') }}">
                                    {{ number_format($score, 1) }}
                                </span>
                                @else
                                <span class="sr-dash">—</span>
                                @endif
                            </td>
                            @foreach(['capability_avg', 'usability_avg', 'affordability_avg', 'maintainability_avg', 'deployability_avg'] as $ki => $saverKey)
                            <td class="wg-score sr-center sr-cell-sm">
                                @if($item[$saverKey])
                                    <span class="{{ ['wg-saver-s','wg-saver-a','wg-saver-v','wg-saver-e','wg-saver-r'][$ki] }}">{{ number_format($item[$saverKey], 0) }}</span>
                                @else
                                    <span class="sr-dash">—</span>
                                @endif
                            </td>
                            @endforeach
                            <td class="sr-center">
                                @if($item['advance_yes'] > 0 || $item['advance_no'] > 0)
                                <span class="sr-adv">
                                    <span class="sr-adv__yes">{{ $item['advance_yes'] }}✓</span>
                                    @if($item['advance_no'] > 0)
                                    <span class="sr-adv__no">{{ $item['advance_no'] }}✕</span>
                                    @endif
                                </span>
                                @if($item['deal_breakers'] > 0)
                                <span class="sr-adv__flag" title="{{ $item['deal_breakers'] }} deal-breaker(s)">⚠️</span>
                                @endif
                                @else
                                <span class="sr-dash sr-caption">—</span>
                                @endif
                            </td>
                            <td class="sr-center">
                                <span class="wg-score sr-resp {{ $item['meets_threshold'] ? 'sr-resp--ok' : '' }}">
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

    {{-- Competitor Group Rankings --}}
    @if(!empty($competitorGroupRankings))
    <div class="wg-section">
        <div class="wg-section-header">
            <div class="wg-section-header-icon">
                <x-heroicon-o-scale class="w-5 h-5"/>
            </div>
            <div class="sr-grow">
                <h2 class="wg-section-title">Competitor Group Rankings</h2>
                <p class="wg-section-subtitle">Products ranked against direct competitors within the same group</p>
            </div>
            <a href="/workgroup-export/competitor_groups{{ $selectedSessionId ? '?session_id='.$selectedSessionId : '' }}" class="wg-export-link" title="Export CSV">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                CSV
            </a>
        </div>

        @foreach($competitorGroupRankings as $cgCategory)
        <div class="sr-cg">
            <h3 class="sr-cg__title">{{ $cgCategory['category_name'] }}</h3>

            @foreach($cgCategory['groups'] as $group)
            <div class="wg-competitor-group">
                <h4 class="wg-group-name">{{ $group['group_name'] }} <span class="wg-group-count">({{ $group['product_count'] }} products)</span></h4>
                <div>
                    @foreach($group['rankings'] as $rIdx => $ranking)
                    @php
                        $groupMedalTone = match(true) {
                            $rIdx === 0 => 'sr-medal--gold',
                            $rIdx === 1 => 'sr-medal--silver',
                            $rIdx === 2 => 'sr-medal--bronze',
                            default => '',
                        };
                    @endphp
                    <div class="wg-group-item {{ $rIdx === 0 ? 'wg-group-item--leader' : '' }}">
                        <span class="wg-rank-medal sr-medal--xs {{ $groupMedalTone }}">{{ $rIdx + 1 }}</span>
                        <div class="sr-grow">
                            <span class="sr-ranking-name">{{ $ranking['name'] }}</span>
                            @if($ranking['brand'])
                            <span class="sr-ranking-brand">({{ $ranking['brand'] }})</span>
                            @endif
                        </div>
                        @if($ranking['avg_score'] !== null)
                        <span class="wg-score-badge {{ $ranking['avg_score'] >= 70 ? 'wg-score-badge--high' : 'wg-score-badge--mid' }}">
                            {{ number_format($ranking['avg_score'], 1) }}
                        </span>
                        @else
                        <span class="sr-dash sr-caption">—</span>
                        @endif
                        <span class="sr-caption sr-caption--xs">{{ $ranking['response_count'] }} resp.</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
        @endforeach
    </div>
    @endif

    {{-- Brand Group Purchase Analysis --}}
    @if(!empty($brandGroupedAnalysis) && $session)
    <div class="wg-section">
        <div class="wg-section-header">
            <div class="wg-section-header-icon">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <div>
                <h2 class="wg-section-title">Package Purchase Recommendation</h2>
                <p class="sr-section-lead">Brand rankings by composite score — best value for complete tool set</p>
            </div>
        </div>

        @foreach($brandGroupedAnalysis as $group)
        <div class="sr-bg">
            <div class="sr-bg__head">
                <h3 class="sr-bg__title">{{ $group['category_name'] }}</h3>
                <p class="sr-caption sr-caption--xs">{{ $group['brand_count'] }} brands · {{ $group['total_products'] }} products compared</p>
            </div>

            @foreach($group['brand_rankings'] as $rank => $brandData)
            @php
                $medalClass = match($rank) { 0 => 'wg-brand-rank--gold', 1 => 'wg-brand-rank--silver', 2 => 'wg-brand-rank--bronze', default => '' };
                $brandMedalTone = match(true) {
                    $rank === 0 => 'sr-medal--gold',
                    $rank === 1 => 'sr-medal--silver',
                    $rank === 2 => 'sr-medal--bronze',
                    default => '',
                };
            @endphp
            <div class="wg-brand-rank {{ $medalClass }}">
                <span class="wg-rank-medal {{ $brandMedalTone }}">{{ $rank + 1 }}</span>
                <div class="sr-grow">
                    <div class="sr-brand-row sr-brand-row--tight">
                        <span class="wg-brand-label">{{ $brandData['brand'] }}</span>
                        @if($brandData['composite_score'] !== null)
                        <span class="wg-brand-composite">{{ number_format($brandData['composite_score'], 1) }}</span>
                        @endif
                        @if($rank === 0 && $brandData['composite_score'] !== null)
                        <span class="wg-best-package">✓ Best Complete Package</span>
                        @endif
                    </div>
                    <div class="sr-ps-grid">
                        @foreach($brandData['product_scores'] as $ps)
                        <div class="sr-ps">
                            <span class="sr-ps__name">{{ $ps['product']->name }}</span>
                            @if($ps['avg_score'] !== null)
                            <span class="wg-score sr-ps__score {{ $ps['avg_score'] >= 70 ? 'sr-tone--good' : ($ps['avg_score'] >= 50 ? 'sr-tone--mid' : 'sr-tone--bad') }}">
                                {{ number_format($ps['avg_score'], 1) }}
                            </span>
                            @else
                            <span class="sr-dash sr-ps__score">—</span>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        @endforeach
    </div>
    @endif

    {{-- Isolated / Standalone Products --}}
    @if(!empty($isolatedProducts))
    <div class="sr-block">
        <div class="wg-section-header sr-header--plain">
            <div class="wg-section-header-icon">
                <x-heroicon-o-cube class="w-5 h-5"/>
            </div>
            <div>
                <h2 class="wg-section-title">Standalone Product Analysis</h2>
                <p class="wg-section-subtitle">Products evaluated independently — no direct competitors for ranking</p>
            </div>
        </div>

        <div class="sr-iso-grid">
            @foreach($isolatedProducts as $iso)
            <div class="wg-isolated-product">
                <div class="wg-isolated-label">Standalone</div>
                <div class="sr-iso__head">
                    <div>
                        <h4 class="sr-iso__name">{{ $iso['name'] }}</h4>
                        <p class="sr-caption sr-caption--xs">
                            {{ $iso['brand'] ? $iso['brand'] . ' · ' : '' }}{{ $iso['category_name'] }}
                        </p>
                    </div>
                    @if($iso['avg_score'] !== null)
                    <span class="wg-score-badge {{ $iso['avg_score'] >= 70 ? 'wg-score-badge--high' : 'wg-score-badge--mid' }} sr-badge--md">
                        {{ number_format($iso['avg_score'], 1) }}
                    </span>
                    @endif
                </div>
                <p class="sr-iso__note">{{ $iso['note'] }}</p>
                <div class="sr-iso__meta">
                    <span>{{ $iso['response_count'] }} responses</span>
                    @if($iso['meets_threshold'])
                    <span class="sr-tone--good">✓ Meets threshold</span>
                    @else
                    <span class="sr-tone--mid">Below threshold</span>
                    @endif
                </div>
                @if(!empty($iso['saver_breakdown']) && $iso['saver_breakdown']['capability'] !== null)
                <div class="sr-chips">
                    @foreach(['capability' => 'S', 'usability' => 'A', 'affordability' => 'V', 'maintainability' => 'E', 'deployability' => 'R'] as $key => $label)
                    <span class="sr-chip" title="{{ ucfirst($key) }}">
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

    {{-- Non-Rankable Feedback --}}
    @if($nonRankableFeedback->isNotEmpty())
    <div class="sr-block">
        @foreach($nonRankableFeedback as $nrCat)
        <div class="wg-section">
            <div class="wg-section-header">
                <div class="wg-section-header-icon">
                    <x-heroicon-o-chat-bubble-left-right class="w-5 h-5"/>
                </div>
                <div>
                    <h2 class="wg-section-title">{{ $nrCat['category_name'] }}</h2>
                    <p class="wg-section-subtitle">{{ $nrCat['submissions_count'] }} submissions · Non-rankable feedback</p>
                </div>
            </div>
            <div class="wg-section-body">
                @foreach($nrCat['feedback'] as $fb)
                <div class="wg-feedback-quote">
                    <div class="wg-feedback-meta">
                        <div class="sr-row">
                            <span class="wg-avatar">{{ strtoupper(substr($fb['evaluator'] ?? '?', 0, 2)) }}</span>
                            <span class="wg-feedback-product">{{ $fb['product'] }}</span>
                        </div>
                        <div class="sr-row">
                            @if($fb['score'] !== null)
                            <span class="wg-score sr-fb-score">{{ number_format($fb['score'], 1) }}</span>
                            @endif
                            <span class="wg-feedback-evaluator">{{ $fb['evaluator'] ?? 'Unknown' }}</span>
                        </div>
                    </div>
                    @if(!empty($fb['comments']))
                    <div>
                        @foreach($fb['comments'] as $comment)
                        <p class="wg-feedback-text">{{ $comment }}</p>
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

    {{-- Finalists Summary --}}
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
    <div class="wg-section sr-block">
        <div class="wg-section-header">
            <div class="wg-section-header-icon">
                <x-heroicon-o-trophy class="w-5 h-5"/>
            </div>
            <div class="sr-grow">
                <h3 class="wg-section-title">Top Finalists</h3>
            </div>
            <a href="/workgroup-export/finalists{{ $selectedSessionId ? '?session_id='.$selectedSessionId : '' }}" class="wg-export-link" title="Export CSV">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                CSV
            </a>
        </div>
        <div class="sr-scroll" tabindex="0" role="region" aria-label="Finalist rankings">
            <table class="wg-table">
                <thead>
                    <tr>
                        <th class="sr-col-rank--wide">Rank</th>
                        <th>Category</th>
                        <th>Product</th>
                        <th>Manufacturer</th>
                        <th class="sr-center">Avg Score</th>
                        <th class="sr-center">Responses</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($finalists as $finalist)
                    @php
                        $finalistMedalTone = $finalist['rank'] === 1 ? 'sr-medal--gold' : 'sr-medal--silver';
                    @endphp
                    <tr class="{{ $finalist['rank'] === 1 ? 'sr-row--leader' : '' }}">
                        <td class="sr-center">
                            <span class="wg-rank-medal sr-medal--sm {{ $finalistMedalTone }}">{{ $finalist['rank'] }}</span>
                        </td>
                        <td class="sr-cell-small-muted">{{ $finalist['category'] }}</td>
                        <td class="sr-cell-strong">{{ $finalist['product']->name }}</td>
                        <td class="sr-cell-muted">{{ $finalist['product']->manufacturer ?? '—' }}</td>
                        <td class="sr-center">
                            <span class="wg-score-badge {{ $finalist['rank'] === 1 ? 'wg-score-badge--high' : 'wg-score-badge--mid' }}">
                                {{ number_format($finalist['score'], 1) }}
                            </span>
                        </td>
                        <td class="wg-score sr-center sr-cell-muted">{{ $finalist['responses'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- No Session State --}}
    @if($allSessions->isEmpty())
    <div class="sr-empty-state">
        <div class="sr-empty-state__icon">
            <x-heroicon-o-calendar class="w-8 h-8 sr-empty-state__glyph"/>
        </div>
        <h3 class="sr-empty-state__title">No Active Session</h3>
        <p class="sr-empty-state__text">There are no evaluation sessions available. Use the "Switch Session" button above to select a session, or ask an admin to create one.</p>
    </div>
    @endif

    <script>
    function workgroupAIPanel() {
        return {
            loading: false, report: null, error: null, copied: false,
            init() {
                this.generateReport(false);
            },
            async generateReport(force = false) {
                this.loading = true; this.error = null;
                if (force) this.report = null;
                try {
                    const resp = await fetch('/api/workgroup/ai/executive-report', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': this.getCsrf() },
                        body: JSON.stringify({ force, session_id: @js($selectedSessionId) })
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
