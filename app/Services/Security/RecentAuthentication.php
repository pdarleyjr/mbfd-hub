<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\Security\RecentAuthenticationAction;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

final class RecentAuthentication
{
    public function isSatisfied(
        DateTimeInterface|int|string|null $authenticatedAt,
        RecentAuthenticationAction $action,
        ?DateTimeInterface $now = null,
    ): bool {
        $threshold = config('security.recent_authentication.thresholds_seconds.'.$action->value);

        if ($threshold === null) {
            return true;
        }

        if ($authenticatedAt === null) {
            return false;
        }

        $authenticatedAt = $authenticatedAt instanceof DateTimeInterface
            ? CarbonImmutable::instance($authenticatedAt)
            : (is_int($authenticatedAt)
                ? CarbonImmutable::createFromTimestamp($authenticatedAt)
                : CarbonImmutable::parse($authenticatedAt));
        $now = $now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now);

        return $authenticatedAt->greaterThanOrEqualTo($now->subSeconds((int) $threshold));
    }

    public function isSatisfiedForRequest(Request $request, RecentAuthenticationAction $action): bool
    {
        $authenticatedAt = $request->hasSession()
            ? $request->session()->get((string) config('security.recent_authentication.session_key'))
            : null;

        return $this->isSatisfied($authenticatedAt, $action);
    }

    public function isSatisfiedForSession(Session $session, RecentAuthenticationAction $action): bool
    {
        return $this->isSatisfied(
            $session->get((string) config('security.recent_authentication.session_key')),
            $action,
        );
    }
}
