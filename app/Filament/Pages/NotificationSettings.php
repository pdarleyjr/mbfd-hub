<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Forms\Components\Grid;
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

        $this->form->fill($user?->getResolvedNotificationPreferences() ?? User::DEFAULT_NOTIFICATION_PREFERENCES);
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
                Grid::make([
                    'default' => 1,
                    'xl' => 2,
                ])
                    ->schema(
                        collect($definitions)
                            ->map(
                                fn (array $definition, string $key): Toggle => Toggle::make($key)
                                    ->label($definition['label'])
                                    ->helperText($definition['description'])
                                    ->default(User::DEFAULT_NOTIFICATION_PREFERENCES[$key] ?? true)
                                    ->inline(false)
                            )
                            ->values()
                            ->all()
                    ),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        abort_unless($user?->canManageNotificationSettings(), 403);

        $data = $this->form->getState();

        $preferences = collect(array_keys(User::DEFAULT_NOTIFICATION_PREFERENCES))
            ->mapWithKeys(fn (string $key): array => [$key => (bool) ($data[$key] ?? true)])
            ->all();

        $user->forceFill([
            'notification_preferences' => $preferences,
        ])->save();

        Notification::make()
            ->success()
            ->title('Notification preferences saved')
            ->body('Submission alerts for this account have been updated.')
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
