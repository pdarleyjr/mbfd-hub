<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster\Pipeline;

use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\DailyRoster\RosterStateRecorder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RosterStateMachineTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function contractEdges(): array
    {
        $edges = [
            [RosterImport::STATE_CLASSIFIED, RosterImport::STATE_QUEUED],
            [RosterImport::STATE_QUEUED, RosterImport::STATE_PARSING],
            [RosterImport::STATE_RETRYABLE_FAILED, RosterImport::STATE_PARSING],
            [RosterImport::STATE_QUEUED, RosterImport::STATE_DUPLICATE],
            [RosterImport::STATE_PARSING, RosterImport::STATE_VALIDATED],
            [RosterImport::STATE_PARSING, RosterImport::STATE_NEEDS_REVIEW],
            [RosterImport::STATE_PARSING, RosterImport::STATE_PERMANENT_FAILED],
            [RosterImport::STATE_PARSING, RosterImport::STATE_RETRYABLE_FAILED],
            [RosterImport::STATE_VALIDATED, RosterImport::STATE_SHADOWED],
            [RosterImport::STATE_VALIDATED, RosterImport::STATE_PUBLISHED],
            [RosterImport::STATE_VALIDATED, RosterImport::STATE_SUPERSEDED],
            [RosterImport::STATE_VALIDATED, RosterImport::STATE_DUPLICATE],
            [RosterImport::STATE_VALIDATED, RosterImport::STATE_NEEDS_REVIEW],
            [RosterImport::STATE_PUBLISHED, RosterImport::STATE_VERIFIED],
            [RosterImport::STATE_PUBLISHED, RosterImport::STATE_NEEDS_REVIEW],
            [RosterImport::STATE_VERIFIED, RosterImport::STATE_CLEANED],
            [RosterImport::STATE_DUPLICATE, RosterImport::STATE_CLEANED],
            [RosterImport::STATE_SUPERSEDED, RosterImport::STATE_CLEANED],
        ];

        return array_combine(array_map(static fn (array $edge): string => $edge[0].' to '.$edge[1], $edges), $edges);
    }

    #[DataProvider('contractEdges')]
    public function test_every_contract_edge_is_defined(string $from, string $to): void
    {
        self::assertTrue(RosterStateRecorder::isDefined($from, $to));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function forbiddenEdges(): array
    {
        $edges = [
            [RosterImport::STATE_CLEANED, RosterImport::STATE_QUEUED],
            [RosterImport::STATE_CLEANED, RosterImport::STATE_PUBLISHED],
            [RosterImport::STATE_REJECTED, RosterImport::STATE_PUBLISHED],
            [RosterImport::STATE_REJECTED, RosterImport::STATE_PARSING],
            [RosterImport::STATE_SHADOWED, RosterImport::STATE_PUBLISHED],
            [RosterImport::STATE_SHADOWED, RosterImport::STATE_CLEANED],
            [RosterImport::STATE_NEEDS_REVIEW, RosterImport::STATE_CLEANED],
            [RosterImport::STATE_PERMANENT_FAILED, RosterImport::STATE_CLEANED],
            [RosterImport::STATE_VERIFIED, RosterImport::STATE_PUBLISHED],
            [RosterImport::STATE_CLASSIFIED, RosterImport::STATE_PUBLISHED],
            [RosterImport::STATE_QUEUED, RosterImport::STATE_PUBLISHED],
            [RosterImport::STATE_PARSING, RosterImport::STATE_PUBLISHED],
        ];

        return array_combine(array_map(static fn (array $edge): string => $edge[0].' to '.$edge[1], $edges), $edges);
    }

    #[DataProvider('forbiddenEdges')]
    public function test_nothing_can_skip_the_checks_or_leave_a_finished_state(string $from, string $to): void
    {
        self::assertFalse(RosterStateRecorder::isDefined($from, $to));
    }

    public function test_backoff_follows_the_schedule_within_twenty_percent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
        config(['daily_roster.processing.backoff_minutes' => [1, 5, 15, 60]]);

        foreach ([1 => 1, 2 => 5, 3 => 15, 4 => 60, 9 => 60] as $attempt => $minutes) {
            for ($sample = 0; $sample < 25; $sample++) {
                $seconds = now()->diffInSeconds(RosterStateRecorder::nextAttemptAt($attempt));
                self::assertGreaterThanOrEqual($minutes * 60 * 0.8, $seconds);
                self::assertLessThanOrEqual($minutes * 60 * 1.2, $seconds);
            }
        }
    }

    public function test_the_exception_message_is_the_code_and_nothing_else(): void
    {
        self::assertSame('some_code', (new RosterPipelineException('some_code'))->getMessage());
    }
}
