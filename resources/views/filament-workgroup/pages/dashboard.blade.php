<x-filament-panels::page data-hub-ui="2" data-hub-portal="workgroups">
    @php
        $member = method_exists($this, 'getCurrentMember') ? $this->getCurrentMember() : null;
        $workgroups = method_exists($this, 'getAvailableWorkgroups') ? $this->getAvailableWorkgroups() : collect();
        $sessions = $member ? $this->getAccessibleSessions($member) : collect();
        $stats = collect($this->getWorkgroupStats());
        // Names (workgroup, session) read as context; only the counts form the readout strip.
        [$counts, $context] = $stats->partition(fn ($stat): bool => is_numeric($stat->getValue()));
        $toneClass = fn ($stat): string => match ($stat->getColor()) {
            'success' => 'hub-tag--success',
            'warning' => 'hub-tag--warning',
            'danger' => 'hub-tag--danger',
            default => '',
        };
    @endphp

    @if($workgroups->count() > 1)
        <div class="wg-context-switcher" role="group" aria-label="Select workgroup">
            <span class="wg-switcher-label">Workgroup</span>
            @foreach($workgroups as $workgroup)
                <button
                    type="button"
                    wire:click="selectWorkgroup({{ $workgroup->id }})"
                    aria-pressed="{{ $member?->workgroup_id === $workgroup->id ? 'true' : 'false' }}"
                    @class([
                        'hub-btn',
                        'hub-btn--primary' => $member?->workgroup_id === $workgroup->id,
                        'hub-btn--secondary' => $member?->workgroup_id !== $workgroup->id,
                    ])
                >{{ $workgroup->name }}</button>
            @endforeach
        </div>
    @endif

    @if($sessions->count() > 1)
        <div class="wg-context-switcher" role="group" aria-label="Select evaluation session">
            <span class="wg-switcher-label">Session</span>
            @foreach($sessions as $session)
                <button
                    type="button"
                    wire:click="$set('selectedSessionId', {{ $session->id }})"
                    aria-pressed="{{ $selectedSessionId == $session->id ? 'true' : 'false' }}"
                    @class([
                        'hub-btn',
                        'hub-btn--primary' => $selectedSessionId == $session->id,
                        'hub-btn--secondary' => $selectedSessionId != $session->id,
                    ])
                >
                    {{ $session->name }}
                    @if($session->status === 'active')<span class="hub-tag hub-tag--success">Active</span>@endif
                    @if($session->status === 'completed')<span class="hub-tag hub-tag--info">Completed</span>@endif
                </button>
            @endforeach
        </div>
    @endif

    @if($context->isNotEmpty())
        <dl class="wg-context" aria-label="Current workgroup">
            @foreach($context as $stat)
                <div class="wg-context__item">
                    <dt>{{ $stat->getLabel() }}</dt>
                    <dd>
                        <span class="wg-context__value">{{ $stat->getValue() }}</span>
                        @if($stat->getDescription())<span class="hub-tag {{ $toneClass($stat) }}">{{ ucfirst((string) $stat->getDescription()) }}</span>@endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if($counts->isNotEmpty())
        <dl class="wg-overview-stats wg-overview-stats--counts" aria-label="Workgroup summary">
            @foreach($counts as $stat)
                <div class="wg-overview-stat">
                    <dt class="wg-overview-stat__label">{{ $stat->getLabel() }}</dt>
                    <dd @class([
                        'wg-overview-stat__value',
                        'wg-overview-stat__value--warning' => $stat->getColor() === 'warning' && (int) $stat->getValue() > 0,
                    ])>{{ $stat->getValue() }}</dd>
                    @if($stat->getDescription())<dd class="wg-overview-stat__note">{{ $stat->getDescription() }}</dd>@endif
                </div>
            @endforeach
        </dl>
    @endif
</x-filament-panels::page>
