<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\User;
use App\Support\Fleet\DefectActionStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/** Small wording and identity helpers shared by every activity adapter, so the sources stay about their own tables. */
final class MemberActivityText
{
    /** What members call the Logistics team and the automated receipts. */
    public const DEPARTMENT = 'Support Services';

    /** One plain line, tags removed, never longer than the configured excerpt length. */
    public static function excerpt(?string $text): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));
        $length = max(20, (int) config('activity.member.excerpt_length', 160));

        return Str::limit($clean, $length - 3, '...');
    }

    public static function moment(mixed $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);
    }

    /** The member's employee id: the resolved employee, else the one linked on the account. */
    public static function employeeId(User $user, ?Employee $employee): ?int
    {
        $id = $employee?->getKey() ?? $user->employee_profile_id;

        return $id === null ? null : (int) $id;
    }

    public static function unitLabel(?Apparatus $apparatus, ?string $snapshot = null): string
    {
        $label = trim((string) ($snapshot ?: ($apparatus?->designation ?: ($apparatus?->name ?: $apparatus?->unit_id))));

        return $label !== '' ? $label : 'Apparatus';
    }

    public static function assistantLabel(): string
    {
        return (string) config('ai_automation.sender_label', self::DEPARTMENT);
    }

    /** The state of an equipment issue in words a member reads. */
    public static function defectStatus(bool $closed, ?string $actionStatus, bool $correctionReported): string
    {
        if ($closed) {
            return DefectActionStatus::isClosed($actionStatus) ? DefectActionStatus::label($actionStatus) : 'Resolved';
        }

        return $correctionReported ? 'Correction reported, awaiting verification' : DefectActionStatus::label($actionStatus);
    }

    public static function ownerLabel(?string $owner): ?string
    {
        return match ($owner) {
            'captain5' => 'Captain 5',
            'logistics' => self::DEPARTMENT,
            default => null,
        };
    }
}
