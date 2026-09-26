<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\OutboundEmail;
use App\Models\OutboundEmailDeliveryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OutboundDeliveryLedger
{
    private const TERMINAL = ['delivered', 'bounced', 'rejected', 'failed', 'complained'];

    public function initialize(OutboundEmail $email): void
    {
        $initialStatus = match (true) {
            $email->delivered_at !== null => 'delivered',
            $email->status === 'failed_pre_acceptance' => 'failed',
            in_array($email->status, ['failed', 'bounced', 'rejected', 'complained', 'historical_unknown', 'unknown', 'acceptance_unknown'], true) => $email->status === 'acceptance_unknown' ? 'unknown' : $email->status,
            $email->accepted_at !== null => 'accepted',
            default => 'pending',
        };
        foreach (['to', 'cc', 'bcc'] as $class) {
            foreach ((array) $email->{$class.'_recipients'} as $address) {
                $address = strtolower(trim((string) $address));
                if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }
                $email->recipients()->firstOrCreate(['address' => $address], [
                    'recipient_class' => $class,
                    'provider_message_id' => $email->provider_message_id,
                    'status' => $initialStatus,
                    'delivered_at' => $email->delivered_at,
                    'failed_at' => in_array($initialStatus, ['failed', 'bounced', 'rejected', 'complained'], true) ? $email->failed_at : null,
                    'is_terminal' => in_array($initialStatus, self::TERMINAL, true),
                ]);
            }
        }
    }

    public function initialResponse(OutboundEmail $email, array $result, CarbonImmutable $at): OutboundEmail
    {
        return DB::transaction(function () use ($email, $result, $at): OutboundEmail {
            $email = OutboundEmail::query()->lockForUpdate()->findOrFail($email->id);
            $this->initialize($email);
            $email->recipients()->whereNull('provider_message_id')->update(['provider_message_id' => $email->provider_message_id]);
            $email->recipients()->where('is_terminal', false)->whereNull('last_event_at')->update(['status' => 'accepted']);
            $confirmed = $email->recipients()->where('is_terminal', true)->pluck('address')->all();
            foreach (['queued' => 'queued', 'delivered' => 'delivered', 'permanent_bounces' => 'bounced', 'suppressed_recipients' => 'rejected'] as $field => $status) {
                foreach ((array) ($result[$field] ?? []) as $address) {
                    // A send response is an initial snapshot, never a correction to a lifecycle event.
                    if (is_string($address) && ! in_array(strtolower(trim($address)), $confirmed, true)) {
                        $this->record($email, $address, $status, 'send_response.'.$field, $email->accepted_at ?? $at);
                    }
                }
            }

            return $this->aggregate($email);
        });
    }

    public function record(OutboundEmail $email, string $address, string $status, string $type, CarbonImmutable $at, ?string $code = null, ?string $detail = null): bool
    {
        if (! in_array($status, [...self::TERMINAL, 'accepted', 'queued', 'deferred', 'unknown'], true)) {
            $status = 'unknown';
        }

        return DB::transaction(function () use ($email, $address, $status, $type, $at, $code, $detail): bool {
            // Lock the parent first so concurrent recipients cannot overwrite aggregate truth.
            $email = OutboundEmail::query()->lockForUpdate()->findOrFail($email->id);
            $this->initialize($email);
            $recipient = $email->recipients()->where('address', strtolower(trim($address)))->lockForUpdate()->first();
            if ($recipient === null) {
                return false;
            }
            $type = mb_substr($type, 0, 96);
            $code = $this->bounded($code, 128);
            $detail = $this->bounded($detail, 1000);
            $identity = hash('sha256', json_encode([$email->id, $email->provider_message_id, $recipient->address, $status, $type, $at->utc()->format('Y-m-d\TH:i:s.u\Z'), $code, $detail], JSON_THROW_ON_ERROR));
            $event = OutboundEmailDeliveryEvent::query()->firstOrCreate(['identity' => $identity], [
                'outbound_email_id' => $email->id, 'outbound_email_recipient_id' => $recipient->id,
                'event_type' => $type, 'status' => $status, 'occurred_at' => $at,
                'failure_code' => $code, 'failure_detail' => $detail,
            ]);
            if (! $event->wasRecentlyCreated) {
                return false;
            }
            $terminal = in_array($status, self::TERMINAL, true);
            $ranks = ['unknown' => 0, 'accepted' => 1, 'queued' => 2, 'deferred' => 3, 'failed' => 4, 'rejected' => 5, 'bounced' => 6, 'delivered' => 7, 'complained' => 8];
            $newer = $recipient->last_event_at === null || $at->gt($recipient->last_event_at)
                || ($at->equalTo($recipient->last_event_at) && $ranks[$status] > ($ranks[$recipient->status] ?? 0));
            if (($terminal && ! $recipient->is_terminal) || ($newer && (! $recipient->is_terminal || $terminal))) {
                $failed = in_array($status, ['bounced', 'rejected', 'failed', 'complained'], true);
                $recipient->forceFill([
                    'provider_message_id' => $email->provider_message_id,
                    'status' => $status, 'last_event_type' => $type, 'last_event_at' => $at,
                    'is_terminal' => $terminal,
                    'delivered_at' => $status === 'delivered' ? $at : $recipient->delivered_at,
                    'failed_at' => $failed ? $at : null,
                    'failure_code' => $failed || $status === 'deferred' ? $code : null,
                    'failure_detail' => $failed || $status === 'deferred' ? $detail : null,
                ])->save();
            }
            $this->aggregate($email);

            return true;
        });
    }

    public function aggregate(OutboundEmail $email): OutboundEmail
    {
        $recipients = $email->recipients()->get();
        if ($recipients->isEmpty()) {
            return $email;
        }
        $statuses = $recipients->pluck('status');
        $status = match (true) {
            $statuses->every(fn (string $status): bool => $status === 'delivered') => 'delivered',
            $statuses->contains('complained') => 'complained',
            $statuses->contains('delivered') => 'partially_delivered',
            $statuses->unique()->count() === 1 => $statuses->first(),
            $recipients->every(fn ($recipient): bool => $recipient->is_terminal) => 'failed',
            $statuses->intersect(['bounced', 'rejected', 'failed'])->isNotEmpty() => 'accepted_with_delivery_issues',
            $statuses->contains('deferred') => 'deferred',
            $statuses->contains('unknown') => 'unknown',
            default => 'queued',
        };
        $email->forceFill([
            'status' => $status,
            'delivered_at' => $status === 'delivered' ? $recipients->max('delivered_at') : null,
            'failed_at' => $recipients->max('failed_at'),
            'failure_reason' => $recipients->whereNotNull('failed_at')->isNotEmpty() ? 'One or more recipients have a confirmed delivery issue. See recipient delivery details.' : null,
        ])->save();

        return $email;
    }

    public function markHistorical(): int
    {
        $query = OutboundEmail::query()->where('provider', 'cloudflare')->where('created_at', '<', now()->subDays(31))
            ->whereIn('status', ['pending', 'reserved', 'submitted', 'accepted', 'queued', 'deferred', 'acceptance_unknown', 'accepted_with_delivery_issues', 'partially_delivered', 'complained', 'unknown']);
        $count = 0;
        $query->chunkById(100, function ($emails) use (&$count): void {
            foreach ($emails as $email) {
                $this->initialize($email);
                $email->recipients()->where('is_terminal', false)->update(['status' => 'historical_unknown']);
                if ($email->recipients()->where('is_terminal', true)->exists()) {
                    $this->aggregate($email);
                } else {
                    $email->forceFill(['status' => 'historical_unknown'])->save();
                }
                $count++;
            }
        });

        return $count;
    }

    private function bounded(?string $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = preg_replace('/https?:\/\/\S+|Bearer\s+\S+/i', '[redacted]', $value) ?? '';

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '', 0, $limit);
    }
}
