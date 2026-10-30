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
 */
final readonly class IssueAction
{
    /**
     * @param  'primary'|'secondary'|'danger'  $tone
     * @param  list<array<string, mixed>>  $fields
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $tone = 'secondary',
        public array $fields = [],
        public ?string $confirm = null,
    ) {}
}
