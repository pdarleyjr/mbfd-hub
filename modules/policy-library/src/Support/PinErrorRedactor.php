<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Support;

use Sentry\Event;

final class PinErrorRedactor
{
    public function __construct(private readonly string $domain) {}

    public function __invoke(Event $event): Event
    {
        $event->setRequest($this->redactRequest($event->getRequest()));

        return $event;
    }

    public function redactRequest(array $request): array
    {
        $url = $request['url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        // Match Laravel's router normalization, including accepted encoded paths.
        $path = is_array($parts) ? rawurldecode(rtrim($parts['path'] ?? '', '/')) : '';
        if (! is_array($parts) || ($parts['host'] ?? '') !== $this->domain || $path !== '/access') {
            return $request;
        }
        unset($request['data'], $request['query_string']);
        $request['url'] = ($parts['scheme'] ?? 'https').'://'.$this->domain.(isset($parts['port']) ? ':'.$parts['port'] : '').'/access';

        return $request;
    }
}
