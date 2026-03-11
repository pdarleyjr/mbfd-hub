<x-filament-panels::page>
    {{-- Admin Stats Overview --}}
    @if(!empty($stats))
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: 0.875rem; margin-bottom: 1.5rem;">
        @foreach($stats as $stat)
        @php
            $iconColors = [
                'primary' => 'background-color: #FEF2F2; color: #B91C1C; border-color: #FECACA;',
                'success' => 'background-color: #ECFDF5; color: #065F46; border-color: #BBF7D0;',
                'warning' => 'background-color: #FEF9C3; color: #854D0E; border-color: #FDE68A;',
                'info'    => 'background-color: #EFF6FF; color: #1E40AF; border-color: #BFDBFE;',
                'gray'    => 'background-color: #F8F6F2; color: #57534E; border-color: #E8E5E0;',
            ];
            $style = $iconColors[$stat['color']] ?? $iconColors['gray'];
        @endphp
        <div class="wg-stat-card" style="text-align: left; padding: 1rem 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.625rem;">
                <div style="width: 2rem; height: 2rem; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; border: 1px solid; {{ $style }}">
                    <x-dynamic-component :component="$stat['icon']" style="width: 1rem; height: 1rem;"/>
                </div>
                <p class="wg-stat-label" style="text-transform: none; letter-spacing: normal;">{{ $stat['label'] }}</p>
            </div>
            <p class="wg-stat-value" style="font-size: 1.5rem;">{{ $stat['value'] }}</p>
            <p style="font-size: 0.6875rem; color: #A8A29E; margin-top: 0.25rem;">{{ $stat['desc'] }}</p>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Session Progress --}}
    @if($progress)
    <div class="wg-section">
        <div class="wg-section-header">
            <div class="wg-section-header-icon" style="background: linear-gradient(135deg, #B91C1C, #DC2626);">
                <x-heroicon-o-chart-bar class="w-5 h-5"/>
            </div>
            <h3 class="wg-section-title">Active Session Progress{{ $activeSession ? ': ' . $activeSession->name : '' }}</h3>
        </div>
        <div class="wg-section-body">
            <div class="wg-stats-row" style="grid-template-columns: repeat(4, 1fr);">
                @php
                    $progressItems = [
                        ['label' => 'Products', 'val' => $progress['total_products']],
                        ['label' => 'Evaluators', 'val' => $progress['total_members']],
                        ['label' => 'Submitted', 'val' => $progress['submitted_submissions']],
                        ['label' => 'Completion', 'val' => $progress['completion_percentage'] . '%'],
                    ];
                @endphp
                @foreach($progressItems as $pi)
                <div style="text-align: center; padding: 0.75rem; background-color: #F8F6F2; border-radius: 0.5rem; border: 1px solid #E8E5E0;">
                    <p class="wg-stat-label">{{ $pi['label'] }}</p>
                    <p class="wg-stat-value">{{ $pi['val'] }}</p>
                </div>
                @endforeach
            </div>
            @if($progress['completion_percentage'] > 0)
            <div class="wg-progress-track">
                <div class="wg-progress-fill" style="width: {{ min(100, $progress['completion_percentage']) }}%"></div>
            </div>
            @endif
        </div>
    </div>
    @endif
</x-filament-panels::page>