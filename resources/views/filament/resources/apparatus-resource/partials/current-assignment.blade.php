@php
    /** @var \App\Support\Fleet\ApparatusCurrentAssignment $assignment */
    $alert = $assignment->activeAlert;
    $pending = $assignment->pendingChangeout;
    $last = $assignment->lastChangeout;
    $ticket = $assignment->relatedTicket;
@endphp

@unless ($assignment->isEmpty())
    <div @class(['hub-insp-card', 'hub-insp-card--warning' => $pending !== null || $alert?->isUpcomingSwap()]) data-current-assignment>
        <p><strong>Current assignment</strong></p>
        <dl class="hub-insp-facts hub-insp-facts--plain">
            @if ($alert)
                <div>
                    <dt>Active alert</dt>
                    <dd>
                        <a href="{{ \App\Filament\Resources\ApparatusOperationalAlertResource::getUrl('view', ['record' => $alert]) }}">{{ $alert->title }}</a>
                        @if ($alert->scheduleLabel())
                            &bull; {{ $alert->scheduleLabel() }}
                        @endif
                        @if ($assignment->otherActiveAlerts > 0)
                            &bull; {{ $assignment->otherActiveAlerts }} more
                        @endif
                    </dd>
                </div>
            @endif
            @if ($pending)
                <div>
                    <dt>Waiting on Fleet</dt>
                    <dd><a href="{{ \App\Filament\Resources\ApparatusChangeoutResource::getUrl('view', ['record' => $pending]) }}">Move to truck {{ $pending->to_vehicle_number }}</a></dd>
                </div>
            @endif
            @if ($ticket)
                <div>
                    <dt>Related ticket</dt>
                    <dd><a href="{{ \App\Filament\Resources\ApparatusServiceTicketResource::getUrl('view', ['record' => $ticket]) }}">{{ $ticket->ticket_number }}</a></dd>
                </div>
            @endif
            @if ($last)
                <div>
                    <dt>Last changeout</dt>
                    <dd>
                        <a href="{{ \App\Filament\Resources\ApparatusChangeoutResource::getUrl('view', ['record' => $last]) }}">{{ $last->unit_designation }}: truck {{ $last->from_vehicle_number ?: 'not recorded' }} to truck {{ $last->to_vehicle_number }}</a>
                        @if ($last->effective_at)
                            &bull; {{ $last->effective_at->setTimezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A') }}
                        @endif
                    </dd>
                </div>
            @endif
            @if ($assignment->openHandoffLines > 0)
                <div>
                    <dt>Open handoff lines</dt>
                    <dd data-open-handoff-lines>{{ $assignment->openHandoffLines }}</dd>
                </div>
            @endif
        </dl>
    </div>
@endunless
