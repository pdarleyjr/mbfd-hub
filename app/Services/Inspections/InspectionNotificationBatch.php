<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Notifications\Inspections\InspectionNotificationKind as Kind;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One action applied to many issues on a unit tells each person once, not once per issue. While
 * collapse() runs, InspectionNotificationDispatcher hands its notifications here; afterwards each
 * person gets the original notice if there was one, or a single summary if there were several.
 */
final class InspectionNotificationBatch
{
    /** @var array<string, list<array{recipient: User, kind: string, payload: array{title: string, body: string, url: string}, eventKey: string, delivered: Closure(string): void}>>|null */
    private ?array $held = null;

    /**
     * @template T
     *
     * @param  string  $unit  what the issues are on, as the summary names it
     * @param  string|null  $note  text the admin wrote for members; never an internal note
     * @param  Closure(): T  $work
     * @return T
     */
    public function collapse(string $unit, ?string $note, Closure $work): mixed
    {
        if ($this->isHolding()) {
            return $work();
        }

        $this->held = [];
        try {
            return $work();
        } finally {
            $held = $this->held;
            $this->held = null;
            foreach ($held as $entries) {
                $this->send($entries, $unit, $note);
            }
        }
    }

    public function isHolding(): bool
    {
        return $this->held !== null;
    }

    /**
     * @param  array{title: string, body: string, url: string}  $payload
     * @param  Closure(string): void  $delivered  records the delivery result on this event's ledger row
     */
    public function hold(User $recipient, string $kind, array $payload, string $eventKey, Closure $delivered): void
    {
        $this->held[$recipient->getKey().':'.$kind][] = compact('recipient', 'kind', 'payload', 'eventKey', 'delivered');
    }

    /** @param  list<array{recipient: User, kind: string, payload: array{title: string, body: string, url: string}, eventKey: string, delivered: Closure(string): void}>  $entries */
    private function send(array $entries, string $unit, ?string $note): void
    {
        $first = $entries[0];
        $count = count($entries);
        $resolved = $first['kind'] === Kind::ISSUE_RESOLVED;
        $title = $count === 1 ? $first['payload']['title'] : "{$unit}: {$count} reported issues ".($resolved ? 'resolved' : 'updated');
        $body = $count === 1
            ? $first['payload']['body']
            : trim('Logistics '.($resolved ? 'resolved' : 'updated')." {$count} reported issues on {$unit}. ".mb_strimwidth((string) $note, 0, 240, '…'));

        try {
            $first['recipient']->notify(new InspectionEventNotification($first['kind'], $title, $body, $first['payload']['url'], $first['eventKey']));
            $status = 'dispatched';
        } catch (Throwable $exception) {
            $status = 'failed';
            Log::error('Inspection notification summary could not be dispatched', [
                'event_key' => $first['eventKey'],
                'kind' => $first['kind'],
                'recipient_user_id' => $first['recipient']->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }

        foreach ($entries as $entry) {
            try {
                ($entry['delivered'])($status);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
