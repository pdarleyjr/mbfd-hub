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
    /** What an administrator picks in the access forms. */
    public const CHOICE_LABELS = [
        'automatic' => 'Automatic (follow rank and seat)',
        'allow' => 'Allow: can use the Captain 5 page',
        'deny' => 'Deny: cannot use the Captain 5 page',
    ];

    /** Short names for badges, lists and history lines. */
    public const CHOICE_NAMES = [
        'automatic' => 'Automatic',
        'allow' => 'Allow',
        'deny' => 'Deny',
    ];

    /** The help line under each choice in the access forms. */
    public const CHOICE_DESCRIPTIONS = [
        'automatic' => 'Rank, seat and administrator access decide. Removes any earlier Allow or Deny.',
        'allow' => 'Can use the whole Captain 5 page, even without a qualifying rank or seat.',
        'deny' => 'Cannot open Captain 5, even with a qualifying rank or seat.',
    ];

    public function __construct(private readonly Captain5Access $access) {}

    /**
     * @return array{
     *     headline: string,
     *     tone: 'success'|'danger'|'gray',
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
            'label' => self::CHOICE_NAMES[self::choiceFor($override)],
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
            return $this->result('No Captain 5 access. This member does not qualify by rank, seat or administrator access.', 'gray', [], $overrideSummary, $this->capabilities($decision), $notes);
        }

        $headline = $decision->isShownCaptain5
            ? 'Can use the whole Captain 5 page, and is shown as the shift\'s Captain 5 right now.'
            : 'Can use the whole Captain 5 page.';

        return $this->result($headline, 'success', $this->sources($decision, $employee), $overrideSummary, $this->capabilities($decision), $notes);
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
            default => 'allow',
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
                Captain5AccessDecision::SOURCE_ADMINISTRATOR => 'Admin Panel account',
                Captain5AccessDecision::SOURCE_OFFICER_RANK => 'Officer rank'.($employee?->rank ? ': '.$employee->rank : ''),
                Captain5AccessDecision::SOURCE_CAPTAIN5_SEAT => 'Holds the Captain 5 seat'.($decision->bidShiftLetter ? ' on '.$decision->bidShiftLetter.' shift' : ''),
                Captain5AccessDecision::SOURCE_EMS_CAPTAIN_SEAT => 'Holds the EMS Captain seat',
                Captain5AccessDecision::SOURCE_EXPLICIT_ALLOW => 'Allowed by an administrator',
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
            ['label' => 'Shown as the shift\'s Captain 5 now (display only)', 'allowed' => $decision->isShownCaptain5],
            ['label' => 'Close issues with no action required', 'allowed' => $decision->canDismiss],
            ['label' => 'Configure Captain 5 in the Admin Panel', 'allowed' => $decision->canConfigure],
        ];
    }

    /**
     * @param  list<string>  $sources
     * @param  array{choice: string, label: string, reason: string, set_by: string, at: string}|null  $override
     * @param  list<array{label: string, allowed: bool}>  $capabilities
     * @param  list<string>  $notes
     * @param  'success'|'danger'|'gray'  $tone
     * @return array{headline: string, tone: 'success'|'danger'|'gray', sources: list<string>, capabilities: list<array{label: string, allowed: bool}>, override: array{choice: string, label: string, reason: string, set_by: string, at: string}|null, notes: list<string>}
     */
    private function result(string $headline, string $tone, array $sources, ?array $override, array $capabilities, array $notes = []): array
    {
        return compact('headline', 'tone', 'sources', 'capabilities', 'override', 'notes');
    }

    private function when(mixed $moment): string
    {
        return $moment === null ? '' : CarbonImmutable::parse($moment)->setTimezone((string) config('captain5.timezone', \App\Support\HubTimezone::NAME))->format('M j, Y g:i A');
    }
}
