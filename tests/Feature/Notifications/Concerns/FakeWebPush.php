<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\Concerns;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;

/**
 * A push service that answers with the status code configured per endpoint
 * (201 when none), so delivery outcomes can be tested without any network.
 */
final class FakeWebPush extends WebPush
{
    /** @var list<string> */
    public array $delivered = [];

    /** @var list<string> */
    private array $queuedEndpoints = [];

    /**
     * @param  array<string, int>  $statusByEndpoint
     * @param  array<string, array<string, string>>  $headersByEndpoint
     */
    public function __construct(public array $statusByEndpoint = [], public array $headersByEndpoint = [])
    {
        parent::__construct([]);
    }

    public function queueNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): void
    {
        $this->queuedEndpoints[] = $subscription->getEndpoint();
    }

    public function flush(?int $batchSize = null): \Generator
    {
        $endpoints = $this->queuedEndpoints;
        $this->queuedEndpoints = [];

        foreach ($endpoints as $endpoint) {
            $status = $this->statusByEndpoint[$endpoint] ?? 201;
            $this->delivered[] = $endpoint;

            yield new MessageSentReport(
                new Request('POST', $endpoint),
                new Response($status, $this->headersByEndpoint[$endpoint] ?? []),
                $status >= 200 && $status < 300,
                $status >= 200 && $status < 300 ? 'OK' : 'Push service answered '.$status,
            );
        }
    }
}
