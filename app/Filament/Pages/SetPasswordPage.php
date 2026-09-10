<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Rules\SafeNewPassword;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CanonicalLoginDestination;
use App\Services\Identity\CanonicalSessionPolicy;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/** @property Form $form */
class SetPasswordPage extends Page
{
    protected static ?string $slug = 'set-password';

    protected static string $view = 'filament.pages.set-password';

    protected static ?string $title = 'Change Your Password';

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
                Forms\Components\Section::make(fn (): string => auth()->user()?->must_change_password ? 'Password Change Required' : 'Change Password')
                    ->description(fn (): string => auth()->user()?->must_change_password
                        ? 'Set a private password before continuing to use MBFD Hub.'
                        : 'Enter your current password, then choose a new private password.')
                    ->schema([
                        Forms\Components\TextInput::make('current_password')
                            ->label('Current Password')
                            ->autocomplete('current-password')
                            ->password()
                            ->required()
                            ->currentPassword()
                            ->revealable(),
                        Forms\Components\TextInput::make('password')
                            ->label('New Password')
                            ->autocomplete('new-password')
                            ->password()
                            ->required()
                            ->confirmed()
                            ->different('current_password')
                            ->rule(Password::default())
                            ->rule(new SafeNewPassword)
                            ->revealable(),
                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirm New Password')
                            ->autocomplete('new-password')
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

        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $hashedPassword = Hash::make($data['password']);
        $changedAt = CarbonImmutable::now();

        $user = DB::transaction(function () use ($user, $data, $security, $hashedPassword, $changedAt): User {
            $current = User::query()->lockForUpdate()->find($user->id);
            // Form validation may have used a cached authenticated model. Do not
            // overwrite a concurrent reset or change a newly disabled account.
            if ($current === null || ! $current->isAuthenticationAllowed()
                || ! Hash::check($data['current_password'], $current->getAuthPassword())) {
                throw ValidationException::withMessages([
                    'data.current_password' => 'Your account or password has changed. Sign in again before changing your password.',
                ]);
            }
            if (Hash::check($data['password'], $current->getAuthPassword())) {
                throw ValidationException::withMessages([
                    'data.password' => 'Choose a password different from your current password.',
                ]);
            }

            return $security->changePassword($current, $hashedPassword, $changedAt);
        });
        $this->data = [];
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

        $handoff = app(CanonicalLoginDestination::class)->federation(
            $request->session()->pull(CanonicalLoginDestination::PASSWORD_RETURN_KEY),
        );
        $this->redirect($handoff ?? (Filament::getCurrentPanel()->getUrl() ?? url(Filament::getCurrentPanel()->getPath())));
    }
}
