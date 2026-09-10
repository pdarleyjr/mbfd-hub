<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuthenticationSession;
use App\Models\User;
use App\Models\UserIdentityLink;
use App\Services\Identity\CityEmailVerificationService;
use App\Services\Identity\IdentityProviderService;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AccountController extends Controller
{
    public function __invoke(
        Request $request,
        ApplicationAccessRegistry $applications,
        CityEmailVerificationService $recovery,
        IdentityProviderService $identities,
    ): View {
        $user = $request->user('web');
        abort_unless($user instanceof User, 403);
        $link = $user->identityLinks()->where('provider', 'authentik')->first();
        $email = $recovery->connectedEmail($user);
        $maskedEmail = $email === null ? null : $this->maskEmail($email);
        $securityState = $link instanceof UserIdentityLink && is_array($link->security_state)
            ? $link->security_state : [];

        return view('account.show', [
            'user' => $user->fresh('employeeProfile'),
            'identityLink' => $link,
            'maskedRecoveryEmail' => $maskedEmail,
            'securityState' => $securityState,
            'enrollmentUrls' => $link instanceof UserIdentityLink && $identities->enabledFor($user)
                ? $identities->enrollmentUrls($user) : null,
            'activeSessionCount' => AuthenticationSession::query()
                ->where('user_id', $user->getKey())->whereNull('revoked_at')->count(),
            'applications' => $applications->applications(),
            'applicationStates' => $applications->states($user),
        ]);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.Str::repeat('•', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }
}
