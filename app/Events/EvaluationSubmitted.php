<?php

namespace App\Events;

use App\Models\EvaluationSubmission;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an evaluation is submitted.
 * Triggers AI analysis of anonymous evaluation text.
 */
class EvaluationSubmitted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public EvaluationSubmission $submission
    ) {}
}
