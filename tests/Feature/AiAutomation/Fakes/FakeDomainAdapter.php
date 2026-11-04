<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation\Fakes;

use App\Models\AiAutomation\AiIntakeEvent;
use App\Services\AiAutomation\BacklogCandidate;
use App\Services\AiAutomation\Contracts\DomainAdapter;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\ReplyRecord;
use Closure;
use RuntimeException;

/** In-memory adapter for engine tests. Records every write so tests can count member-visible replies. */
final class FakeDomainAdapter implements DomainAdapter
{
    /** @var array<int, IntakeFacts> keyed by source id */
    public static array $facts = [];

    /** @var list<array{intake_id: int, source_id: int, text: string}> */
    public static array $writes = [];

    /** @var list<int> */
    public static array $backlog = [];

    public static ?Closure $beforeWrite = null;

    public static ?Closure $onFacts = null;

    public static int $failWrites = 0;

    public static ?int $requesterUserId = null;

    public static function reset(): void
    {
        self::$facts = [];
        self::$writes = [];
        self::$backlog = [];
        self::$beforeWrite = null;
        self::$onFacts = null;
        self::$failWrites = 0;
        self::$requesterUserId = null;
    }

    public function domain(): string
    {
        return 'support';
    }

    public function sourceTypes(): array
    {
        return ['fake_message'];
    }

    public function facts(AiIntakeEvent $intake): IntakeFacts
    {
        if (self::$onFacts !== null) {
            (self::$onFacts)($intake);
        }

        return self::$facts[(int) $intake->source_id]
            ?? new IntakeFacts('support', 'support_message', self::$requesterUserId, [], 'Left a note for you.');
    }

    public function writeReply(AiIntakeEvent $intake, IntakeFacts $facts, string $text): ReplyRecord
    {
        if (self::$beforeWrite !== null) {
            (self::$beforeWrite)($intake);
        }
        if (self::$failWrites > 0) {
            self::$failWrites--;

            throw new RuntimeException('write failed');
        }
        foreach (self::$writes as $write) {
            if ($write['intake_id'] === (int) $intake->getKey()) {
                return new ReplyRecord('fake_reply', $write['intake_id']);
            }
        }
        self::$writes[] = ['intake_id' => (int) $intake->getKey(), 'source_id' => (int) $intake->source_id, 'text' => $text];

        return new ReplyRecord('fake_reply', (int) $intake->getKey());
    }

    public function memberUrl(AiIntakeEvent $intake): ?string
    {
        return '/employee/messages';
    }

    public function backlogSourceTypes(): array
    {
        return ['fake_message'];
    }

    public function backlogCandidates(string $sourceType, int $afterId, int $limit): array
    {
        $ids = array_values(array_filter(self::$backlog, static fn (int $id): bool => $id > $afterId));
        sort($ids);

        return array_map(
            static fn (int $id): BacklogCandidate => new BacklogCandidate('fake_message', $id, 'received'),
            array_slice($ids, 0, $limit),
        );
    }
}
