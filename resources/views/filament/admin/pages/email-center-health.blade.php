@php
    use App\Support\Mail\MailPresentation as Words;

    $usageUrl = null;
    try {
        $usageUrl = \App\Filament\Pages\CommunicationsUsage::canAccess() ? \App\Filament\Pages\CommunicationsUsage::getUrl() : null;
    } catch (\Throwable) {
        $usageUrl = null;
    }
    $dns = static fn (string $state): array => match ($state) {
        'present' => ['Found', 'good'],
        'missing' => ['Not found', 'bad'],
        default => ['Not checked', 'neutral'],
    };
@endphp
<div class="mail-health">
    <div class="mail-reader__bar">
        <button type="button" class="mail-btn mail-btn--ghost mail-back" wire:click="openFolder('inbox')">
            <x-filament::icon icon="heroicon-m-arrow-left" class="mail-icon" />
            Back to Inbox
        </button>
    </div>
    <header class="mail-reader__header">
        <h2 class="mail-reader__subject">Email health</h2>
        <p class="mail-muted">How sending is going, from what the Hub already knows. Nothing here contacts Cloudflare, and no message content is shown.</p>
    </header>

    @if ($healthError || $health === null)
        <div class="mail-state" role="alert">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mail-state__icon" />
            <p class="mail-state__title">The health report could not be built</p>
            <p class="mail-muted">Your mail is not affected. Try again in a moment.</p>
            <button type="button" class="mail-btn mail-btn--secondary" wire:click="openFolder('health')">Try again</button>
        </div>
    @else
        @php
            $sending = $health['sending'];
            $open = $sending['state'] === 'open';
        @endphp
        <section class="mail-health__card" aria-labelledby="mail-health-sending">
            <h3 id="mail-health-sending">Sending</h3>
            <p>
                <span class="mail-badge mail-badge--{{ $open ? 'good' : 'warn' }}">{{ $open ? 'Open' : 'Paused' }}</span>
                {{ $sending['summary'] }}
            </p>
            <dl class="mail-health__facts">
                <div><dt>Used this billing cycle</dt><dd>{{ number_format((int) $sending['used']) }} of {{ number_format((int) $sending['ceiling']) }}</dd></div>
                <div><dt>Used today</dt><dd>{{ number_format((int) $sending['daily_used']) }} of {{ number_format((int) $sending['daily_quota']) }}</dd></div>
                <div><dt>People per message</dt><dd>{{ $sending['recipient_limit'] }} at most</dd></div>
                <div><dt>Messages per minute</dt><dd>{{ $sending['minute_limit'] }} at most</dd></div>
                @if ($sending['backoff_until'])
                    <div><dt>Paused until</dt><dd>{{ Words::fullTime(\Carbon\CarbonImmutable::parse($sending['backoff_until'])) }}</dd></div>
                @endif
            </dl>
        </section>

        <section class="mail-health__card" aria-labelledby="mail-health-outcomes">
            <h3 id="mail-health-outcomes">What happened to messages in the last 30 days</h3>
            <dl class="mail-health__facts">
                @foreach ($health['outcome_labels'] as $key => $text)
                    <div>
                        <dt>{{ $text }}</dt>
                        <dd>
                            @if ($health['outcomes_30d'][$key] === null)
                                {{ $health['complaints_unavailable_label'] }}
                            @else
                                {{ number_format((int) $health['outcomes_30d'][$key]) }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="mail-health__card" aria-labelledby="mail-health-reconcile">
            <h3 id="mail-health-reconcile">Delivery updates</h3>
            @php $reconciliation = $health['reconciliation']; @endphp
            <p>
                <span class="mail-badge mail-badge--{{ $reconciliation['enabled'] ? 'good' : 'neutral' }}">{{ $reconciliation['enabled'] ? 'On' : 'Off' }}</span>
                @if ($reconciliation['last_completed_at'])
                    Last complete update: {{ Words::fullTime(\Carbon\CarbonImmutable::parse($reconciliation['last_completed_at'])) }}.
                @else
                    No complete update has run yet.
                @endif
            </p>
            @if ($reconciliation['last_error'])
                <p class="mail-callout mail-callout--warn" role="note">The last attempt reported: {{ $reconciliation['last_error'] }}</p>
            @endif
        </section>

        <section class="mail-health__card" aria-labelledby="mail-health-auth">
            <h3 id="mail-health-auth">Sender authentication for {{ $health['authentication']['domain'] }}</h3>
            <dl class="mail-health__facts">
                @foreach (['spf' => 'SPF record', 'dmarc' => 'DMARC record', 'dkim' => 'DKIM key'] as $key => $text)
                    @php [$word, $tone] = $dns($health['authentication'][$key]); @endphp
                    <div><dt>{{ $text }}</dt><dd><span class="mail-badge mail-badge--{{ $tone }}">{{ $word }}</span></dd></div>
                @endforeach
            </dl>
        </section>

        <ul class="mail-health__notes">
            @foreach ($health['notes'] as $note)
                <li class="mail-muted">{{ $note }}</li>
            @endforeach
        </ul>
        @if ($usageUrl)
            <p><a class="mail-link" href="{{ $usageUrl }}">Open Communications Usage</a></p>
        @endif
        <section class="mail-health__card" aria-labelledby="mail-health-records" data-mail-records>
            <h3 id="mail-health-records">Records and exports</h3>
            <p class="mail-muted">The full log of every message, with filters and Excel or PDF export.</p>
            <p>
                <a class="mail-link" href="{{ \App\Filament\Resources\InboundEmailResource::getUrl('index', ['records' => 1]) }}">Received mail log</a>
                &nbsp;
                <a class="mail-link" href="{{ \App\Filament\Resources\OutboundEmailResource::getUrl('index', ['records' => 1]) }}">Sent mail log</a>
            </p>
        </section>
    @endif
</div>
