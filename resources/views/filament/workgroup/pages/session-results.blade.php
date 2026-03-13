<x-filament-panels::page>
    {{-- Session Switcher Pill Navigation --}}
    @php $allSessions = $this->getAllSessions(); @endphp
    @if($allSessions->count() > 0)
    <div class="wg-session-pills">
        <button
            wire:click="switchSession(null)"
            class="wg-session-pill {{ $selectedSessionId === null ? 'wg-session-pill--overall' : '' }}"
        >
            <x-heroicon-o-squares-2x2 class="w-3.5 h-3.5" />
            Overall Results
        </button>
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

    {{-- Overall Project Banner --}}
    @if($selectedSessionId === null)
    <div class="wg-overall-banner" style="margin-bottom: 1.25rem;">
        <span style="opacity: 0.7;">📊</span> Viewing aggregate results across all sessions
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
                ['label' => 'Pending',     'val' => max(0, $progress['max_possible_submissions'] - $progress['submitted_submissions'])],
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

    {{-- AI Executive Report Panel --}}
    <div class="wg-ai-panel" wire:init="loadAiReport">
        <div class="wg-ai-panel-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div class="wg-ai-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                    </svg>
                </div>
                <div>
                    <h3 class="wg-ai-title">AI Executive Intelligence Report</h3>
                    <p class="wg-ai-subtitle">Committee-ready summary · Powered by Llama 3.3 70B</p>
                </div>
            </div>
            @if($aiReportLoaded && $aiReport)
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
                <button wire:click="regenerateAiReport" wire:loading.attr="disabled" class="wg-ai-btn wg-ai-btn--primary">
                    <svg style="width:1rem;height:1rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Regenerate
                </button>
                <button x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(@js($aiReport)); copied = true; setTimeout(() => copied = false, 2000)" class="wg-ai-btn wg-ai-btn--secondary">
                    <svg style="width:1rem;height:1rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                </button>
            </div>
            @endif
        </div>

        {{-- Shimmer skeleton while loading --}}
        @if(!$aiReportLoaded)
        <div style="padding: 1rem 0;">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <div style="display: flex; gap: 0.375rem;">
                    <span class="wg-shimmer" style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; display: inline-block;"></span>
                    <span class="wg-shimmer" style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; display: inline-block;"></span>
                    <span class="wg-shimmer" style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; display: inline-block;"></span>
                </div>
                <p style="font-size: 0.8125rem; color: #78716C;">Generating AI executive report...</p>
            </div>
            <div class="wg-shimmer" style="height: 0.875rem; width: 100%; margin-bottom: 0.5rem;"></div>
            <div class="wg-shimmer" style="height: 0.875rem; width: 85%; margin-bottom: 0.5rem;"></div>
            <div class="wg-shimmer" style="height: 0.875rem; width: 70%; margin-bottom: 0.5rem;"></div>
            <div class="wg-shimmer" style="height: 0.875rem; width: 100%; margin-bottom: 0.5rem;"></div>
            <div class="wg-shimmer" style="height: 0.875rem; width: 75%;"></div>
        </div>
        @elseif($aiReportError)
        <div style="background-color: #FEF2F2; border: 1px solid #FECACA; border-radius: 0.5rem; padding: 0.875rem;">
            <p style="font-size: 0.8125rem; color: #991B1B;">{{ $aiReportError }}</p>
            <button wire:click="loadAiReport" style="margin-top: 0.5rem; font-size: 0.75rem; color: #B91C1C; text-decoration: underline; cursor: pointer; background: none; border: none;">Retry</button>
        </div>
        @elseif($aiReport)
        <div class="wg-ai-body">{{ $aiReport }}</div>
        @else
        <div style="text-align: center; padding: 1.5rem 0;">
            <p style="font-size: 0.8125rem; color: #78716C; max-width: 28rem; margin: 0 auto;">Click "Generate Report" for a comprehensive executive summary.</p>
            <button wire:click="loadAiReport" class="wg-ai-btn wg-ai-btn--primary" style="margin-top: 0.75rem;">
                <svg style="width:1rem;height:1rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Generate Report
            </button>
        </div>
        @endif
    </div>

    {{-- SAVER Executive Report Generator --}}
    @if($selectedSessionId === null)
    <div class="wg-section" style="margin-bottom: 1.25rem;">
        <div class="wg-section-header" style="background: linear-gradient(135deg, #1E3A5F 0%, #2563EB 100%); color: #fff;">
            <div class="wg-section-header-icon" style="background: rgba(255,255,255,0.2);">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div style="flex: 1;">
                <h3 style="font-size: 1rem; font-weight: 700; color: #fff;">SAVER Executive Purchasing Report</h3>
                <p style="font-size: 0.75rem; color: rgba(255,255,255,0.7);">DHS-style assessment: Capability · Usability · Affordability · Maintainability · Deployability</p>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
                @if($saverReportHtml)
                <a href="{{ route('workgroup.saver-report') }}" target="_blank" class="wg-ai-btn wg-ai-btn--secondary" style="color: #fff; border-color: rgba(255,255,255,0.3);">
                    <svg style="width:1rem;height:1rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2z"/></svg>
                    Print Report
                </a>
                @endif
                <button
                    wire:click="generateSaverReport"
                    wire:loading.attr="disabled"
                    wire:target="generateSaverReport"
                    class="wg-ai-btn wg-ai-btn--primary"
                    style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);"
                >
                    <div wire:loading wire:target="generateSaverReport" style="display: flex; align-items: center; gap: 0.5rem;">
                        <svg style="width:1rem;height:1rem;animation:spin 1s linear infinite;" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity:0.25;"></circle><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" style="opacity:0.75;"></path></svg>
                        Generating...
                    </div>
                    <div wire:loading.remove wire:target="generateSaverReport" style="display: flex; align-items: center; gap: 0.5rem;">
                        <svg style="width:1rem;height:1rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        {{ $saverReportHtml ? 'Regenerate' : 'Generate SAVER Report' }}
                    </div>
                </button>
            </div>
        </div>

        {{-- SAVER Report Loading State --}}
        @if($saverReportLoading)
        <div style="padding: 2rem; text-align: center;">
            <div style="display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-bottom: 1rem;">
                <svg style="width:1.5rem;height:1.5rem;animation:spin 1s linear infinite;color:#2563EB;" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity:0.25;"></circle><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round" style="opacity:0.75;"></path></svg>
                <p style="font-size: 0.875rem; color: #57534E; font-weight: 500;">Analyzing evaluation data and generating SAVER report...</p>
            </div>
            <p style="font-size: 0.75rem; color: #A8A29E;">This may take 30-60 seconds depending on the amount of data.</p>
        </div>
        @endif

        {{-- SAVER Report Error --}}
        @if($saverReportError)
        <div style="padding: 1rem; background-color: #FEF2F2; border: 1px solid #FECACA; border-radius: 0.5rem; margin: 1rem;">
            <p style="font-size: 0.8125rem; color: #991B1B;">{{ $saverReportError }}</p>
            <button wire:click="generateSaverReport" style="margin-top: 0.5rem; font-size: 0.75rem; color: #B91C1C; text-decoration: underline; cursor: pointer; background: none; border: none;">Retry</button>
        </div>
        @endif

        {{-- SAVER Report Content --}}
        @if($saverReportHtml && !$saverReportLoading)
        <div style="padding: 1.25rem; font-size: 0.875rem; line-height: 1.6; color: #292524;">
            <div class="wg-saver-content">
                {!! $saverReportHtml !!}
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
    <div class="wg-section" style="margin-bottom: 1.25rem;">
        <div class="wg-section-header" style="background-color: #FEF9C3;">
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #D97706, #F59E0B);">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
            </div>
            <div style="flex: 1;">
                <h3 class="wg-section-title">T1 — Forcible Entry Tool</h3>
                <p class="wg-section-subtitle" style="color: #92400E; font-weight: 500;">For consideration in replacing the <strong>Rabbit Tool</strong> (Forcible entry tool currently in use)</p>
            </div>
        </div>
        <div class="wg-section-body">
            @php $t1 = $gtg['t1_standalone']; @endphp
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; background-color: #FFFBEB; border: 1px solid #FDE68A; border-radius: 0.625rem;">
                <div>
                    <h4 style="font-weight: 700; color: #292524; font-size: 1rem;">{{ $t1['name'] }}</h4>
                    @if($t1['brand'])
                    <p style="font-size: 0.75rem; color: #78716C;">{{ $t1['brand'] }}</p>
                    @endif
                </div>
                <div style="text-align: right;">
                    @if($t1['avg_score'] !== null)
                    <span class="wg-score-badge {{ $t1['avg_score'] >= 70 ? 'wg-score-badge--high' : ($t1['avg_score'] >= 50 ? 'wg-score-badge--mid' : 'wg-score-badge--low') }}" style="font-size: 1rem; padding: 0.375rem 0.875rem;">
                        {{ number_format($t1['avg_score'], 1) }}
                    </span>
                    @endif
                    <p style="font-size: 0.6875rem; color: #78716C; margin-top: 0.25rem;">{{ $t1['response_count'] }} responses</p>
                </div>
            </div>
            @if($t1['saver_breakdown']['capability'] !== null)
            <div style="display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap;">
                @foreach(['capability' => 'Capability', 'usability' => 'Usability', 'affordability' => 'Afford.', 'maintainability' => 'Maintain.', 'deployability' => 'Deploy.'] as $key => $label)
                <div style="flex: 1; min-width: 5rem; text-align: center; padding: 0.5rem; background-color: #F8F6F2; border-radius: 0.375rem;">
                    <p style="font-size: 0.625rem; font-weight: 600; color: #78716C; text-transform: uppercase; letter-spacing: 0.04em;">{{ $label }}</p>
                    <p class="wg-score" style="font-size: 1rem; color: #292524; margin-top: 0.125rem;">
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
    <div class="wg-section" style="margin-bottom: 1.25rem;">
        <div class="wg-section-header">
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #DC2626, #EF4444);">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243z"/></svg>
            </div>
            <div>
                <h3 class="wg-section-title">Forcible Entry — Cut-off Saws</h3>
                <p class="wg-section-subtitle">{{ count($gtg['cutoff_saws']) }} products ranked by overall score</p>
            </div>
        </div>
        @include('filament.workgroup.pages.partials.granular-tool-table', ['items' => $gtg['cutoff_saws']])
    </div>
    @endif

    {{-- ── Extrication Tool Brands — Overall Summary ── --}}
    @if(!empty($gtg['brand_overall']))
    <div class="wg-section" style="margin-bottom: 1.25rem;">
        <div class="wg-section-header">
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #7C3AED, #8B5CF6);">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            </div>
            <div>
                <h3 class="wg-section-title">Battery-Operated Extrication Tools — Brand Rankings</h3>
                <p class="wg-section-subtitle">Overall average score combining all tools per brand · Ranked #1 to #{{ count($gtg['brand_overall']) }}</p>
            </div>
        </div>
        <div style="padding: 0;">
            @foreach($gtg['brand_overall'] as $brandRank)
            @php
                $brandMedalClass = match($brandRank['rank']) {
                    1 => 'wg-brand-rank--gold',
                    2 => 'wg-brand-rank--silver',
                    3 => 'wg-brand-rank--bronze',
                    default => '',
                };
                $brandMedalBg = match($brandRank['rank']) {
                    1 => 'background-color: #C5A55A; color: #fff;',
                    2 => 'background-color: #A8A8A8; color: #fff;',
                    3 => 'background-color: #CD7F32; color: #fff;',
                    default => 'background-color: #E8E5E0; color: #78716C;',
                };
            @endphp
            <div class="wg-brand-rank {{ $brandMedalClass }}">
                <span class="wg-rank-medal" style="{{ $brandMedalBg }}">{{ $brandRank['rank'] }}</span>
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                        <span class="wg-brand-label" style="font-size: 1.0625rem;">{{ $brandRank['brand'] }}</span>
                        @if($brandRank['overall_avg'] !== null)
                        <span class="wg-brand-composite">{{ number_format($brandRank['overall_avg'], 1) }}</span>
                        @endif
                        <span style="font-size: 0.75rem; color: #78716C; padding: 0.125rem 0.5rem; background-color: #F0EDE8; border-radius: 9999px;">{{ $brandRank['tool_count'] }} tools</span>
                        @if($brandRank['rank'] === 1 && $brandRank['overall_avg'] !== null)
                        <span class="wg-best-package">🥇 Top Brand</span>
                        @endif
                    </div>
                </div>
                @if($brandRank['overall_avg'] !== null)
                <span class="wg-score-badge {{ $brandRank['overall_avg'] >= 70 ? 'wg-score-badge--high' : ($brandRank['overall_avg'] >= 50 ? 'wg-score-badge--mid' : 'wg-score-badge--low') }}" style="font-size: 1rem; padding: 0.375rem 0.75rem;">
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
    <div class="wg-section" style="margin-bottom: 1.25rem;">
        <div class="wg-section-header">
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #059669, #10B981);">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
            </div>
            <div>
                <h3 class="wg-section-title">Extrication — Spreaders</h3>
                <p class="wg-section-subtitle">{{ count($gtg['spreaders']) }} spreaders ranked independently</p>
            </div>
        </div>
        @include('filament.workgroup.pages.partials.granular-tool-table', ['items' => $gtg['spreaders']])
    </div>
    @endif

    {{-- ── Cutters Table ── --}}
    @if(!empty($gtg['cutters']))
    <div class="wg-section" style="margin-bottom: 1.25rem;">
        <div class="wg-section-header">
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #2563EB, #3B82F6);">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243z"/></svg>
            </div>
            <div>
                <h3 class="wg-section-title">Extrication — Cutters</h3>
                <p class="wg-section-subtitle">{{ count($gtg['cutters']) }} cutters ranked independently</p>
            </div>
        </div>
        @include('filament.workgroup.pages.partials.granular-tool-table', ['items' => $gtg['cutters']])
    </div>
    @endif

    {{-- ── Rams Table ── --}}
    @if(!empty($gtg['rams']))
    <div class="wg-section" style="margin-bottom: 1.25rem;">
        <div class="wg-section-header">
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #EA580C, #F97316);">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div>
                <h3 class="wg-section-title">Extrication — Rams</h3>
                <p class="wg-section-subtitle">{{ count($gtg['rams']) }} rams ranked independently</p>
            </div>
        </div>
        @include('filament.workgroup.pages.partials.granular-tool-table', ['items' => $gtg['rams']])
    </div>
    @endif

    @endif {{-- end granularToolGroupings --}}

    {{-- Category Rankings Grid --}}
    @if($categoryResults->isNotEmpty())
    <div style="margin-bottom: 1.5rem;">
        @foreach($categoryResults as $cat)
        <div class="wg-section">
            <div class="wg-section-header">
                <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #B91C1C, #DC2626);">
                    <x-heroicon-o-squares-2x2 class="w-5 h-5"/>
                </div>
                <div style="flex: 1;">
                    <h3 class="wg-section-title">{{ $cat['category_name'] }}</h3>
                    <p class="wg-section-subtitle">{{ $cat['total_products'] }} products · {{ $cat['eligible_products'] }} meet threshold</p>
                </div>
                @if($cat['top_products']->isNotEmpty())
                <span style="display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.625rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 500; background-color: #FEF9C3; color: #854D0E; border: 1px solid #FDE68A;">
                    <x-heroicon-o-trophy class="w-3.5 h-3.5"/>
                    Top: {{ $cat['top_products']->first()['product']->name ?? 'N/A' }}
                </span>
                @endif
            </div>

            <div style="overflow-x: auto;">
                <table class="wg-table">
                    <thead>
                        <tr>
                            <th style="width: 3rem; text-align: center;">#</th>
                            <th>Product</th>
                            <th style="text-align: center;">Overall</th>
                            <th style="text-align: center;" class="wg-saver-s">S</th>
                            <th style="text-align: center;" class="wg-saver-a">A</th>
                            <th style="text-align: center;" class="wg-saver-v">V</th>
                            <th style="text-align: center;" class="wg-saver-e">E</th>
                            <th style="text-align: center;" class="wg-saver-r">R</th>
                            <th style="text-align: center;">Advance</th>
                            <th style="text-align: center;">Responses</th>
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
                            $medalBg = match(true) {
                                $rank === 1 && $isFinalist => 'background-color: #C5A55A; color: #fff;',
                                $rank === 2 && $isFinalist => 'background-color: #A8A8A8; color: #fff;',
                                $rank === 3 && $isFinalist => 'background-color: #CD7F32; color: #fff;',
                                default => '',
                            };
                        @endphp
                        <tr>
                            <td style="text-align: center;">
                                @if($rank <= 3 && $isFinalist)
                                    <span class="wg-rank-medal {{ $medalClass }}" style="width: 1.5rem; height: 1.5rem; font-size: 0.625rem; {{ $medalBg }}">{{ $rank }}</span>
                                @else
                                    <span style="color: #A8A29E; font-size: 0.75rem;">{{ $rank }}</span>
                                @endif
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #292524;">{{ $item['product']->name }}</div>
                                @if($item['product']->manufacturer)
                                <div style="font-size: 0.6875rem; color: #A8A29E;">{{ $item['product']->manufacturer }} {{ $item['product']->model ? '· '.$item['product']->model : '' }}</div>
                                @endif
                            </td>
                            <td style="text-align: center;">
                                @if($score !== null)
                                <span class="wg-score-badge {{ $score >= 80 ? 'wg-score-badge--high' : ($score >= 60 ? 'wg-score-badge--mid' : 'wg-score-badge--low') }}">
                                    {{ number_format($score, 1) }}
                                </span>
                                @else
                                <span style="color: #D4D0CA;">—</span>
                                @endif
                            </td>
                            @foreach(['capability_avg', 'usability_avg', 'affordability_avg', 'maintainability_avg', 'deployability_avg'] as $ki => $saverKey)
                            <td style="text-align: center; font-size: 0.75rem;" class="wg-score">
                                @if($item[$saverKey])
                                    <span class="{{ ['wg-saver-s','wg-saver-a','wg-saver-v','wg-saver-e','wg-saver-r'][$ki] }}">{{ number_format($item[$saverKey], 0) }}</span>
                                @else
                                    <span style="color: #D4D0CA;">—</span>
                                @endif
                            </td>
                            @endforeach
                            <td style="text-align: center;">
                                @if($item['advance_yes'] > 0 || $item['advance_no'] > 0)
                                <span style="font-size: 0.75rem;">
                                    <span style="color: #059669; font-weight: 500;">{{ $item['advance_yes'] }}✓</span>
                                    @if($item['advance_no'] > 0)
                                    <span style="color: #DC2626; margin-left: 0.25rem;">{{ $item['advance_no'] }}✕</span>
                                    @endif
                                </span>
                                @if($item['deal_breakers'] > 0)
                                <span style="margin-left: 0.25rem; font-size: 0.75rem; color: #DC2626;" title="{{ $item['deal_breakers'] }} deal-breaker(s)">⚠️</span>
                                @endif
                                @else
                                <span style="color: #D4D0CA; font-size: 0.75rem;">—</span>
                                @endif
                            </td>
                            <td style="text-align: center;">
                                <span class="wg-score" style="font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 9999px;
                                    {{ $item['meets_threshold'] ? 'background-color: #EFF6FF; color: #1E40AF;' : 'background-color: #F5F3F0; color: #78716C;' }}">
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
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #2563EB, #0891B2);">
                <x-heroicon-o-scale class="w-5 h-5"/>
            </div>
            <div>
                <h2 class="wg-section-title">Competitor Group Rankings</h2>
                <p class="wg-section-subtitle">Products ranked against direct competitors within the same group</p>
            </div>
        </div>

        @foreach($competitorGroupRankings as $cgCategory)
        <div style="padding: 0.75rem 1.25rem; border-bottom: 1px solid #E8E5E0;">
            <h3 style="font-size: 0.875rem; font-weight: 700; color: #292524; margin-bottom: 0.75rem;">{{ $cgCategory['category_name'] }}</h3>

            @foreach($cgCategory['groups'] as $group)
            <div class="wg-competitor-group">
                <h4 class="wg-group-name">{{ $group['group_name'] }} <span class="wg-group-count">({{ $group['product_count'] }} products)</span></h4>
                <div>
                    @foreach($group['rankings'] as $rIdx => $ranking)
                    @php
                        $groupMedalBg = match(true) {
                            $rIdx === 0 => 'background-color: #C5A55A; color: #fff;',
                            $rIdx === 1 => 'background-color: #A8A8A8; color: #fff;',
                            $rIdx === 2 => 'background-color: #CD7F32; color: #fff;',
                            default => '',
                        };
                    @endphp
                    <div class="wg-group-item {{ $rIdx === 0 ? 'wg-group-item--leader' : '' }}">
                        <span class="wg-rank-medal" style="width: 1.5rem; height: 1.5rem; font-size: 0.625rem; {{ $groupMedalBg }}">{{ $rIdx + 1 }}</span>
                        <div style="flex: 1; min-width: 0;">
                            <span style="font-size: 0.8125rem; font-weight: 600; color: #292524;">{{ $ranking['name'] }}</span>
                            @if($ranking['brand'])
                            <span style="font-size: 0.6875rem; color: #A8A29E; margin-left: 0.25rem;">({{ $ranking['brand'] }})</span>
                            @endif
                        </div>
                        @if($ranking['avg_score'] !== null)
                        <span class="wg-score-badge {{ $ranking['avg_score'] >= 70 ? 'wg-score-badge--high' : 'wg-score-badge--mid' }}">
                            {{ number_format($ranking['avg_score'], 1) }}
                        </span>
                        @else
                        <span style="font-size: 0.75rem; color: #D4D0CA;">—</span>
                        @endif
                        <span style="font-size: 0.6875rem; color: #A8A29E;">{{ $ranking['response_count'] }} resp.</span>
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
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #D97706, #EA580C);">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <div>
                <h2 class="wg-section-title">Package Purchase Recommendation</h2>
                <p style="color: #78716C; font-size: 0.875rem; margin-bottom: 0.5rem;">Brand rankings by composite score — best value for complete tool set</p>
            </div>
        </div>

        @foreach($brandGroupedAnalysis as $group)
        <div style="padding: 1rem 1.25rem; border-bottom: 1px solid #E8E5E0;">
            <div style="margin-bottom: 0.75rem;">
                <h3 style="font-size: 0.9375rem; font-weight: 700; color: #292524;">{{ $group['category_name'] }}</h3>
                <p style="font-size: 0.6875rem; color: #A8A29E;">{{ $group['brand_count'] }} brands · {{ $group['total_products'] }} products compared</p>
            </div>

            @foreach($group['brand_rankings'] as $rank => $brandData)
            @php
                $medalClass = match($rank) { 0 => 'wg-brand-rank--gold', 1 => 'wg-brand-rank--silver', 2 => 'wg-brand-rank--bronze', default => '' };
                $brandMedalBg = match(true) {
                    $rank === 0 => 'background-color: #C5A55A; color: #fff;',
                    $rank === 1 => 'background-color: #A8A8A8; color: #fff;',
                    $rank === 2 => 'background-color: #CD7F32; color: #fff;',
                    default => '',
                };
            @endphp
            <div class="wg-brand-rank {{ $medalClass }}">
                <span class="wg-rank-medal" style="width: 2rem; height: 2rem; {{ $brandMedalBg }}">{{ $rank + 1 }}</span>
                <div style="flex: 1;">
                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem;">
                        <span class="wg-brand-label">{{ $brandData['brand'] }}</span>
                        @if($brandData['composite_score'] !== null)
                        <span class="wg-brand-composite">{{ number_format($brandData['composite_score'], 1) }}</span>
                        @endif
                        @if($rank === 0 && $brandData['composite_score'] !== null)
                        <span class="wg-best-package">✓ Best Complete Package</span>
                        @endif
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 0.375rem; margin-top: 0.5rem;">
                        @foreach($brandData['product_scores'] as $ps)
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.375rem 0.625rem; background-color: #F8F6F2; border-radius: 0.375rem; font-size: 0.75rem;">
                            <span style="color: #78716C; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-right: 0.5rem;">{{ $ps['product']->name }}</span>
                            @if($ps['avg_score'] !== null)
                            <span class="wg-score" style="flex-shrink: 0;
                                {{ $ps['avg_score'] >= 70 ? 'color: #059669;' : ($ps['avg_score'] >= 50 ? 'color: #D97706;' : 'color: #DC2626;') }}">
                                {{ number_format($ps['avg_score'], 1) }}
                            </span>
                            @else
                            <span style="color: #D4D0CA; flex-shrink: 0;">—</span>
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
    <div style="margin-top: 1.5rem;">
        <div class="wg-section-header" style="background: none; border: none; padding-left: 0;">
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #78716C, #57534E);">
                <x-heroicon-o-cube class="w-5 h-5"/>
            </div>
            <div>
                <h2 class="wg-section-title">Standalone Product Analysis</h2>
                <p class="wg-section-subtitle">Products evaluated independently — no direct competitors for ranking</p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(20rem, 1fr)); gap: 1rem; margin-top: 0.75rem;">
            @foreach($isolatedProducts as $iso)
            <div class="wg-isolated-product">
                <div class="wg-isolated-label">Standalone</div>
                <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 0.5rem;">
                    <div>
                        <h4 style="font-weight: 600; color: #292524; font-size: 0.875rem;">{{ $iso['name'] }}</h4>
                        <p style="font-size: 0.6875rem; color: #A8A29E;">
                            {{ $iso['brand'] ? $iso['brand'] . ' · ' : '' }}{{ $iso['category_name'] }}
                        </p>
                    </div>
                    @if($iso['avg_score'] !== null)
                    <span class="wg-score-badge {{ $iso['avg_score'] >= 70 ? 'wg-score-badge--high' : 'wg-score-badge--mid' }}" style="font-size: 0.875rem;">
                        {{ number_format($iso['avg_score'], 1) }}
                    </span>
                    @endif
                </div>
                <p style="font-size: 0.75rem; color: #A8A29E; font-style: italic; margin-bottom: 0.5rem;">{{ $iso['note'] }}</p>
                <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.6875rem; color: #78716C;">
                    <span>{{ $iso['response_count'] }} responses</span>
                    @if($iso['meets_threshold'])
                    <span style="color: #059669;">✓ Meets threshold</span>
                    @else
                    <span style="color: #D97706;">Below threshold</span>
                    @endif
                </div>
                @if(!empty($iso['saver_breakdown']) && $iso['saver_breakdown']['capability'] !== null)
                <div style="margin-top: 0.5rem; display: flex; gap: 0.375rem; font-size: 0.6875rem;">
                    @foreach(['capability' => 'S', 'usability' => 'A', 'affordability' => 'V', 'maintainability' => 'E', 'deployability' => 'R'] as $key => $label)
                    <span style="padding: 0.125rem 0.375rem; border-radius: 0.25rem; background-color: #F0EDE8; color: #57534E; font-variant-numeric: tabular-nums;" title="{{ ucfirst($key) }}">
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
    <div style="margin-top: 1.5rem;">
        @foreach($nonRankableFeedback as $nrCat)
        <div class="wg-section">
            <div class="wg-section-header" style="background-color: #F0FAF4;">
                <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #059669, #10B981);">
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
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span class="wg-avatar">{{ strtoupper(substr($fb['evaluator'] ?? '?', 0, 2)) }}</span>
                            <span class="wg-feedback-product">{{ $fb['product'] }}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            @if($fb['score'] !== null)
                            <span class="wg-score" style="font-size: 0.75rem; color: #44403C;">{{ number_format($fb['score'], 1) }}</span>
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
    <div class="wg-section" style="margin-top: 1.5rem;">
        <div class="wg-section-header">
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #C5A55A, #D97706);">
                <x-heroicon-o-trophy class="w-5 h-5"/>
            </div>
            <h3 class="wg-section-title">Top Finalists</h3>
        </div>
        <div style="overflow-x: auto;">
            <table class="wg-table">
                <thead>
                    <tr>
                        <th style="width: 3.5rem; text-align: center;">Rank</th>
                        <th>Category</th>
                        <th>Product</th>
                        <th>Manufacturer</th>
                        <th style="text-align: center;">Avg Score</th>
                        <th style="text-align: center;">Responses</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($finalists as $finalist)
                    @php
                        $finalistMedalBg = $finalist['rank'] === 1
                            ? 'background-color: #C5A55A; color: #fff;'
                            : 'background-color: #A8A8A8; color: #fff;';
                    @endphp
                    <tr style="{{ $finalist['rank'] === 1 ? 'background-color: #FFFBEB;' : '' }}">
                        <td style="text-align: center;">
                            <span class="wg-rank-medal" style="width: 1.75rem; height: 1.75rem; font-size: 0.6875rem; {{ $finalistMedalBg }}">{{ $finalist['rank'] }}</span>
                        </td>
                        <td style="font-size: 0.75rem; color: #78716C;">{{ $finalist['category'] }}</td>
                        <td style="font-weight: 600; color: #292524;">{{ $finalist['product']->name }}</td>
                        <td style="color: #78716C;">{{ $finalist['product']->manufacturer ?? '—' }}</td>
                        <td style="text-align: center;">
                            <span class="wg-score-badge {{ $finalist['rank'] === 1 ? 'wg-score-badge--high' : 'wg-score-badge--mid' }}">
                                {{ number_format($finalist['score'], 1) }}
                            </span>
                        </td>
                        <td style="text-align: center; color: #78716C;" class="wg-score">{{ $finalist['responses'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- No Session State --}}
    @if($allSessions->isEmpty())
    <div style="text-align: center; padding: 4rem 0;">
        <div style="width: 4rem; height: 4rem; margin: 0 auto 1rem; border-radius: 9999px; background-color: #F0EDE8; display: flex; align-items: center; justify-content: center;">
            <x-heroicon-o-calendar class="w-8 h-8" style="color: #A8A29E;"/>
        </div>
        <h3 style="font-size: 1.125rem; font-weight: 700; color: #292524; margin-bottom: 0.5rem;">No Active Session</h3>
        <p style="font-size: 0.875rem; color: #78716C; max-width: 28rem; margin: 0 auto;">There are no evaluation sessions available. Use the "Switch Session" button above to select a session, or ask an admin to create one.</p>
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
