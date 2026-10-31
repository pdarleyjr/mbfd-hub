{{-- MBFD: identical to the vendor view except the data-mbfd-alert-feed snapshot on the polled root. --}}
{{-- MBFD: the snapshot rides on the bell's own poll so open pages can show a pop-up for a NEW alert (resources/js/pwa/foreground-alerts.ts); it marks nothing read. --}}
{{-- MBFD: tests/Feature/Notifications/ForegroundAlertFeedTest.php fails if the vendor view drifts from this copy. --}}
@php
    $notifications = $this->getNotifications();
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    $mbfdAlertSnapshot = app(\App\Services\Notifications\ForegroundAlertPresenter::class)->snapshotForPage($notifications); // MBFD
@endphp

<div
    @if ($pollingInterval = $this->getPollingInterval())
        wire:poll.{{ $pollingInterval }}
    @endif
    class="flex"
    data-mbfd-alert-feed="{{ $mbfdAlertSnapshot === null ? '' : json_encode($mbfdAlertSnapshot) }}" {{-- MBFD --}}
>
    @if ($trigger = $this->getTrigger())
        <x-filament-notifications::database.trigger>
            {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
        </x-filament-notifications::database.trigger>
    @endif

    <x-filament-notifications::database.modal
        :notifications="$notifications"
        :unread-notifications-count="$unreadNotificationsCount"
    />

    @if ($broadcastChannel = $this->getBroadcastChannel())
        <x-filament-notifications::database.echo
            :channel="$broadcastChannel"
        />
    @endif
</div>
