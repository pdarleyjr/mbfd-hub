<?php

namespace App\Filament\Pages;

use App\Models\HubPushSubscription;
use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationRecipientEvaluator;
use App\Services\Notifications\PushDeviceRemovals;
use Filament\Facades\Filament;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;

/**
 * Every active member's alert settings.
 *
 * What appears is decided only by the registry, from the member's current roles and
 * permissions: categories appear and disappear as access changes and nothing about
 * eligibility is stored. A saved choice can only narrow what the member is eligible
 * for, and the server ignores any category or channel the member is not eligible for.
 */
class NotificationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static string $view = 'filament.pages.notification-settings';

    protected static ?string $title = 'Notification Settings';

    protected static ?string $navigationLabel = 'Notification settings';

    protected static ?string $navigationGroup = 'My work';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'notification-settings';

    public ?array $data = [];

    /** Read once per request; Livewire does not persist non-public properties. */
    private ?Collection $deviceCache = null;

    /** Shown in the Employee sidebar; the other panels reach it from their user menu. */
    public static function shouldRegisterNavigation(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'employee';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill($this->currentState());
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canManageNotificationSettings() ?? false;
    }

    public function form(Form $form): Form
    {
        $user = Auth::user();
        $categories = $user instanceof User ? $this->categoriesFor($user) : collect();
        $evaluator = app(NotificationRecipientEvaluator::class);
        $notes = $user instanceof User
            ? [
                NotificationCategory::CHANNEL_WEBPUSH => $evaluator->reachabilityNote($user, NotificationCategory::CHANNEL_WEBPUSH),
                NotificationCategory::CHANNEL_EMAIL => $evaluator->reachabilityNote($user, NotificationCategory::CHANNEL_EMAIL),
            ]
            : [];

        $sections = $categories
            ->groupBy(fn (NotificationCategory $category): string => $category->group)
            ->map(fn (Collection $group, string $heading): Section => Section::make($heading)
                ->schema($group->map(fn (NotificationCategory $category): Fieldset => $this->categoryFieldset($category, $notes))->values()->all())
                ->collapsible(false))
            ->values()
            ->all();

        return $form->schema($sections)->statePath('data');
    }

    /** @param  array<string, string|null>  $notes */
    private function categoryFieldset(NotificationCategory $category, array $notes): Fieldset
    {
        $key = $category->key;
        $toggles = [];

        if ($category->supports(NotificationCategory::CHANNEL_DATABASE)) {
            $locked = $category->isLocked(NotificationCategory::CHANNEL_DATABASE);
            $toggles[] = Toggle::make("{$key}.database")
                ->label($locked ? 'In-app (always on)' : 'In-app')
                ->disabled($locked)
                ->dehydrated(! $locked);
        }
        if ($category->supports(NotificationCategory::CHANNEL_WEBPUSH)) {
            $toggles[] = Toggle::make("{$key}.webpush")
                ->label('Push to my devices')
                ->helperText($notes[NotificationCategory::CHANNEL_WEBPUSH] ?? null);
        }
        if ($category->supports(NotificationCategory::CHANNEL_EMAIL)) {
            $toggles[] = Toggle::make("{$key}.email")
                ->label('City email')
                ->helperText($notes[NotificationCategory::CHANNEL_EMAIL] ?? null);
        }

        return Fieldset::make($category->label)
            ->schema([
                Placeholder::make("{$key}_about")->hiddenLabel()->content($category->description)->columnSpanFull(),
                ...$toggles,
            ])
            ->columns(3);
    }

    /**
     * The member's choices for the categories they may receive: the saved row, or
     * the category default when they have never chosen. Reading writes nothing.
     *
     * @return array<string, array<string, bool>>
     */
    private function currentState(): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        $evaluator = app(NotificationRecipientEvaluator::class);
        $rows = $user->notificationSubscriptions()->get()->keyBy('event_key');
        $state = [];
        foreach ($this->categoriesFor($user) as $category) {
            $effective = $evaluator->effectivePreference($category, $rows->get($category->key));
            $state[$category->key] = array_filter(
                $effective,
                fn (string $channel): bool => $category->supports($channel),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $state;
    }

    /** @return Collection<string, NotificationCategory> */
    private function categoriesFor(User $user): Collection
    {
        return app(NotificationCategoryRegistry::class)->forUser($user);
    }

    public function save(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        abort_unless($user?->canManageNotificationSettings(), 403);

        $eligible = $this->categoriesFor($user);
        $data = $this->form->getState();
        $evaluator = app(NotificationRecipientEvaluator::class);

        // Anything submitted for a category the member cannot receive is dropped and noted. Only
        // channel choices count: each category's description field ("{key}_about") holds no choice.
        $submittedState = (array) $this->data;
        $unavailable = collect(array_keys($submittedState))
            ->filter(fn (mixed $key): bool => is_array($submittedState[$key] ?? null))
            ->reject(fn (mixed $key): bool => is_string($key) && $eligible->has($key))
            ->values();
        if ($unavailable->isNotEmpty()) {
            Log::warning('Notification settings save ignored categories the member is not eligible for', [
                'user_id' => $user->id,
                'keys' => $unavailable->all(),
            ]);
        }

        $rows = $user->notificationSubscriptions()->get()->keyBy('event_key');
        foreach ($eligible as $key => $category) {
            $submitted = (array) ($data[$key] ?? []);
            $desired = [];
            foreach (NotificationCategory::ALL_CHANNELS as $channel) {
                $desired[$channel] = $category->supports($channel)
                    && ($category->isLocked($channel) || (bool) ($submitted[$channel] ?? false));
            }

            $row = $rows->get($key);
            // No row and nothing changed from the default: leave it unset so defaults can improve later.
            if (! $row instanceof UserNotificationSubscription
                && $desired === $evaluator->effectivePreference($category, null)) {
                continue;
            }

            $user->notificationSubscriptions()->updateOrCreate(['event_key' => $key], [
                'database_enabled' => $desired[NotificationCategory::CHANNEL_DATABASE],
                'webpush_enabled' => $desired[NotificationCategory::CHANNEL_WEBPUSH],
                'email_enabled' => $desired[NotificationCategory::CHANNEL_EMAIL],
            ]);
        }

        $notification = Notification::make()->title('Notification preferences saved');
        if ($unavailable->isNotEmpty()) {
            $notification->warning()->body('Some settings are not available to your account and were not saved.');
        } else {
            $notification->success()->body('Your alert choices are saved.');
        }
        $notification->send();
    }

    public function getVapidPublicKey(): string
    {
        return (string) config('webpush.vapid.public_key', '');
    }

    /**
     * The member's own push devices, newest first.
     *
     * @return Collection<int, HubPushSubscription>
     */
    public function getDevices(): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user instanceof User) {
            return collect();
        }

        /** @var Collection<int, HubPushSubscription> $devices */
        $devices = $this->deviceCache ??= $user->pushSubscriptions()->latest('id')->get();

        return $devices;
    }

    /** @return list<string> SHA-256 of each endpoint, so the browser can tell which entry is itself. */
    public function getEndpointHashes(): array
    {
        return $this->getDevices()->map(fn (HubPushSubscription $device): string => hash('sha256', $device->endpoint))->values()->all();
    }

    public function getPushSubscriptionCount(): int
    {
        return $this->getDevices()->count();
    }

    /** Remove one of the member's own devices; never anyone else's. */
    public function removeDevice(int $deviceId): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageNotificationSettings(), 403);

        $device = $user->pushSubscriptions()->whereKey($deviceId)->first();
        if ($device !== null) {
            app(PushDeviceRemovals::class)->remember((string) $device->endpoint);
            $device->delete();
        }
        $this->deviceCache = null;
        Notification::make()->success()->title('Device removed')->send();
    }

    /** The in-page enrollment script changed something; re-read the device list. */
    #[On('push-devices-changed')]
    public function refreshDevices(): void
    {
        // Rendering re-reads getDevices(); nothing else to do.
    }
}
