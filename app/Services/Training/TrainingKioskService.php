<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Training\TrainingKioskGrant;
use App\Models\Training\TrainingRosterAudit;
use App\Models\Training\TrainingSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TrainingKioskService
{
    public function issue(TrainingSession $session, User $actor): string
    {
        Gate::forUser($actor)->authorize('launch', $session);

        return DB::transaction(function () use ($session, $actor): string {
            $session = TrainingSession::query()->lockForUpdate()->findOrFail($session->getKey());
            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['session' => 'Open sign-in before launching the roster.']);
            }
            $token = bin2hex(random_bytes(32));
            TrainingKioskGrant::query()->create([
                'training_session_id' => $session->getKey(),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours((int) config('training-roster.grant_hours', 12)),
                'created_by' => $actor->getKey(),
            ]);
            TrainingRosterAudit::query()->create([
                'training_session_id' => $session->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => 'kiosk.issued',
                'created_at' => now()->utc(),
                'after' => ['expires_in_hours' => (int) config('training-roster.grant_hours', 12)],
            ]);

            return (string) config('training-roster.scheme').'://'.(string) config('training-roster.host')
                .'/r/'.$session->public_id.'#key='.$token;
        });
    }

    public function authorizeDevice(Request $request, TrainingSession $session, string $token): void
    {
        $grant = TrainingKioskGrant::query()
            ->where('training_session_id', $session->getKey())
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($grant !== null && $session->status === 'open', 403, 'This sign-in link has ended. Ask the instructor to open a new link.');
        $request->session()->regenerate();
        $request->session()->put('training_grants.'.$session->getKey(), $grant->getKey());
    }

    public function requireDevice(Request $request, TrainingSession $session): int
    {
        $id = $request->session()->get('training_grants.'.$session->getKey());
        $valid = is_numeric($id) && TrainingKioskGrant::query()->whereKey($id)
            ->where('training_session_id', $session->getKey())
            ->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
        abort_unless($valid, 403, 'This sign-in link has ended. Ask the instructor to open a new link.');

        return (int) $id;
    }

    public function revoke(TrainingSession $session, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageKiosk', $session);
        DB::transaction(function () use ($session, $actor): void {
            TrainingSession::query()->lockForUpdate()->findOrFail($session->getKey());
            TrainingKioskGrant::query()->where('training_session_id', $session->getKey())
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);
            TrainingRosterAudit::query()->create([
                'training_session_id' => $session->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => 'kiosk.revoked',
                'created_at' => now()->utc(),
            ]);
        });
    }
}
