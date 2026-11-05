<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Services\AiAutomation\Contracts\DomainAdapter;

/** The three in-scope adapters. Nothing outside this list can reach the engine. */
final class DomainRegistry
{
    /** @var list<DomainAdapter>|null */
    private ?array $adapters = null;

    /** @return list<DomainAdapter> */
    public function all(): array
    {
        if ($this->adapters === null) {
            $this->adapters = array_values(array_map(
                static fn (string $class): DomainAdapter => app($class),
                (array) config('ai_automation.adapters', []),
            ));
        }

        return $this->adapters;
    }

    public function forSourceType(string $sourceType): ?DomainAdapter
    {
        foreach ($this->all() as $adapter) {
            if (in_array($sourceType, $adapter->sourceTypes(), true)) {
                return $adapter;
            }
        }

        return null;
    }

    public function domainEnabled(string $domain): bool
    {
        return in_array($domain, ['vehicle', 'station', 'support'], true)
            && (bool) config("ai_automation.domains.{$domain}", false);
    }
}
