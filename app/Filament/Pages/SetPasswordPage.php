<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CanonicalSessionPolicy;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Throwable;

/** @property Form $form */
class SetPasswordPage extends Page
{
    protected static ?string $slug = 'set-password';

    protected static string $view = 'filament.pages.set-password';

    protected static ?string $title = 'Set Your Password';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Password Change Required')
                    ->description('Set a new password before continuing to use this panel.')
                    ->schema([
                        Forms\Components\TextInput::make('current_password')
                            ->label('Current Password')
                            ->password()
                            ->required()
                            ->currentPassword()
                            ->revealable(),
                        Forms\Components\TextInput::make('password')
                            ->label('New Password')
                            ->password()
                            ->required()
                            ->confirmed()
                            ->rule(Password::default())
                            ->revealable(),
                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirm New Password')
                            ->password()
                            ->required()
                            ->revealable(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(
        AccountSecurityService $security,
        CanonicalSessionPolicy $sessionPolicy,
        SessionRegistry $sessions,
    ): void {
        $data = $this->form->getState();

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $hashedPassword = Hash::make($data['password']);
        $changedAt = CarbonImmutable::now();

        $user = $security->changePassword($user, $hashedPassword, $changedAt);
        $request = request();
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate(true);
        $policy = $sessionPolicy->resolve($request, $changedAt);

        try {
            $registered = $sessions->register(
                $user,
                $request->session()->getId(),
                $policy['context_class'],
                $changedAt,
                $policy['idle_expires_at'],
                $policy['absolute_expires_at'],
            );
        } catch (Throwable $exception) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Log::error('canonical_password_change_session_registration_failed', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            $this->redirect('/login');

            return;
        }

        $request->session()->put('auth.canonical_session_id', $registered->id);
        $request->session()->put(
            (string) config('security.recent_authentication.session_key'),
            $changedAt->getTimestamp(),
        );
        $request->session()->put('password_hash_web', $user->getAuthPassword());

        Notification::make()
            ->success()
            ->title('Password changed successfully')
            ->send();

        $this->redirect(Filament::getCurrentPanel()->getUrl() ?? url(Filament::getCurrentPanel()->getPath()));
    }
}
