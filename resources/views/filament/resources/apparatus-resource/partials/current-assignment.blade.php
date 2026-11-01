@php
    /** @var \App\Support\Fleet\ApparatusCurrentAssignment $assignment */
    $alert = $assignment->activeAlert;
    $last = $assignment->lastChangeout;
    $ticket = $assignment->relatedTicket;
@endphp

@unless ($assignment->isEmpty())
    <div @class(['hub-insp-card', 'hub-insp-card--warning' => $alert?->isUpcomingSwap()]) data-current-assignment>
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
            @if ($ticket)
                <div>
                    <dt>Related ticket</dt>
                    <dd><a href="{{ \App\Filament\Resources\ApparatusServiceTicketResource::getUrl('view', ['record' => $ticket]) }}">{{ $ticket->ticket_number }}</a></dd>
                </div>
            @endif
            @if ($last)
                <div>
                    <dt>Last truck change</dt>
                    <dd>
                        <a href="{{ \App\Filament\Resources\ApparatusChangeoutResource::getUrl('view', ['record' => $last]) }}">{{ $last->unit_designation }}: truck {{ $last->from_vehicle_number ?: 'not recorded' }} to truck {{ $last->to_vehicle_number }}</a>
                        @if ($last->effective_at)
                            &bull; {{ $last->effective_at->setTimezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A') }}
                        @endif
                        @unless ($last->isAcknowledged())
                            &bull; Not yet acknowledged
                        @endunless
                    </dd>
                </div>
            @endif
        </dl>
    </div>
@endunless
