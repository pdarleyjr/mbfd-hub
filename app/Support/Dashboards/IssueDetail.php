<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

/**
 * Fresh, authoritative detail loaded when an action panel opens (never from the list cache).
 *
 * `facts` is an ordered list of label/value pairs (plain text; the view escapes them).
 * `timeline` is an ordered list of ['at' => CarbonImmutable, 'actor' => ?string, 'text' => string].
 * `readOnlyReason` is set when the current admin may see the record but no workflow action exists
 * for them; the panel shows it instead of inert buttons.
 */
final readonly class IssueDetail
{
    /**
     * @param  list<array{label: string, value: string}>  $facts
     * @param  list<array{at: \Carbon\CarbonImmutable, actor: ?string, text: string}>  $timeline
     * @param  list<IssueAction>  $actions
     */
    public function __construct(
        public DashboardIssue $issue,
        public ?string $body,
        public array $facts,
        public array $timeline,
        public array $actions,
        public ?string $readOnlyReason = null,
    ) {}
}
