<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\UserNotificationSubscription;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class NotificationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static string $view = 'filament.pages.notification-settings';

    protected static ?string $title = 'Notification Settings';

    protected static ?string $slug = 'notification-settings';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var User|null $user */
        $user = Auth::user();

        $subscriptions = $user instanceof User
            ? $user->notificationSubscriptions()->get()->keyBy('event_key')
            : collect();
        $state = collect(array_keys(User::notificationPreferenceDefinitions()))
            ->mapWithKeys(function (string $key) use ($subscriptions): array {
                $subscription = $subscriptions->get($key);

                return [$key => [
                    'database' => $subscription instanceof UserNotificationSubscription
                        && $subscription->database_enabled,
                    'webpush' => $subscription instanceof UserNotificationSubscription
                        && $subscription->webpush_enabled,
                    'email' => $subscription instanceof UserNotificationSubscription
                        && $subscription->email_enabled,
                ]];
            })->all();
        $this->form->fill($state);
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canManageNotificationSettings() ?? false;
    }

    public function form(Form $form): Form
    {
        $definitions = User::notificationPreferenceDefinitions();

        return $form
            ->schema([
                ...collect($definitions)->map(
                    fn (array $definition, string $key): Fieldset => Fieldset::make($definition['label'])
                        ->schema([
                            Toggle::make("{$key}.database")->label('Admin inbox'),
                            Toggle::make("{$key}.webpush")->label('Web push'),
                            Toggle::make("{$key}.email")->label('City email'),
                        ])
                        ->columns(3),
                )->values()->all(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        abort_unless($user?->canManageNotificationSettings(), 403);

        $data = $this->form->getState();

        foreach (array_keys(User::notificationPreferenceDefinitions()) as $key) {
            $channels = (array) ($data[$key] ?? []);
            $user->notificationSubscriptions()->updateOrCreate(['event_key' => $key], [
                'database_enabled' => (bool) ($channels['database'] ?? false),
                'webpush_enabled' => (bool) ($channels['webpush'] ?? false),
                'email_enabled' => (bool) ($channels['email'] ?? false),
            ]);
        }

        Notification::make()
            ->success()
            ->title('Notification preferences saved')
            ->body('Each delivery channel has been updated independently.')
            ->send();
    }

    public function getVapidPublicKey(): string
    {
        return config('webpush.vapid.public_key', '');
    }

    public function getPushSubscriptionCount(): int
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->pushSubscriptions()->count() ?? 0;
    }
}
