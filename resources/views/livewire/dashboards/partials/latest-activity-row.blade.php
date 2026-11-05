{{--
    One event line, shared by the Admin Overview card and the Activity history page so they read the same.
    Expects: $event (OperationsActivityEvent), $time (display text), $timeIso. Every value is escaped; the row is a link
    to the record, or plain text when the record has no page.
--}}
<li class="mbfd-dash-activity__item" wire:key="activity-{{ $event->key }}" data-domain="{{ $event->domain }}">
    @if (filled($event->url))
        <a href="{{ $event->url }}" class="mbfd-dash-activity__link">
    @else
        <div class="mbfd-dash-activity__link">
    @endif
        <time class="mbfd-dash-activity__time" datetime="{{ $timeIso }}">{{ $time }}</time>
        <span class="mbfd-dash-activity__what">
            <span class="mbfd-dash-activity__line">
                @if (filled($event->subject))
                    <span class="mbfd-dash-row__subject">{{ $event->subject }}</span>
                @endif
                <span class="mbfd-dash-activity__kind">{{ $event->kind }}</span>
                @if ($event->actionRequired)
                    <span class="mbfd-dash-badge" data-severity="high">Needs action</span>
                @endif
                @if ($event->internal)
                    <span class="mbfd-dash-badge" data-severity="medium">Internal note</span>
                @endif
                @if (filled($event->ownerTag))
                    <span class="mbfd-dash-badge" data-severity="low">{{ $event->ownerTag }}<span class="sr-only"> is working this item</span></span>
                @endif
            </span>
            @if (filled($event->summary))
                <span class="mbfd-dash-row__summary">{{ $event->summary }}</span>
            @endif
        </span>
        <span class="mbfd-dash-activity__who">{{ $event->actorLabel }}</span>
        <span class="mbfd-dash-badge mbfd-dash-activity__status" data-plain>{{ $event->statusLabel }}</span>
    @if (filled($event->url))
        </a>
    @else
        </div>
    @endif
</li>
