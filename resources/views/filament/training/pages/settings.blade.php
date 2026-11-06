<x-filament-panels::page data-hub-ui="2" data-hub-portal="training">
    <div class="training-settings-grid">
        <x-filament::section>
            <x-slot name="heading">Push notifications</x-slot>

            <p class="training-note">Push alerts for this device, and which alerts you get, are in one place.</p>
            <a href="{{ \App\Filament\Pages\NotificationSettings::getUrl() }}" class="hub-btn hub-btn--primary mt-3">
                <x-heroicon-o-bell class="hub-btn__icon" aria-hidden="true" />
                Open Notification settings
            </a>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">In-app notifications</x-slot>

            <p class="training-note">In-app notifications appear under the bell in the top bar and refresh every 30 seconds. Open the bell to view and manage them.</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Profile</x-slot>

            <dl class="training-profile-facts">
                <div>
                    <dt>Name</dt>
                    <dd>{{ auth()->user()->name }}</dd>
                </div>
                <div>
                    <dt>Email</dt>
                    <dd>{{ auth()->user()->email }}</dd>
                </div>
                @if(auth()->user()->display_name)
                <div>
                    <dt>Display name</dt>
                    <dd>{{ auth()->user()->display_name }}</dd>
                </div>
                @endif
                @if(auth()->user()->rank)
                <div>
                    <dt>Rank</dt>
                    <dd>{{ auth()->user()->rank }}</dd>
                </div>
                @endif
            </dl>
        </x-filament::section>
    </div>
</x-filament-panels::page>
