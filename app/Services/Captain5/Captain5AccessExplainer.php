<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Services\Security\Captain5AccessDecision;
use Carbon\CarbonImmutable;

/**
 * Plain-language reading of the single Captain 5 access decision for the Admin Panel: why a
 * member can or cannot use Captain 5, and which administrator choice (if any) applies. It never
 * decides anything itself; every answer comes from Captain5Access::decide().
 */
final class Captain5AccessExplainer
{
    public const CHOICE_LABELS = [
        'automatic' => 'Automatic (follow rank and seat)',
        'allow_view' => 'Allow, view only',
        'allow_act' => 'Allow, view and act',
        'deny' => 'Deny',
    ];

    public function __construct(private readonly Captain5Access $access) {}

    /**
     * @return array{
     *     headline: string,
     *     tone: 'success'|'info'|'danger'|'gray',
     *     sources: list<string>,
     *     capabilities: list<array{label: string, allowed: bool}>,
     *     override: array{choice: string, label: string, reason: string, set_by: string, at: string}|null,
     *     notes: list<string>
     * }
     */
    public function explain(?User $user, ?Employee $employee = null): array
    {
        $employee ??= $user?->employeeProfile;
        $override = $employee instanceof Employee ? $this->currentOverride((int) $employee->getKey()) : null;
        $overrideSummary = $override instanceof Captain5AccessOverride ? [
            'choice' => self::choiceFor($override),
            'label' => self::CHOICE_LABELS[self::choiceFor($override)],
            'reason' => (string) $override->reason,
            'set_by' => (string) ($override->setBy->name ?? 'A former administrator'),
            'at' => $this->when($override->created_at),
        ] : null;

        if (! $user instanceof User) {
            return $this->result('No login account yet, so no Captain 5 access.', 'gray', [], $overrideSummary, [], [
                'An access choice below is kept and applies once the member has an active account.',
            ]);
        }
        if (! $user->isAuthenticationAllowed()) {
            return $this->result('This account is not active, so there is no Captain 5 access.', 'gray', [], $overrideSummary, []);
        }
        if ($user->must_change_password) {
            return $this->result('This member must finish password setup before Captain 5 access applies.', 'gray', [], $overrideSummary, []);
        }

        $decision = $this->access->decide($user);
        $notes = [];
        $isSuperAdmin = $decision->has(Captain5AccessDecision::SOURCE_SUPER_ADMIN);
        if ($isSuperAdmin && $overrideSummary !== null && $overrideSummary['choice'] === 'deny') {
            $notes[] = 'A deny choice is on file, but super administrators are protected recovery accounts and keep access.';
        } elseif ($isSuperAdmin) {
            $notes[] = 'Super administrators are protected recovery accounts: Captain 5 access cannot be denied.';
        }

        if ($decision->denied) {
            return $this->result('Denied by an administrator. This member cannot open Captain 5.', 'danger', [], $overrideSummary, $this->capabilities($decision), $notes);
        }
        if ($decision->sources === []) {
            return $this->result('No Captain 5 access. This member does not qualify by rank, seat or assignment.', 'gray', [], $overrideSummary, $this->capabilities($decision), $notes);
        }

        $headline = match (true) {
            $decision->hasOnDutyAuthority => 'On-duty Captain 5 authority right now.',
            $decision->canAct => 'Can view and act on Captain 5 issues.',
            default => 'Can view Captain 5, but cannot act on issues.',
        };

        return $this->result($headline, $decision->canAct ? 'success' : 'info', $this->sources($decision, $employee), $overrideSummary, $this->capabilities($decision), $notes);
    }

    /** One line for the employee action modal. */
    public function summary(?User $user, ?Employee $employee = null): string
    {
        $explained = $this->explain($user, $employee);
        $line = $explained['headline'];
        if ($explained['sources'] !== []) {
            $line .= ' '.implode('; ', $explained['sources']).'.';
        }

        return $line;
    }

