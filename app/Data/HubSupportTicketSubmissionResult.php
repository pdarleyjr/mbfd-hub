<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\HubSupportTicket;

final readonly class HubSupportTicketSubmissionResult
{
    public function __construct(public HubSupportTicket $ticket, public bool $created) {}
}
