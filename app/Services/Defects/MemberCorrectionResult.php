<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Support\Fleet\DefectActionStatus;

/** What a member's "fixed" did, in the member's words (DefectMemberCorrection). */
final readonly class MemberCorrectionResult
{
    public const RESOLVED = 'resolved';

    public const AWAITING_VERIFICATION = 'awaiting_verification';

    public function __construct(public string $result, public string $statusLabel, public string $message) {}

    public static function fromEvent(ApparatusDefectEvent $event, ApparatusDefect $defect): self
    {
        if ($event->event_type === DefectMemberCorrection::EVENT_RESOLVED) {
            return new self(self::RESOLVED, DefectActionStatus::label(DefectActionStatus::RESOLVED), 'Marked resolved. Thanks for closing the loop.');
        }
        $owner = $defect->getAttribute('responsible_owner') === ApparatusDefect::OWNER_CAPTAIN5 ? 'Captain 5' : 'Support Services';

        return new self(self::AWAITING_VERIFICATION, 'Correction reported, awaiting verification', "Thanks. {$owner} will verify it before the issue is closed.");
    }

    /** @return array{result: string, status_label: string, message: string} */
    public function toArray(): array
    {
        return ['result' => $this->result, 'status_label' => $this->statusLabel, 'message' => $this->message];
    }
}
