{{-- MBFD: the vendor close button had no accessible name (axe button-name). --}}
<x-filament::icon-button
    color="gray"
    icon="heroicon-m-x-mark"
    icon-alias="notifications::notification.close-button"
    label="Dismiss notification"
    x-on:click="close"
    class="fi-no-notification-close-btn"
/>
