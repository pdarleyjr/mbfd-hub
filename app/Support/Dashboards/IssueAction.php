<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

/**
 * A command the CURRENT admin may perform on one issue right now. Produced by
 * IssueSource::actions() after a fresh policy check; the panel renders only these.
 *
 * `fields` describes inputs the panel must collect, e.g.
 *   ['name' => 'message', 'label' => 'Reply', 'type' => 'textarea', 'required' => true, 'max' => 2000]
 *   ['name' => 'assignee_id', 'label' => 'Assign to', 'type' => 'select', 'options' => [id => label], 'required' => true]
 * Supported types: text, textarea, select, date.
 *
 * `bulk` offers the action on the page for all of one item's issues: BULK_DIRECT runs in one click,
 * with its optional fields one step away; BULK_FORM always collects its fields first. Null keeps it
 * to the single-issue panel, for decisions that depend on each record.
 */
final readonly class IssueAction
{
    public const BULK_DIRECT = 'direct';

    public const BULK_FORM = 'form';

    /**
     * @param  'primary'|'secondary'|'danger'  $tone
     * @param  list<array<string, mixed>>  $fields
     * @param  self::BULK_*|null  $bulk
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $tone = 'secondary',
        public array $fields = [],
        public ?string $confirm = null,
        public ?string $bulk = null,
    ) {}
}
