<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

use Carbon\CarbonImmutable;

/**
 * One row on an admin dashboard: a thin, read-only projection of a record that lives in its own
 * domain table. It never carries workflow state of its own; `status` and `version` are read from
 * the source record so an action panel can detect a stale write.
 *
 * Lead-owned contract (feat/push-dashboards-20261030). Change only through the lead.
 */
final readonly class DashboardIssue
{
    /**
     * @param  string  $type  stable source key, e.g. "apparatus_defect" (IssueSource::type()).
     * @param  string  $id  source primary key as a string.
     * @param  'high'|'medium'|'low'  $severity
     * @param  string  $status  the source's own status value (never a synthetic status).
     * @param  string  $statusLabel  human label for that status.
     * @param  'awaiting_response'|'in_progress'|'resolved'|'other'  $stage  coarse bucket for queues/filters, derived from the source status.
     * @param  list<string>  $tags  filter keys this row belongs to, e.g. ["rescue"], ["engine_puc"], ["marine"], ["facilities"], ["supplies"], ["access_it"].
     * @param  string|null  $subject  apparatus designation ("Rescue 2") or station name ("Station 1").
     * @param  string|null  $imageKey  key understood by DashboardImages (never a URL from user input).
     * @param  string|null  $actorLabel  reporter/requester display name, or null where privacy/display mode forbids it.
     * @param  string  $version  optimistic-concurrency token of the source record (e.g. updated_at ISO-8601 or a revision column).
     * @param  string|null  $sourceUrl  optional "Open full record" link (secondary only).
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $title,
        public ?string $summary,
        public string $severity,
        public string $status,
        public string $statusLabel,
        public string $stage,
        public array $tags,
        public ?string $subject,
        public ?string $imageKey,
        public ?string $actorLabel,
        public ?string $categoryLabel,
        public CarbonImmutable $occurredAt,
        public string $version,
        public ?string $sourceUrl = null,
    ) {}

    /** Stable identity used in URLs, wire:key and dedupe: "<type>:<id>". */
    public function ref(): string
    {
        return $this->type.':'.$this->id;
    }
}
