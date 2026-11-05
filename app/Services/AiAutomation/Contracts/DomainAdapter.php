<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Contracts;

use App\Models\AiAutomation\AiIntakeEvent;
use App\Services\AiAutomation\BacklogCandidate;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\ReplyRecord;

/**
 * One adapter per in-scope area (vehicle findings, station requests, Support Services messages).
 *
 * The engine never touches domain tables itself: it asks the adapter for facts, decides the reply, and
 * asks the adapter to append the one member-visible "AI Response" record. Adapters must never change a
 * status, acknowledgment, priority, readiness, review or resolution field.
 */
interface DomainAdapter
{
    /** 'vehicle' | 'station' | 'support' */
    public function domain(): string;

    /** @return list<string> source_type values this adapter owns */
    public function sourceTypes(): array;

    /**
     * Server-built facts for one intake. Re-read from the database every call (the engine calls it again
     * inside the write transaction). Set ineligibleReason when the source is gone, test data, archived,
     * withdrawn, terminal or otherwise not something to answer; set alreadyAnswered when staff or the
     * assistant already replied to this submission.
     */
    public function facts(AiIntakeEvent $intake): IntakeFacts;

    /**
     * Append the single member-visible AI Response record. Runs inside the engine's transaction after
     * the master switch was re-checked under lock. Must be idempotent for the same intake id, must not
     * send email, and must not fire the domain's own staff/member notifications.
     */
    public function writeReply(AiIntakeEvent $intake, IntakeFacts $facts, string $text): ReplyRecord;

    /** Where the member reads the reply (relative URL), for the in-app notification. */
    public function memberUrl(AiIntakeEvent $intake): ?string;

    /** @return list<string> source types the historical backlog scans, in processing order */
    public function backlogSourceTypes(): array;

    /**
     * Unattended, still-open submissions with id greater than $afterId, ascending, at most $limit.
     * Only parents that never got a staff or assistant response. Read-only.
     *
     * @return list<BacklogCandidate>
     */
    public function backlogCandidates(string $sourceType, int $afterId, int $limit): array;
}
