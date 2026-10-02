<x-filament-panels::page data-hub-ui="2" data-hub-portal="workgroups">
    @php
        $member = method_exists($this, 'getCurrentMember') ? $this->getCurrentMember() : null;
        $workgroups = method_exists($this, 'getAvailableWorkgroups') ? $this->getAvailableWorkgroups() : collect();
        $sessions = $member ? $this->getAccessibleSessions($member) : collect();
        $stats = $this->getWorkgroupStats();
    @endphp

    @if($workgroups->count() > 1)
        <div class="wg-context-switcher" role="group" aria-label="Select workgroup">
            <span class="hub-portal-eyebrow">Workgroup</span>
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
            <span class="hub-portal-eyebrow">Session</span>
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
    @elseif($sessions->count() === 1)
        <div class="hub-portal-actions">
            <x-heroicon-o-calendar class="h-5 w-5 text-primary-600" />
            <span class="text-sm font-semibold">{{ $sessions->first()->name }}</span>
            @if($sessions->first()->status === 'active')<span class="hub-tag hub-tag--success">Active session</span>@endif
        </div>
    @endif

    <dl class="wg-overview-stats" aria-label="Workgroup summary">
        @foreach($stats as $stat)
            <div class="wg-overview-stat">
                <dt class="text-sm font-medium text-gray-600">
                    @if($stat->getDescriptionIcon())
                        <x-dynamic-component :component="$stat->getDescriptionIcon()" class="wg-overview-stat-icon text-primary-600" />
                    @endif
                    {{ $stat->getLabel() }}
                </dt>
                <dd @class([
                    'mt-1 text-2xl font-semibold',
                    'text-green-700' => $stat->getColor() === 'success',
                    'text-amber-700' => $stat->getColor() === 'warning',
                    'text-red-700' => $stat->getColor() === 'danger',
                    'text-primary-700' => in_array($stat->getColor(), ['primary', 'info'], true),
                    'text-gray-900' => !in_array($stat->getColor(), ['success', 'warning', 'danger', 'primary', 'info'], true),
                ])>{{ $stat->getValue() }}
                    @if($stat->getDescription())<p class="mt-1 text-xs font-normal text-gray-600">{{ $stat->getDescription() }}</p>@endif
                </dd>
            </div>
        @endforeach
    </dl>

    <section aria-labelledby="workgroup-workspace-heading">
        <h2 id="workgroup-workspace-heading" class="hub-portal-section-title">Workgroup workspace</h2>
        <div class="wg-workspace-nav">
            <a href="{{ \App\Filament\Workgroup\Pages\Evaluations::getUrl() }}" class="wg-workspace-primary">
                <x-heroicon-o-clipboard-document-check />
                <div><span class="font-semibold">Continue evaluations</span><small>Review candidate products and record your findings.</small></div>
                <x-heroicon-o-arrow-right class="ml-auto" />
            </a>
            <nav class="wg-workspace-resources" aria-label="Shared workgroup resources">
                <a href="{{ \App\Filament\Workgroup\Pages\Files::getUrl() }}"><x-heroicon-o-document-duplicate /><span>Files</span><small>Session materials</small></a>
                <a href="{{ \App\Filament\Workgroup\Pages\Notes::getUrl() }}"><x-heroicon-o-pencil-square /><span>Notes</span><small>Personal and shared</small></a>
            </nav>
        </div>
    </section>
</x-filament-panels::page>
