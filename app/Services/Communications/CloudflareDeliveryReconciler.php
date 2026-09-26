<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\OutboundEmail;
use App\Models\OutboundEmailReconciliationCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class CloudflareDeliveryReconciler
{
    private int $requests = 0;

    private float $startedAt = 0;

    public function __construct(private readonly OutboundDeliveryLedger $ledger) {}

    public function reconcile(bool $backfill = false): array
    {
        $zone = (string) config('communications.delivery.zone_id');
        $token = (string) config('communications.delivery.analytics_token');
        if (preg_match('/^[a-f0-9]{32}$/i', $zone) !== 1 || $token === '') {
            throw new RuntimeException('Delivery analytics requires the sending zone and a scoped Analytics Read token.');
        }
        $lock = Cache::lock('cloudflare-email-delivery:'.$zone, 600);
        if (! $lock->get()) {
            throw new RuntimeException('Delivery reconciliation is already running.');
        }
        $checkpoint = null;
        try {
            $this->requests = 0;
            $this->startedAt = microtime(true);
            $end = CarbonImmutable::now()->utc()->startOfSecond();
            $oldest = $end->subDays(31);
            $checkpoint = OutboundEmailReconciliationCheckpoint::query()->firstOrCreate(['zone_id' => $zone]);
            $checkpoint->forceFill(['attempted_at' => $end])->save();
            $overlap = max(10, min(1440, (int) config('communications.delivery.overlap_minutes', 1440)));
            $start = $backfill || $checkpoint->completed_through === null
                ? $oldest : $checkpoint->completed_through->subMinutes($overlap)->max($oldest);
            $matched = 0;
            // Daily windows keep responses modest; saturated windows split without losing tied timestamps.
            while ($start->lt($end)) {
                $until = $start->addDay()->min($end);
                $matched += $this->window($zone, $token, $start, $until);
                $start = $until;
            }
            $historical = $this->ledger->markHistorical();
            $checkpoint->forceFill(['completed_through' => $end, 'last_error' => null])->save();

            return ['events_recorded' => $matched, 'historical_messages' => $historical, 'requests' => $this->requests];
        } catch (Throwable) {
            // Neither API bodies nor transport exceptions may enter logs: they can contain PII or credentials.
            $checkpoint?->forceFill(['last_error' => 'Delivery analytics reconciliation failed; checkpoint preserved.'])->save();
            throw new RuntimeException('Delivery analytics reconciliation failed; checkpoint preserved. Verify analytics access and dataset availability.');
        } finally {
            $lock->release();
        }
    }

    private function window(string $zone, string $token, CarbonImmutable $start, CarbonImmutable $end): int
    {
        if (++$this->requests > 128 || microtime(true) - $this->startedAt > 480) {
            throw new RuntimeException('Delivery analytics request bound reached.');
        }
        $query = <<<'GRAPHQL'
query DeliveryEvents($zoneTag: string!, $start: Time!, $end: Time!) {
  viewer { zones(filter: {zoneTag: $zoneTag}) {
    emailSendingAdaptive(limit: 1000, orderBy: [datetime_ASC], filter: {datetime_geq: $start, datetime_leq: $end}) {
      messageId envelopeTo status eventType errorCause errorDetail datetime sendingDomain
    }
  } }
}
GRAPHQL;
        $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(20)
            ->post('https://api.cloudflare.com/client/v4/graphql', [
                'query' => $query,
                'variables' => ['zoneTag' => $zone, 'start' => $start->toIso8601ZuluString(), 'end' => $end->toIso8601ZuluString()],
            ])->throw()->json();
        $zones = data_get($response, 'data.viewer.zones');
        if (! empty($response['errors']) || ! is_array($zones) || count($zones) !== 1
            || ! is_array($zones[0]['emailSendingAdaptive'] ?? null) || ! array_is_list($zones[0]['emailSendingAdaptive'])) {
            throw new RuntimeException('Incomplete delivery analytics response.');
        }
        $events = $zones[0]['emailSendingAdaptive'];
        if (count($events) >= 1000) {
            $seconds = (int) $start->diffInSeconds($end);
            if ($seconds <= 1) {
                throw new RuntimeException('Delivery analytics timestamp exceeds page limit.');
            }
            $middle = $start->addSeconds(intdiv($seconds, 2));

            return $this->window($zone, $token, $start, $middle) + $this->window($zone, $token, $middle, $end);
        }
        $count = 0;
        foreach ($events as $event) {
            if (! is_array($event) || ! is_string($event['messageId'] ?? null)
                || ! is_string($event['envelopeTo'] ?? null) || ! is_string($event['datetime'] ?? null)
                || ! is_string($event['status'] ?? null) || ! is_string($event['eventType'] ?? null)) {
                throw new RuntimeException('Invalid delivery event schema.');
            }
            $at = CarbonImmutable::parse($event['datetime'])->utc();
            if ($at->lt($start) || $at->gt($end)) {
                throw new RuntimeException('Delivery event lies outside the requested window.');
            }
            $emails = OutboundEmail::query()->where('provider', 'cloudflare')->where('provider_message_id', $event['messageId'])->limit(2)->get();
            if ($emails->count() !== 1) {
                continue;
            }
            $email = $emails->first();
            $domain = strtolower(substr($email->from_address, strrpos($email->from_address, '@') + 1));
            if (strtolower((string) ($event['sendingDomain'] ?? '')) !== $domain || $at->lt($email->created_at->subMinutes(5))) {
                continue;
            }
            $status = $this->status($event['status'], $event['eventType']);
            $count += (int) $this->ledger->record($email, $event['envelopeTo'], $status, $event['eventType'], $at,
                is_string($event['errorCause'] ?? null) ? $event['errorCause'] : null,
                is_string($event['errorDetail'] ?? null) ? $event['errorDetail'] : null);
        }

        return $count;
    }

    private function status(string $status, string $eventType): string
    {
        $type = strtolower((string) preg_replace('/^.*[.:]/', '', $eventType));
        if (in_array($type, ['delivered', 'deferred', 'bounced', 'failed', 'rejected', 'complained'], true)) {
            return $type;
        }

        return match (strtolower($status)) {
            'sent', 'accepted' => 'accepted',
            'queued' => 'queued',
            'deliveryfailed' => 'bounced',
            'delivered', 'deferred', 'bounced', 'failed', 'rejected', 'complained' => strtolower($status),
            default => 'unknown',
        };
    }
}
