<x-filament-panels::page data-hub-ui="2" data-hub-portal="workgroups">
    {{-- One readout strip: label, figure, note. Tokens only, no tinted icon tiles. --}}
    @if(!empty($stats))
    <dl class="wg-overview-stats wg-overview-stats--counts" aria-label="Workgroup administration summary">
        @foreach($stats as $stat)
        <div class="wg-overview-stat">
            <dt class="wg-overview-stat__label">{{ $stat['label'] }}</dt>
            <dd @class([
                'wg-overview-stat__value',
                'wg-overview-stat__value--warning' => ($stat['color'] ?? null) === 'warning',
            ])>{{ $stat['value'] }}</dd>
            @if(filled($stat['desc'] ?? null))<dd class="wg-overview-stat__note">{{ $stat['desc'] }}</dd>@endif
        </div>
        @endforeach
    </dl>
    @endif

    @if($progress)
    <section class="wg-section wg-admin-progress" aria-labelledby="wg-admin-progress-title">
        <div class="wg-section-header">
            <h3 id="wg-admin-progress-title" class="wg-section-title">Active Session Progress{{ $activeSession ? ': ' . $activeSession->name : '' }}</h3>
        </div>
        <div class="wg-section-body">
            <dl class="wg-progress-figures">
                <div><dt>Products</dt><dd>{{ $progress['total_products'] }}</dd></div>
                <div><dt>Evaluators</dt><dd>{{ $progress['total_members'] }}</dd></div>
                <div><dt>Submitted</dt><dd>{{ $progress['submitted_submissions'] }}</dd></div>
                <div><dt>Completion</dt><dd>{{ $progress['completion_percentage'] }}%</dd></div>
            </dl>
            @if($progress['completion_percentage'] > 0)
            <div class="wg-progress-track" role="progressbar" aria-label="Session completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $progress['completion_percentage']) }}">
                <div class="wg-progress-fill" style="width: {{ min(100, $progress['completion_percentage']) }}%"></div>
            </div>
            @endif
        </div>
    </section>
    @endif
</x-filament-panels::page>
