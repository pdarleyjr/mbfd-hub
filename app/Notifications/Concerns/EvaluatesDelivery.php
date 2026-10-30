<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\Notifications\NotificationDecision;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationRecipientEvaluator;

/**
 * Makes a notification obey the one evaluator, both when it is queued
 * (`via`) and again when the worker delivers each channel (`shouldSend`,
 * which Laravel calls per channel at send time). A role revoked after a
 * notification was queued therefore stops it.
 */
trait EvaluatesDelivery
{
    /** The registry category key, or null for an unknown/undeliverable kind. */
    abstract protected function deliveryCategory(): ?string;

    /**
     * Preference channels the publisher offers; null offers all.
     *
     * @return list<string>|null
     */
    protected function deliveryChannelMask(): ?array
    {
        return null;
    }

    /**
     * One id per fan-out / send, stamped before the notification is queued so every push attempt it causes
     * (including deferred retries) can be matched in the delivery log. Not a secret and not personal.
     */
    public ?string $deliveryCorrelationId = null;

    public function withDeliveryCorrelation(string $correlationId): static
    {
        $this->deliveryCorrelationId ??= $correlationId;

        return $this;
    }

    /** The registry category key, for the delivery log (null for an unknown/undeliverable kind). */
    public function deliveryCategoryKey(): ?string
    {
        return $this->deliveryCategory();
    }

    /** Idempotency key (category + subject + event) recorded as provenance. */
    protected function deliveryEventKey(): ?string
    {
        return null;
    }

    protected function evaluateDelivery(object $notifiable): ?NotificationDecision
    {
        $category = $this->deliveryCategory();
        if ($category === null || ! $notifiable instanceof User) {
            return null;
        }

        return app(NotificationRecipientEvaluator::class)->decide($notifiable, $category, $this->deliveryChannelMask());
    }

    /**
     * Laravel channels, evaluated against the member's current state.
     *
     * @return list<string>
     */
    protected function evaluatedChannels(object $notifiable): array
    {
        return $this->evaluateDelivery($notifiable)?->laravelChannels() ?? [];
    }

    /** Evaluated again at delivery time from a fresh copy of the member. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }
        $fresh = User::query()->find($notifiable->getKey());

        return $fresh instanceof User && ($this->evaluateDelivery($fresh)?->allows($channel) ?? false);
    }

    /**
     * Stored with the in-app copy so support can answer "why did I get this".
     *
     * @return array{category: ?string, rule: ?string, event_key: ?string}
     */
    protected function deliveryProvenance(object $notifiable): array
    {
        return [
            'category' => $this->deliveryCategory(),
            'rule' => $this->evaluateDelivery($notifiable)?->rule,
            'event_key' => $this->deliveryEventKey(),
        ];
    }

    /** A same-origin click-through the recipient is allowed to open. */
    protected function deliveryUrl(object $notifiable, string $url): string
    {
        return $notifiable instanceof User ? NotificationLink::resolve($notifiable, $url) : $url;
    }
}
