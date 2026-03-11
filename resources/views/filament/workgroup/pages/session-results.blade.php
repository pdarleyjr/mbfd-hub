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

    {{-- Category Rankings Grid }}
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
                        @endphp
                        <tr>
                            <td style="text-align: center;">
                                @if($rank <= 3 && $isFinalist)
                                    <span class="wg-rank-medal {{ $medalClass ? str_replace('wg-brand-rank', 'wg-brand-rank', $medalClass) }}" style="width: 1.5rem; height: 1.5rem; font-size: 0.625rem;
                                        @if($rank === 1) background-color: #C5A55A; color: #fff;
                                        @elseif($rank === 2) background-color: #A8A8A8; color: #fff;
                                        @elseif($rank === 3) background-color: #CD7F32; color: #fff;
                                        @endif">{{ $rank }}</span>
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

    --}}

     Competitor Group Rankings --}}

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
                    <div class="wg-group-item {{ $rIdx === 0 ? 'wg-group-item--leader' : '' }}">
                        <span class="wg-rank-medal" style="width: 1.5rem; height: 1.5rem; font-size: 0.625rem;
                            @if($rIdx === 0) background-color: #C5A55A; color: #fff;
                            @elseif($rIdx === 1) background-color: #A8A8A8; color: #fff;
                            @elseif($rIdx === 2) background-color: #CD7F32; color: #fff;
                            @endif">{{ $rIdx + 1 }}</span>
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

    --}}

     Brand Group Purchase Analysis --}}

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
            @endphp
            <div class="wg-brand-rank {{ $medalClass }}">
                <span class="wg-rank-medal" style="width: 2rem; height: 2rem;
                    @if($rank === 0) background-color: #C5A55A; color: #fff;
                    @elseif($rank === 1) background-color: #A8A8A8; color: #fff;
                    @elseif($rank === 2) background-color: #CD7F32; color: #fff;
                    @endif">{{ $rank + 1 }}</span>
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

    --}}

     Isolated / Standalone Products --}}

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

    --}}

     Non-Rankable Feedback --}}

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
                    <tr style="{{ $finalist['rank'] === 1 ? 'background-color: #FFFBEB;' : '' }}">
                        <td style="text-align: center;">
                            <span class="wg-rank-medal" style="width: 1.75rem; height: 1.75rem; font-size: 0.6875rem;
                                @if($finalist['rank'] === 1) background-color: #C5A55A; color: #fff;
                                @else background-color: #A8A8A8; color: #fff;
                                @endif">{{ $finalist['rank'] }}</span>
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
    @else
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
