<?php

namespace App\Services\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConferenceLaunchContextService
{
    private const SESSION_KEY = 'video_conferencing.launches';

    private const MAX_AGE_SECONDS = 3600;

    public function createStation(Request $request, ConferenceJoinRole $station): string
    {
        abort_unless($station->isStation(), 404);
        $now = now()->getTimestamp();
        $launches = collect($request->session()->get(self::SESSION_KEY, []))
            ->filter(fn (mixed $launch): bool => is_array($launch)
                && (int) ($launch['created_at'] ?? 0) >= $now - self::MAX_AGE_SECONDS)
            ->take(-10)
            ->all();
        $id = (string) Str::uuid();
        $launches[$id] = [
            'entry_mode' => 'station',
            'join_as' => $station->value,
            'created_at' => $now,
        ];
        $request->session()->put(self::SESSION_KEY, $launches);

        return $id;
    }

    public function station(Request $request, string $id): ConferenceJoinRole
    {
        $launch = $request->session()->get(self::SESSION_KEY.'.'.$id);
        abort_unless(
            is_array($launch)
                && ($launch['entry_mode'] ?? null) === 'station'
                && (int) ($launch['created_at'] ?? 0) >= now()->getTimestamp() - self::MAX_AGE_SECONDS,
            403,
            'This Station launch has expired. Open the Station page and try again.',
        );
        $role = ConferenceJoinRole::tryFrom((string) ($launch['join_as'] ?? ''));
        abort_unless($role?->isStation(), 403);

        return $role;
    }

    public function fingerprint(string $id): string
    {
        return hash_hmac('sha256', $id, (string) config('app.key'));
    }
}
