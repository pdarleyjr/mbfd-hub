<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

use InvalidArgumentException;

/**
 * Container singleton listing every IssueSource. Sources are registered in
 * config/dashboards.php (vehicle_sources, station_sources) via DashboardServiceProvider. Routing an action is always by the
 * stable "<type>:<id>" ref, never by a class name from the client.
 */
final class IssueSourceRegistry
{
    /** @var array<string, IssueSource> */
    private array $sources = [];

    /** @param  iterable<IssueSource>  $sources */
    public function __construct(iterable $sources = [])
    {
        foreach ($sources as $source) {
            $this->register($source);
        }
    }

    public function register(IssueSource $source): void
    {
        $this->sources[$source->type()] = $source;
    }

    /** @return list<IssueSource> */
    public function forView(DashboardView $view): array
    {
        return array_values(array_filter(
            $this->sources,
            static fn (IssueSource $source): bool => in_array($view, $source->views(), true),
        ));
    }

    public function get(string $type): IssueSource
    {
        return $this->sources[$type] ?? throw new InvalidArgumentException('Unknown dashboard issue type.');
    }

    public function has(string $type): bool
    {
        return isset($this->sources[$type]);
    }

    /** @return array{0: string, 1: string} */
    public static function parseRef(string $ref): array
    {
        if (preg_match('/^([a-z][a-z0-9_]{1,40}):([A-Za-z0-9-]{1,64})$/', $ref, $m) !== 1) {
            throw new InvalidArgumentException('Malformed dashboard issue reference.');
        }

        return [$m[1], $m[2]];
    }
}
