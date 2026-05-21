<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors the bid app's "Bid Access PIN" setting (KV-backed, default 2300).
 *
 * The bid Worker is the system-of-record for the PIN — both this page and
 * the staging bid admin's /admin/settings/bid-pin write to the same KV key,
 * so a change in either surface takes effect immediately on the other.
 *
 * Auth: PORTAL_BID_READER bearer (already used for /verify-credentials and
 * /members/:id/credentials). No member JWT involved.
 */
class BidAccessPin extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Bid Access PIN';

    protected static ?string $title = 'Bid Access PIN';

    protected static ?string $slug = 'bid-access-pin';

    protected static ?string $navigationGroup = 'Bid Administration';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.admin.pages.bid-access-pin';

    public ?string $pin = null;

    public ?string $currentPin = null;

    public ?string $updatedAt = null;

    public ?string $updatedBy = null;

    public bool $isDefault = true;

    public ?string $fetchError = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->hasRole(['super_admin', 'admin']);
    }

    public function mount(): void
    {
        $this->refreshFromBidApp();
        $this->form->fill(['pin' => $this->currentPin]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('pin')
                    ->label('New PIN')
                    ->placeholder('e.g. 2300')
                    ->required()
                    ->minLength(4)
                    ->maxLength(8)
                    ->regex('/^\d{4,8}$/')
                    ->helperText('4–8 digits. Used by members to unlock the bid page.')
                    ->extraInputAttributes([
                        'inputmode' => 'numeric',
                        'pattern' => '\d{4,8}',
                        'autocomplete' => 'off',
                    ]),
            ])
            ->statePath('pin');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $pin = is_string($state) ? $state : (string) ($state['pin'] ?? '');

        if (! preg_match('/^\d{4,8}$/', $pin)) {
            Notification::make()
                ->title('PIN must be 4–8 digits.')
                ->danger()
                ->send();

            return;
        }

        $result = $this->writeToBidApp($pin);

        if ($result === null) {
            Notification::make()
                ->title('Could not save the PIN.')
                ->body($this->fetchError ?? 'Unknown error.')
                ->danger()
                ->send();

            return;
        }

        $this->applyPayload($result);
        $this->form->fill(['pin' => $this->currentPin]);

        Notification::make()
            ->title('Bid PIN updated.')
            ->body('Members will need the new PIN on their next sign-in.')
            ->success()
            ->send();
    }

    public function refresh(): void
    {
        $this->refreshFromBidApp();
        $this->form->fill(['pin' => $this->currentPin]);
    }

    public function resetToDefault(): void
    {
        $this->pin = '2300';
        $this->save();
    }

    private function refreshFromBidApp(): void
    {
        $payload = $this->readFromBidApp();
        if ($payload === null) {
            $this->currentPin = '—';

            return;
        }
        $this->applyPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyPayload(array $payload): void
    {
        $this->currentPin = (string) ($payload['pin'] ?? '');
        $this->updatedAt = isset($payload['updatedAt']) ? (string) $payload['updatedAt'] : null;
        $this->updatedBy = isset($payload['updatedBy']) ? (string) $payload['updatedBy'] : null;
        $this->isDefault = (bool) ($payload['isDefault'] ?? false);
        $this->fetchError = null;
    }

    private function bidApiBase(): ?string
    {
        $base = (string) (config('services.bid.console_url') ?? '');
        if ($base === '') {
            return null;
        }

        return rtrim(str_replace('staging.', 'api.staging.', $base), '/');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readFromBidApp(): ?array
    {
        $base = $this->bidApiBase();
        $token = (string) (config('services.bid.reader_token') ?? '');

        if ($base === null || $token === '') {
            $this->fetchError = 'Bid bridge not configured. Set BID_CONSOLE_URL and BID_READER_TOKEN.';

            return null;
        }

        try {
            $response = Http::timeout(8)
                ->withToken($token)
                ->acceptJson()
                ->get($base.'/api/portal/admin/bid-pin');
        } catch (\Throwable $e) {
            Log::warning('bid.pin.read.exception', ['error' => $e->getMessage()]);
            $this->fetchError = 'Could not reach the bid app. Try again in a minute.';

            return null;
        }

        if (! $response->successful()) {
            Log::warning('bid.pin.read.bad_status', ['status' => $response->status()]);
            $this->fetchError = 'Bid app returned status '.$response->status().'.';

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function writeToBidApp(string $pin): ?array
    {
        $base = $this->bidApiBase();
        $token = (string) (config('services.bid.reader_token') ?? '');

        if ($base === null || $token === '') {
            $this->fetchError = 'Bid bridge not configured. Set BID_CONSOLE_URL and BID_READER_TOKEN.';

            return null;
        }

        $user = auth()->user();
        $updatedBy = $user?->email ?? $user?->name ?? 'mbfd-hub-admin';

        try {
            $response = Http::timeout(8)
                ->withToken($token)
                ->acceptJson()
                ->put($base.'/api/portal/admin/bid-pin', [
                    'pin' => $pin,
                    'updatedBy' => (string) $updatedBy,
                ]);
        } catch (\Throwable $e) {
            Log::warning('bid.pin.write.exception', ['error' => $e->getMessage()]);
            $this->fetchError = 'Could not reach the bid app. Try again in a minute.';

            return null;
        }

        if (! $response->successful()) {
            Log::warning('bid.pin.write.bad_status', ['status' => $response->status()]);
            $this->fetchError = 'Bid app returned status '.$response->status().'.';

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }
}
