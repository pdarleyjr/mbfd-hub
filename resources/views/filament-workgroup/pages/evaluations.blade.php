<x-filament-panels::page data-hub-ui="2" data-hub-portal="workgroups">
    {{-- Session switcher: the same Hub buttons as the dashboard's switcher. --}}
    @php
        $currentMember = method_exists($this, 'getCurrentMember') ? $this->getCurrentMember() : null;
        $attendedSessions = $currentMember ? $this->getAttendedSessions($currentMember) : collect();
    @endphp

    @if($attendedSessions->count() > 1)
    <div class="wg-context-switcher" role="group" aria-label="Select evaluation session">
        <span class="wg-switcher-label">Session</span>
        @foreach($attendedSessions as $session)
            <button
                type="button"
                wire:click="switchSession({{ $session->id }})"
                aria-pressed="{{ $selectedSession == $session->id ? 'true' : 'false' }}"
                @class([
                    'hub-btn',
                    'hub-btn--primary' => $selectedSession == $session->id,
                    'hub-btn--secondary' => $selectedSession != $session->id,
                ])
            >
                {{ $session->name }}
                @if($session->status === 'active')<span class="hub-tag hub-tag--success">Active</span>@endif
            </button>
        @endforeach
    </div>
    @elseif($attendedSessions->count() === 1)
    <dl class="wg-context" aria-label="Current session">
        <div class="wg-context__item">
            <dt>Session</dt>
            <dd>
                <span class="wg-context__value">{{ $attendedSessions->first()->name }}</span>
                @if($attendedSessions->first()->status === 'active')<span class="hub-tag hub-tag--success">Active</span>@endif
            </dd>
        </div>
    </dl>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