    public static function choiceFor(?Captain5AccessOverride $override): string
    {
        return match (true) {
            $override === null => 'automatic',
            $override->effect === Captain5AccessOverride::EFFECT_DENY => 'deny',
            $override->level === Captain5AccessOverride::LEVEL_ACT => 'allow_act',
            default => 'allow_view',
        };
    }

    public function currentChoice(int $employeeProfileId): string
    {
        return self::choiceFor($this->currentOverride($employeeProfileId));
    }

    private function currentOverride(int $employeeProfileId): ?Captain5AccessOverride
    {
        return Captain5AccessOverride::query()->current()->with('setBy:id,name')
            ->where('employee_profile_id', $employeeProfileId)->first();
    }

    /** @return list<string> */
    private function sources(Captain5AccessDecision $decision, ?Employee $employee): array
    {
        $lines = [];
        foreach ($decision->sources as $source) {
            $lines[] = match ($source) {
                Captain5AccessDecision::SOURCE_SUPER_ADMIN => 'Super administrator',
                Captain5AccessDecision::SOURCE_ADMINISTRATOR => 'Admin Panel account (view only)',
                Captain5AccessDecision::SOURCE_OFFICER_RANK => 'Officer rank'.($employee?->rank ? ': '.$employee->rank : ''),
                Captain5AccessDecision::SOURCE_CAPTAIN5_SEAT => 'Holds the Captain 5 seat'.($decision->bidShiftLetter ? ' on '.$decision->bidShiftLetter.' shift' : ''),
                Captain5AccessDecision::SOURCE_EMS_CAPTAIN_SEAT => 'Holds the EMS Captain seat',
                Captain5AccessDecision::SOURCE_ACTING => 'Acting Captain 5 for duty day '.($decision->actingAssignment?->duty_date->format('M j') ?? 'today'),
                Captain5AccessDecision::SOURCE_ALLOW_VIEW => 'Allowed by an administrator (view only)',
                Captain5AccessDecision::SOURCE_ALLOW_ACT => 'Allowed by an administrator (view and act)',
                Captain5AccessDecision::SOURCE_REVIEWER => 'Fleet inspection reviewer',
                default => $source,
            };
        }

        return $lines;
    }

    /** @return list<array{label: string, allowed: bool}> */
    private function capabilities(Captain5AccessDecision $decision): array
    {
        return [
            ['label' => 'Open the Captain 5 workspace', 'allowed' => $decision->canViewPage],
            ['label' => 'Act on issues', 'allowed' => $decision->canAct],
            ['label' => 'On-duty Captain 5 authority', 'allowed' => $decision->hasOnDutyAuthority],
            ['label' => 'Dismiss issues', 'allowed' => $decision->canDismiss],
            ['label' => 'Configure Captain 5 in the Admin Panel', 'allowed' => $decision->canConfigure],
        ];
    }

    /**
     * @param  list<string>  $sources
     * @param  array{choice: string, label: string, reason: string, set_by: string, at: string}|null  $override
     * @param  list<array{label: string, allowed: bool}>  $capabilities
     * @param  list<string>  $notes
     * @param  'success'|'info'|'danger'|'gray'  $tone
     * @return array{headline: string, tone: 'success'|'info'|'danger'|'gray', sources: list<string>, capabilities: list<array{label: string, allowed: bool}>, override: array{choice: string, label: string, reason: string, set_by: string, at: string}|null, notes: list<string>}
     */
    private function result(string $headline, string $tone, array $sources, ?array $override, array $capabilities, array $notes = []): array
    {
        return compact('headline', 'tone', 'sources', 'capabilities', 'override', 'notes');
    }

    private function when(mixed $moment): string
    {
        return $moment === null ? '' : CarbonImmutable::parse($moment)->setTimezone((string) config('captain5.timezone', 'America/New_York'))->format('M j, Y g:i A');
    }
}
