<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Models\User;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Home page visit counter and the list of members who signed in recently.
 *
 * A visit is one successful render of Home by a signed-in member (see HomeController). Visits are counted in one
 * row per UTC hour, after the response has been sent, and nothing here may slow or break Home: every read and
 * write is guarded, and a table that cannot be read (an old image, a database blip) just hides the card.
 *
 * "Past 24 hours" is a rolling window over those hourly rows, so old hours fall away on their own and nothing
 * has to run at midnight. History counted before this feature existed lives in `imported_views`.
 */
final class HomeActivity
{
    public const TABLE = 'home_page_view_hours';

    private const SUMMARY_CACHE_KEY = 'home-activity.summary';

    /** Long enough that open pages polling every minute cannot pile up queries, short enough to feel live. */
    private const SUMMARY_CACHE_SECONDS = 15;

    private const WINDOW_HOURS = 24;

    /** More than the department has members; a ceiling so the list can never grow without bound. */
    private const SIGNED_IN_LIMIT = 1000;

    /** Prefetches and HEAD probes are the browser or a monitor guessing, not a member looking at Home. */
    public function countsAsVisit(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        foreach (['Sec-Purpose', 'Purpose'] as $header) {
            if (stripos((string) $request->headers->get($header), 'prefetch') !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Count this request as a visit once the response has gone out. Returns whether it will be counted, so the
     * page can show a total that already includes the member's own visit.
     */
    public function recordVisit(Request $request): bool
    {
        if (! $this->countsAsVisit($request)) {
            return false;
        }

        $hour = $this->hourKey($this->now());
        // Terminating callbacks live as long as the application object. In a worker or a test that serves many
        // requests, this one must still run only for the request that registered it.
        $done = false;
        app()->terminating(function () use ($hour, &$done): void {
            if ($done) {
                return;
            }
            $done = true;
            $this->guarded(function () use ($hour): void {
                DB::transaction(static function () use ($hour): void {
                    // One atomic statement on Postgres and SQLite alike; `views` is the only column it ever changes.
                    DB::table(self::TABLE)->upsert(
                        [['hour' => $hour, 'views' => 1, 'imported_views' => 0]],
                        ['hour'],
                        ['views' => DB::raw(self::TABLE.'.views + 1')],
                    );
                });
                Cache::forget(self::SUMMARY_CACHE_KEY);
            });
        });

        return true;
    }

    /**
     * @return array{past_24_hours: int, total: int, since: ?string}|null null when the counts cannot be read
     */
    public function summary(bool $includingThisVisit = false): ?array
    {
        $totals = $this->guarded(fn (): array => Cache::remember(
            self::SUMMARY_CACHE_KEY,
            self::SUMMARY_CACHE_SECONDS,
            fn (): array => $this->readTotals(),
        ));
        if (! is_array($totals)) {
            return null;
        }

        // The visit being rendered is only written after the response, so it is added here instead.
        $own = $includingThisVisit ? 1 : 0;
        $first = $totals['first'] ?? ($own === 1 ? $this->hourKey($this->now()) : null);

        return [
            'past_24_hours' => $totals['recent'] + $own,
            'total' => $totals['total'] + $own,
            'since' => $first === null
                ? null
                : CarbonImmutable::parse($first, 'UTC')->setTimezone(HubTimezone::NAME)->format('M j, Y'),
        ];
    }

    /** One place to widen or narrow who sees which members signed in. */
    public function canSeeSignIns(User $user): bool
    {
        return $user->canAccessPanel(Filament::getPanel('admin'));
    }

    public function signedInCount(): ?int
    {
        $count = $this->guarded(fn (): int => User::query()->where('last_login_at', '>=', $this->signedInSince())->count());

        return is_int($count) ? $count : null;
    }

    /**
     * Members whose last sign-in was within the past 24 hours, most recent first. Names and times only.
     *
     * @return array{count: int, members: list<array{name: string, at: string}>}|null
     */
    public function signedIn(): ?array
    {
        $users = $this->guarded(fn () => User::query()
            ->where('last_login_at', '>=', $this->signedInSince())
            ->orderByDesc('last_login_at')
            ->limit(self::SIGNED_IN_LIMIT)
            ->get(['name', 'display_name', 'last_login_at']));
        if ($users === null) {
            return null;
        }

        $now = $this->now();
        $members = $users->map(fn (User $user): array => [
            'name' => (string) ($user->display_name ?: $user->name),
            'at' => $this->whenLabel($user->last_login_at, $now),
        ])->values()->all();

        return ['count' => count($members), 'members' => $members];
    }

    /** @return array{total: int, recent: int, first: ?string} */
    private function readTotals(): array
    {
        $windowStart = $this->hourKey($this->now()->subHours(self::WINDOW_HOURS));

        // Quoted identifiers and plain aggregates only, so the same statement runs on Postgres and SQLite.
        $row = DB::transaction(static fn (): ?object => DB::table(self::TABLE)->selectRaw(
            'coalesce(sum("views" + "imported_views"), 0) as total, '
            .'coalesce(sum(case when "hour" >= ? then "views" + "imported_views" else 0 end), 0) as recent, '
            .'min(case when "views" + "imported_views" > 0 then "hour" end) as first_hour',
            [$windowStart],
        )->first());

        return [
            'total' => (int) ($row->total ?? 0),
            'recent' => (int) ($row->recent ?? 0),
            'first' => isset($row->first_hour) ? (string) $row->first_hour : null,
        ];
    }

    private function signedInSince(): CarbonImmutable
    {
        return $this->now()->subHours(self::WINDOW_HOURS);
    }

    private function whenLabel(CarbonInterface|string|null $at, CarbonImmutable $now): string
    {
        if ($at === null) {
            return '';
        }

        $local = CarbonImmutable::parse($at)->setTimezone(HubTimezone::NAME);
        $today = $now->setTimezone(HubTimezone::NAME)->startOfDay();
        $day = $local->startOfDay();
        $label = $day->equalTo($today) ? 'Today' : ($day->equalTo($today->subDay()) ? 'Yesterday' : $local->format('M j'));

        return $label.' '.$local->format('g:i A');
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(now())->utc();
    }

    private function hourKey(CarbonImmutable $moment): string
    {
        return $moment->utc()->startOfHour()->format('Y-m-d H:i:s');
    }

    /** Run the work; on any failure log a warning and answer null, so Home never notices. */
    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $exception) {
            Log::warning('Home activity could not be read or written.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
