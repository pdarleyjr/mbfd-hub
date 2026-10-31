<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\OutboundEmailRecipient;
use App\Models\OutboundEmailReconciliationCheckpoint;
use App\Models\User;
use App\Services\Communications\CloudflareCostGuard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A read-only summary of how sending is going, from data the Hub already holds.
 *
 * Nothing here calls Cloudflare or starts a job. DNS record presence is looked up in public DNS and
 * remembered for 24 hours; a failed lookup says "not checked" and never an error. The wording describes
 * what a receiving server accepted. No signal available to the Hub shows which folder a message reached,
 * so nothing here claims it.
 */
final class MailHealthReport
{
    private const DNS_CACHE_SECONDS = 86400;

    /** A failed lookup is retried soon rather than hidden for a day. */
    private const DNS_FAILURE_CACHE_SECONDS = 600;

    private const PRESENT = 'present';

    private const MISSING = 'missing';

    private const NOT_CHECKED = 'not checked';

    /** @var Closure(string): (list<string>|null)|null */
    private readonly ?Closure $txtResolver;

    /** @param  (Closure(string): (list<string>|null))|null  $txtResolver  Returns TXT strings for a name, or null when the lookup failed. */
    public function __construct(?Closure $txtResolver = null)
    {
        $this->txtResolver = $txtResolver;
    }

    /** @return array<string, mixed> */
    public function summary(User $viewer): array
    {
        // The Email Center's own read level (which also honours the legacy Communications permission), never the bare permission.
        if (! app(MailAccess::class)->canView($viewer)) {
            throw new AuthorizationException('You may not view email health.');
        }
        $now = CarbonImmutable::now();

        return [
            'generated_at' => $now->utc()->toIso8601String(),
            'sending' => $this->sending($now),
            'reconciliation' => $this->reconciliation(),
            'outcomes_30d' => $this->outcomes($now),
            'outcome_labels' => self::OUTCOME_LABELS,
            'complaints_unavailable_label' => self::COMPLAINTS_UNAVAILABLE,
            'authentication' => $this->authentication($now),
            'notes' => $this->notes(),
        ];
    }

    private const OUTCOME_LABELS = [
        'accepted_by_receiving_server' => 'Accepted by receiving server',
        'deferred' => 'Deferred',
        'bounced' => 'Bounced',
        'failed' => 'Failed',
        'rejected' => 'Rejected',
        'suppressed' => 'Suppressed',
        'complained' => 'Complaint reported',
        'awaiting_confirmation' => 'Awaiting confirmation',
    ];

    /** Cloudflare's analytics dataset carries no complaint data, so "none" would claim knowledge nobody has. */
    public const COMPLAINTS_UNAVAILABLE = 'Not reported by Cloudflare analytics';

    /** @return array<string, mixed> */
    private function sending(CarbonInterface $now): array
    {
        $snapshot = app(CloudflareCostGuard::class)->snapshot($now);

        return [
            'state' => $snapshot['state'],
            'summary' => $snapshot['summary'],
            'used' => $snapshot['used'],
            'ceiling' => $snapshot['ceiling'],
            'remaining' => $snapshot['remaining'],
            'daily_used' => $snapshot['daily_used'],
            'daily_quota' => $snapshot['daily_quota'],
            'minute_limit' => $snapshot['minute_limit'],
            'recipient_limit' => $snapshot['recipient_limit'],
            'backoff_until' => $this->iso($snapshot['backoff_until']),
            'usage_reconciled_at' => $this->iso($snapshot['reconciled_at']),
        ];
    }

    /** @return array{enabled: bool, last_completed_at: ?string, last_attempted_at: ?string, last_error: ?string} */
    private function reconciliation(): array
    {
        $zone = (string) config('communications.delivery.zone_id');
        $query = OutboundEmailReconciliationCheckpoint::query();
        $checkpoint = ($zone !== '' ? $query->where('zone_id', $zone) : $query)->orderByDesc('id')->first();

        return [
            'enabled' => (bool) config('communications.delivery.enabled'),
            'last_completed_at' => $this->iso($checkpoint?->completed_through),
            'last_attempted_at' => $this->iso($checkpoint?->attempted_at),
            'last_error' => $checkpoint?->last_error,
        ];
    }

