<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class EmailConversation
{
    public static function sourceLabel(string $source): string
    {
        $name = class_basename($source);

        return match (strtolower($name)) {
            'admin_compose' => 'Administrator email',
            'admin_reply' => 'Administrator reply',
            'admin_forward' => 'Forwarded email',
            'password_reset' => 'Password reset',
            'city_email_verification' => 'City email verification',
            'city_email_changed' => 'City email changed',
            'identity_recovery', 'identity_administrative_recovery' => 'Account recovery',
            'member_onboarding_invitation' => 'Onboarding invitation',
            'newsubmissionnotification' => 'New submission',
            'equipmentdefectnotification' => 'Equipment defect',
            'criticalalertnotification' => 'Critical alert',
            'departmentupdatenotification' => 'Department update',
            'hubsupportmembernotification' => 'Support request',
            'apparatusserviceticketemployeenotification' => 'Apparatus service ticket',
            'trainingtodoassignednotification' => 'Training assignment',
            default => \Illuminate\Support\Str::headline(preg_replace('/Notification$/i', '', $name)) ?: 'System email',
        };
    }

    public function canRespond(InboundEmail|OutboundEmail $email): bool
    {
        // These reviewed notification bodies contain operational summaries and authenticated
        // resource links. New sources remain blocked until their content is reviewed.
        return $email instanceof InboundEmail || in_array($email->source_type, [
            'admin_compose', 'admin_reply', 'admin_forward',
            \App\Notifications\NewSubmissionNotification::class,
            \App\Notifications\EquipmentDefectNotification::class,
        ], true);
    }

    public function source(string $type, int $id, User $actor): InboundEmail|OutboundEmail
    {
        abort_unless($actor->can('admin.communications.view') && $actor->can('admin.communications.send'), 403);
        abort_unless(in_array($type, ['inbound', 'outbound'], true), 404);
        $email = ($type === 'inbound' ? InboundEmail::query() : OutboundEmail::query())->findOrFail($id);
        abort_unless($this->canRespond($email), 403);

        return $email;
    }

    public function messageId(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 998 && preg_match('/^<[^<>\s@]+@[^<>\s@]+>$/D', $value) === 1 ? $value : null;
    }

    public function references(mixed $values): array
    {
        if (is_string($values)) {
            preg_match_all('/<[^<>\s]+>/', $values, $matches);
            $values = $matches[0];
        }

        return array_slice(array_values(array_unique(array_filter(array_map($this->messageId(...), is_array($values) ? $values : [])))), -20);
    }

    public function headers(InboundEmail|OutboundEmail $email, string $mode): array
    {
        if ($mode === 'forward') {
            return [];
        }
        $id = $email instanceof InboundEmail
            ? $this->messageId($email->provider_message_id)
            : ($this->messageId($email->message_id) ?? ($email->provider === 'cloudflare' ? $this->messageId($email->provider_message_id) : null));
        if ($id === null) {
            return [];
        }
        $references = $this->references([...($email->references ?? []), $id]);
        while (strlen(implode(' ', $references)) > 1800) {
            array_shift($references);
        }

        return ['In-Reply-To' => $id, 'References' => implode(' ', $references)];
    }

    public function prefill(InboundEmail|OutboundEmail $email, string $mode): array
    {
        abort_unless($this->canRespond($email) && in_array($mode, ['reply', 'reply_all', 'forward'], true), 403);
        $own = [strtolower((string) config('communications.cloudflare.from_address')), strtolower((string) config('communications.inbound.address'))];
        $to = $email instanceof InboundEmail ? [$email->safe_headers['reply-to'] ?? $email->from_address] : ($email->to_recipients ?? []);
        $cc = [];
        if ($mode === 'reply_all') {
            $cc = $email instanceof InboundEmail ? [...$this->headerAddresses($email->safe_headers['to'] ?? []), ...$this->headerAddresses($email->safe_headers['cc'] ?? [])] : ($email->cc_recipients ?? []);
        }
        $to = array_values(array_diff($this->addresses($to), $own));
        $cc = array_values(array_diff($this->addresses($cc), $own, $to));
        $subject = (string) $email->subject;
        $prefix = $mode === 'forward' ? 'Fwd: ' : 'Re: ';
        if (! preg_match($mode === 'forward' ? '/^(fwd?|fw):\s*/i' : '/^re:\s*/i', $subject)) {
            $subject = $prefix.$subject;
        }
        $body = $email->text_body ?? strip_tags($email instanceof InboundEmail ? ($email->sanitized_html_body ?? '') : ($email->html_body ?? ''));

        return [
            'to' => $mode === 'forward' ? [] : $to, 'cc' => $mode === 'forward' ? [] : $cc, 'bcc' => [],
            'subject' => $subject,
            'text' => "\n\n---------- Original message ----------\nFrom: {$email->from_address}\nSubject: {$email->subject}\n\n{$body}",
            'forward_attachments' => [],
        ];
    }

    public function headerAddresses(mixed $value): array
    {
        return $this->addresses(is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []));
    }

    public function addresses(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($value): string => is_string($value) ? strtolower(trim($value)) : '', $values), fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)));
    }

    public function attachmentOptions(InboundEmail|OutboundEmail $email): array
    {
        $options = [];
        foreach ($email->attachment_metadata ?? [] as $index => $attachment) {
            if ($this->attachmentPath($email, $attachment) !== null) {
                $options[$index] = $attachment['filename'].' ('.number_format($attachment['size'] / 1024, 1).' KB)';
            }
        }

        return $options;
    }

    public function attachments(InboundEmail|OutboundEmail $email, array $selected): array
    {
        $attachments = [];
        foreach (array_unique($selected) as $index) {
            $metadata = $email->attachment_metadata[$index] ?? [];
            $path = $this->attachmentPath($email, $metadata);
            if ($path === null || ! Storage::disk('local')->exists($path)) {
                throw ValidationException::withMessages(['data.forward_attachments' => 'A selected attachment is unavailable. Remove it or upload a new copy.']);
            }
            $attachments[] = ['filename' => $metadata['filename'], 'type' => $metadata['mime_type'] ?? $metadata['type'], 'content' => base64_encode(Storage::disk('local')->get($path))];
        }

        return $attachments;
    }

    private function attachmentPath(InboundEmail|OutboundEmail $email, array $metadata): ?string
    {
        $path = $metadata['path'] ?? '';
        $prefix = $email instanceof InboundEmail ? 'inbound-email-attachments/'.hash('sha256', $email->provider_message_id).'/' : 'outbound-email-attachments/'.$email->getKey().'/';

        return ($metadata['disk'] ?? '') === 'local' && is_string($path) && str_starts_with($path, $prefix) && ! str_contains($path, '..') ? $path : null;
    }

    public function associateInbound(InboundEmail $email): void
    {
        $ids = array_reverse($this->references([...($email->references ?? []), $email->in_reply_to]));
        foreach ($ids as $id) {
            $parent = OutboundEmail::query()->where('message_id', $id)
                ->orWhere(fn ($query) => $query->where('provider', 'cloudflare')->whereNull('message_id')->where('provider_message_id', $id))
                ->first();
            if ($parent !== null) {
                $email->forceFill(['parent_outbound_email_id' => $parent->getKey()])->save();

                return;
            }
        }
    }
}
