<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

/** Which Playbooks template a catalog result maps to, and how closely staff should watch the thread. */
final readonly class ReplyChoice
{
    /**
     * @param  'routine'|'elevated'|'critical'  $tier
     * @param  bool  $supportServices  true when Support Services itself handles the follow-up (the weekend line applies)
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $key,
        public string $tier,
        public bool $supportServices,
        public array $reasons,
    ) {}
}