    /** @return array<string, int|null> complained is null (not zero) unless a ledger row actually records one */
    private function outcomes(CarbonInterface $now): array
    {
        $since = $now->subDays(30);
        $byStatus = OutboundEmailRecipient::query()->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)->all();
        $suppressed = OutboundEmailRecipient::query()->where('created_at', '>=', $since)->where('status', 'rejected')
            ->where(fn ($query) => $query->where('failure_code', 'E_RECIPIENT_SUPPRESSED')
                ->orWhere('last_event_type', 'send_response.suppressed_recipients'))
            ->count();
        $count = static fn (string ...$statuses): int => array_sum(array_map(static fn (string $status): int => $byStatus[$status] ?? 0, $statuses));

        return [
            'accepted_by_receiving_server' => $count('delivered'),
            'deferred' => $count('deferred'),
            'bounced' => $count('bounced'),
            'failed' => $count('failed'),
            'rejected' => max(0, $count('rejected') - $suppressed),
            'suppressed' => $suppressed,
            'complained' => $count('complained') > 0 ? $count('complained') : null,
            'awaiting_confirmation' => $count('pending', 'submitted', 'accepted', 'queued', 'unknown', 'historical_unknown'),
        ];
    }

    /** @return array{domain: string, checked_at: ?string, spf: string, dmarc: string, dkim: string} */
    private function authentication(CarbonInterface $now): array
    {
        $domain = $this->sendingDomain();
        $cacheKey = 'mail-health:dns:'.$domain;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $selectors = array_values(array_filter((array) config('communications.health.dkim_selectors', ['cf-bounce']), 'is_string'));
        $spf = $this->presence($domain, static fn (string $record): bool => stripos(trim($record), 'v=spf1') === 0);
        $dmarc = $this->presence('_dmarc.'.$domain, static fn (string $record): bool => stripos(trim($record), 'v=DMARC1') === 0);
        $dkim = $this->anyPresence(array_map(static fn (string $selector): string => $selector.'._domainkey.'.$domain, $selectors),
            static fn (string $record): bool => stripos($record, 'v=DKIM1') !== false || preg_match('/(^|;)\s*p=/i', $record) === 1);

        $result = [
            'domain' => $domain,
            'checked_at' => in_array(self::NOT_CHECKED, [$spf, $dmarc, $dkim], true) ? null : $this->iso($now),
            'spf' => $spf,
            'dmarc' => $dmarc,
            'dkim' => $dkim,
        ];
        $complete = ! in_array(self::NOT_CHECKED, [$spf, $dmarc, $dkim], true);
        Cache::put($cacheKey, $result, $complete ? self::DNS_CACHE_SECONDS : self::DNS_FAILURE_CACHE_SECONDS);

        return $result;
    }

    private function sendingDomain(): string
    {
        $address = (string) config('communications.cloudflare.from_address', 'info@mbfdhub.com');
        $domain = strtolower(substr(strrchr($address, '@') ?: '@mbfdhub.com', 1));

        return $domain === '' ? 'mbfdhub.com' : $domain;
    }

    /** @param  Closure(string): bool  $matches */
    private function presence(string $name, Closure $matches): string
    {
        return $this->anyPresence([$name], $matches);
    }

    /**
     * @param  list<string>  $names
     * @param  Closure(string): bool  $matches
     */
    private function anyPresence(array $names, Closure $matches): string
    {
        $failed = false;
        foreach ($names as $name) {
            $records = $this->lookup($name);
            if ($records === null) {
                $failed = true;

                continue;
            }
            foreach ($records as $record) {
                if ($matches($record)) {
                    return self::PRESENT;
                }
            }
        }

        return $failed ? self::NOT_CHECKED : self::MISSING;
    }

    /** @return list<string>|null */
    private function lookup(string $name): ?array
    {
        try {
            $resolver = $this->txtResolver ?? static function (string $host): ?array {
                $records = @dns_get_record($host, DNS_TXT);

                return is_array($records)
                    ? array_map(static fn (array $record): string => (string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? []))), $records)
                    : null;
            };

            return $resolver($name);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function notes(): array
    {
        $notes = [
            'These figures show whether a receiving server accepted a message. They cannot show which mailbox folder it reached.',
            'Authentication records are looked up in public DNS and remembered for 24 hours.',
            'Complaints: '.self::COMPLAINTS_UNAVAILABLE.'.',
        ];
        if (! (bool) config('communications.delivery.enabled')) {
            $notes[] = 'Delivery reconciliation is off, so outcomes show only what each send response reported.';
        }

        return $notes;
    }

    private function iso(?CarbonInterface $moment): ?string
    {
        return $moment === null ? null : CarbonImmutable::instance($moment)->utc()->toIso8601String();
    }
}
