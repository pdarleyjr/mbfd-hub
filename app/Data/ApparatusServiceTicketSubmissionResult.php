<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ApparatusServiceTicket;

final readonly class ApparatusServiceTicketSubmissionResult
{
    public function __construct(
        public ApparatusServiceTicket $ticket,
        public bool $created,
    ) {}
}
