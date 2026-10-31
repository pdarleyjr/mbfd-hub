<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Mail\MailThreadEvent;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Words and shapes the Email Center shows. Presentation only: it holds no state, reads no message body and never
 * decides who may see what (the services do that). Every string here is rendered escaped.
 */
final class MailPresentation
{
    /** Delivery wording from the architecture. Nothing says "delivered to Inbox": no signal can show a folder. */
    public const DELIVERY_STATUSES = [
        'Queued', 'Submitted', 'Accepted by Cloudflare', 'Deferred', 'Accepted by receiving server',
        'Failed', 'Rejected', 'Bounced', 'Suppressed', 'Unconfirmed',
    ];

    /** List time: clock today, "Yesterday", month and day this year, full date before that. */
    public static function listTime(?DateTimeInterface $at): string
    {
        if ($at === null) {
            return '';
        }
        $local = CarbonImmutable::instance($at)->setTimezone(HubTimezone::NAME);
        $today = CarbonImmutable::now(HubTimezone::NAME);

        return match (true) {
            $local->isSameDay($today) => $local->format('g:i A'),
            $local->isSameDay($today->subDay()) => 'Yesterday',
            $local->year === $today->year => $local->format('M j'),
            default => $local->format('M j, Y'),
        };
    }

    public static function fullTime(?DateTimeInterface $at): string
    {
        return $at === null ? '' : HubTimezone::dateTime($at);
    }

    /** The ISO form for the <time datetime> attribute. */
    public static function isoTime(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->toIso8601String();
    }

    /** "Name <address>" when a name is known, else the address. */
    public static function person(string $address, ?string $name): string
    {
        $name = $name === null ? '' : trim($name);

        return $name !== '' && strcasecmp($name, $address) !== 0 ? "{$name} <{$address}>" : $address;
    }

    /** The name a list row leads with. */
    public static function displayName(string $address, ?string $name): string
    {
        $name = $name === null ? '' : trim($name);

        return $name !== '' ? $name : $address;
    }

    public static function initials(string $label): string
    {
        $parts = preg_split('/[\s@._-]+/u', trim($label), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    public static function fileSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 0).' KB',
            default => $bytes.' B',
        };
    }

    /** One sentence for the activity history. Names come from the loaded actor, never from stored metadata. */
    public static function eventSentence(MailThreadEvent $event, ?string $assigneeName = null): string
    {
        $actor = $event->actor instanceof User ? $event->actor->name : 'The system';
        $metadata = (array) $event->metadata;

        return match ($event->type) {
            'archived' => "{$actor} archived this conversation.",
            'unarchived' => "{$actor} moved this conversation back to the Inbox.",
            'trashed' => "{$actor} moved this conversation to Trash.",
            'restored' => "{$actor} restored this conversation from Trash.",
            'assigned' => $actor.' assigned this conversation to '.($assigneeName ?? 'a colleague').'.',
            'unassigned' => "{$actor} removed the assignee.",
            'priority_changed' => $actor.' set the priority to '.(($metadata['priority'] ?? '') === 'high' ? 'high' : 'normal').'.',
            'snoozed' => "{$actor} snoozed this conversation.",
            'unsnoozed' => "{$actor} woke this conversation.",
            'label_added' => $actor.' added the label "'.(string) ($metadata['label'] ?? '').'".',
            'label_removed' => $actor.' removed the label "'.(string) ($metadata['label'] ?? '').'".',
            'note_added' => "{$actor} added an internal note.",
            'merged' => 'Two conversations were joined because a message linked them.',
            default => "{$actor} updated this conversation.",
        };
    }

    /**
     * Per-recipient delivery rows for a message we sent, using the exact status wording. Bcc recipients are listed
     * only for the person who sent the message. Addresses come from the ledger, so a recipient the provider never
     * heard of is simply absent: that is shown as "Unconfirmed" against the address on the message.
     *
     * @return list<array{address: string, class: string, label: string, tone: string}>
     */
    public static function deliveryRows(OutboundEmail $email, User $viewer): array
    {
        $mine = $email->initiated_by_user_id !== null && (int) $email->initiated_by_user_id === (int) $viewer->getKey();
        $rows = [];
        foreach ($email->recipients()->orderBy('id')->get() as $recipient) {
            $class = (string) $recipient->recipient_class;
            if ($class === 'bcc' && ! $mine) {
                continue;
            }
            $address = (string) $recipient->getAttribute('address');
            $label = self::recipientLabel((string) $recipient->status, $recipient->failure_code, $recipient->last_event_type);
            $rows[mb_strtolower($address)] = ['address' => $address, 'class' => $class, 'label' => $label, 'tone' => self::tone($label)];
        }
        foreach ([['to', $email->to_recipients], ['cc', $email->cc_recipients]] as [$class, $addresses]) {
            foreach ((array) $addresses as $address) {
                $key = mb_strtolower((string) $address);
                $rows[$key] ??= ['address' => (string) $address, 'class' => $class, 'label' => 'Unconfirmed', 'tone' => 'neutral'];
            }
        }

        return array_values($rows);
    }

    public static function recipientLabel(string $status, ?string $failureCode = null, ?string $lastEventType = null): string
    {
        if ($status === 'rejected' && ($failureCode === 'E_RECIPIENT_SUPPRESSED' || $lastEventType === 'send_response.suppressed_recipients')) {
            return 'Suppressed';
        }

        return match ($status) {
            'pending', 'reserved' => 'Queued',
            'submitted' => 'Submitted',
            'accepted', 'queued' => 'Accepted by Cloudflare',
            'deferred' => 'Deferred',
            'delivered', 'partially_delivered', 'complained' => 'Accepted by receiving server',
            'bounced' => 'Bounced',
            'rejected' => 'Rejected',
            'failed', 'failed_pre_acceptance' => 'Failed',
            default => 'Unconfirmed',
        };
    }

    /** Colour is never the only signal: the label is always printed. */
    public static function tone(string $label): string
    {
        return match ($label) {
            'Accepted by receiving server' => 'good',
            'Deferred' => 'warn',
            'Failed', 'Rejected', 'Bounced', 'Suppressed' => 'bad',
            default => 'neutral',
        };
    }
}
