<?php

declare(strict_types=1);

namespace Tests\Unit\HubSupport;

use App\Enums\HubSupportTicketStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HubSupportTicketStatusTest extends TestCase
{
    #[DataProvider('validTransitions')]
    public function test_intended_admin_transitions_are_allowed(HubSupportTicketStatus $from, HubSupportTicketStatus $to): void
    {
        self::assertTrue($from->canTransitionTo($to));
    }

    #[DataProvider('invalidTransitions')]
    public function test_administrative_shortcuts_are_rejected(HubSupportTicketStatus $from, HubSupportTicketStatus $to): void
    {
        self::assertFalse($from->canTransitionTo($to));
    }

    /** @return iterable<string, array{HubSupportTicketStatus, HubSupportTicketStatus}> */
    public static function validTransitions(): iterable
    {
        yield 'new acknowledged' => [HubSupportTicketStatus::New, HubSupportTicketStatus::Acknowledged];
        yield 'new in progress' => [HubSupportTicketStatus::New, HubSupportTicketStatus::InProgress];
        yield 'acknowledged in progress' => [HubSupportTicketStatus::Acknowledged, HubSupportTicketStatus::InProgress];
        yield 'acknowledged waiting' => [HubSupportTicketStatus::Acknowledged, HubSupportTicketStatus::WaitingForReporter];
        yield 'acknowledged resolved' => [HubSupportTicketStatus::Acknowledged, HubSupportTicketStatus::Resolved];
        yield 'in progress waiting' => [HubSupportTicketStatus::InProgress, HubSupportTicketStatus::WaitingForReporter];
        yield 'in progress resolved' => [HubSupportTicketStatus::InProgress, HubSupportTicketStatus::Resolved];
        yield 'waiting in progress' => [HubSupportTicketStatus::WaitingForReporter, HubSupportTicketStatus::InProgress];
        yield 'waiting resolved' => [HubSupportTicketStatus::WaitingForReporter, HubSupportTicketStatus::Resolved];
        yield 'resolved closed' => [HubSupportTicketStatus::Resolved, HubSupportTicketStatus::Closed];
        yield 'resolved reopen' => [HubSupportTicketStatus::Resolved, HubSupportTicketStatus::InProgress];
        yield 'closed reopen' => [HubSupportTicketStatus::Closed, HubSupportTicketStatus::InProgress];
    }

    /** @return iterable<string, array{HubSupportTicketStatus, HubSupportTicketStatus}> */
    public static function invalidTransitions(): iterable
    {
        yield 'new waiting' => [HubSupportTicketStatus::New, HubSupportTicketStatus::WaitingForReporter];
        yield 'new resolved' => [HubSupportTicketStatus::New, HubSupportTicketStatus::Resolved];
        yield 'new closed' => [HubSupportTicketStatus::New, HubSupportTicketStatus::Closed];
        yield 'acknowledged closed' => [HubSupportTicketStatus::Acknowledged, HubSupportTicketStatus::Closed];
        yield 'in progress closed' => [HubSupportTicketStatus::InProgress, HubSupportTicketStatus::Closed];
        yield 'waiting acknowledged' => [HubSupportTicketStatus::WaitingForReporter, HubSupportTicketStatus::Acknowledged];
        yield 'waiting closed' => [HubSupportTicketStatus::WaitingForReporter, HubSupportTicketStatus::Closed];
    }
}
