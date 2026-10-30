<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Services\Identity\EmployeeIdentifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Who may sign a classroom roster.
 *
 * Authority: a person signs when the unique canonical Hub Employee is active
 * (the Hub roster is reconciled against the City roster) and the Bid bridge
 * has no negative employment evidence (inactive, retired, separated). A
 * published bid selection supplies the shift when one exists, but it is NOT a
 * precondition: the Fire Chief, Deputy Chiefs and other personnel whose
 * positions do not bid have no selection and must still be able to sign.
 */
class TrainingAssignmentProvider
{
    /** Shift bucket for active personnel who have neither a published bid nor an operational shift. */
    public const STAFF_SHIFT_LABEL = 'Command & Staff';

    public const UNRECORDED_RANK_LABEL = 'Rank not recorded';

    private const SHIFTS = ['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'];

    private const KNOWN_RANKS = ['firefighter' => 'Firefighter', 'lieutenant' => 'Lieutenant', 'captain' => 'Captain',
        'division chief' => 'Division Chief', 'deputy chief' => 'Deputy Chief', 'fire chief' => 'Fire Chief', 'civilian' => 'Civilian'];

    /** @return list<array<string, mixed>> */
    public function candidates(string $asOf): array
    {
        return $this->evaluate($asOf)['candidates'];
    }

    /**
     * Every decision made for the date, so operators can see who was left out and why.
     *
     * @return array{as_of: string, bid_year: int, candidates: list<array<string, mixed>>, exclusions: list<array{employee_profile_id: int|null, employee_id: string, rank: string|null, reason: string}>}
     */
    public function evaluate(string $asOf): array
    {
        Validator::make(['as_of' => $asOf], ['as_of' => ['required', 'date_format:Y-m-d']])->validate();
        $publishedBefore = CarbonImmutable::parse($asOf, \App\Support\HubTimezone::NAME)->addDay()->startOfDay()->utc();
        $published = EmployeeBidAssignment::query()->where('payload_version', 2)->where('created_at', '<', $publishedBefore)
            ->where(fn ($query) => $query->whereNull('superseded_at')->orWhere('superseded_at', '>=', $publishedBefore));
        $bidYear = (clone $published)->max('bid_year');
        if ($bidYear === null) {
            $this->unavailable();
        }
        $bidYear = (int) $bidYear;
        $publishedAssignments = $published->where('bid_year', $bidYear)->get()->groupBy('employee_profile_id')->all();
        $payload = $this->bridgePayload($asOf);

        /** @var Collection<string, Collection<int, Employee>> $hub */
        $hub = Employee::query()->get(['id', 'employee_id', 'name', 'rank', 'roster_status'])
            ->groupBy(fn (Employee $employee): string => EmployeeIdentifier::normalize((string) $employee->employee_id));
        $candidates = [];
        $exclusions = [];
        $matched = [];
        foreach ($payload['members'] as $member) {
            $matches = $hub->get(EmployeeIdentifier::normalize($member['employee_id']));
            if ($matches !== null && $matches->count() !== 1) {
                $this->unavailable();
            }
            $employee = $matches?->first();
            if ($employee !== null) {
                // Two bridge rows must never resolve to one person.
                if (isset($matched[$employee->getKey()])) {
                    $this->unavailable();
                }
                $matched[$employee->getKey()] = true;
            }
            $reason = $this->bridgeExclusion($member, $employee);
            if ($reason !== null) {
                $exclusions[] = $this->exclusion($employee, $member['employee_id'], $member['rank'] ?? null, $reason);

                continue;
            }
            $result = $this->consider($employee, $member, $publishedAssignments, $bidYear, $asOf, $payload);
            if ($result['candidate'] === null) {
                $exclusions[] = $this->exclusion($employee, $member['employee_id'], $member['rank'] ?? null, $result['reason']);
            } else {
                $candidates[] = $result['candidate'];
            }
        }

        // The Hub roster is authoritative for current membership. A person the bridge does not list has
        // no negative evidence; they remain eligible with Hub-held name and rank, and are reported.
        foreach ($hub as $identity => $group) {
            foreach ($group as $employee) {
                if (isset($matched[$employee->getKey()]) || $employee->roster_status !== 'active') {
                    continue;
                }
                if ($identity === '' || $group->count() !== 1) {
                    $exclusions[] = $this->exclusion($employee, (string) $employee->employee_id, $employee->rank, $identity === '' ? 'hub_identity_missing' : 'hub_identity_duplicate');

                    continue;
                }
                $member = ['employee_id' => (string) $employee->employee_id, 'first_name' => null, 'last_name' => null, 'rank' => null,
                    'shift' => null, 'assignment_id' => null, 'assignment_source' => null, 'effective_from' => null, 'effective_to' => null,
                    'eligible' => true, 'employment_status' => 'not_listed'];
                $result = $this->consider($employee, $member, $publishedAssignments, $bidYear, $asOf, $payload);
                if ($result['candidate'] === null) {
                    $exclusions[] = $this->exclusion($employee, (string) $employee->employee_id, $employee->rank, $result['reason']);
                } else {
                    $candidates[] = $result['candidate'];
                }
            }
        }
        usort($candidates, static fn (array $a, array $b): int => strcasecmp($a['last_name'].' '.$a['first_name'].' '.$a['employee_id'], $b['last_name'].' '.$b['first_name'].' '.$b['employee_id']));

        return ['as_of' => $asOf, 'bid_year' => $bidYear, 'candidates' => $candidates, 'exclusions' => $exclusions];
    }

    /** @return array<string, mixed> */
    private function bridgePayload(string $asOf): array
    {
        $base = rtrim((string) config('training-roster.bid_api_url'), '/');
        $token = (string) config('services.bid.reader_token');
        if (! str_starts_with($base, 'https://') || $token === '') {
            $this->unavailable();
        }
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])
                ->withToken($token)->acceptJson()->get($base.'/api/portal/training-roster', ['as_of' => $asOf]);
            $payload = $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            $this->unavailable();
        }
        if (! is_array($payload)) {
            $this->unavailable();
        }
        $rules = [
            'schema_version' => ['required', 'integer', Rule::in([1])],
            'as_of' => ['required', Rule::in([$asOf])],
            'generated_at' => ['required', 'date'],
            'source_updated_at' => ['present', 'nullable', 'integer'],
            'term_label' => ['present', 'nullable', 'string', 'max:32'],
            'members' => ['present', 'array', 'max:2000'],
            'members.*.employee_id' => ['required', 'string', 'max:64', 'distinct:strict'],
            'members.*.first_name' => ['present', 'nullable', 'string', 'max:100'],
            'members.*.last_name' => ['present', 'nullable', 'string', 'max:100'],
            'members.*.rank' => ['present', 'nullable', 'string', 'max:100'],
            'members.*.shift' => ['present', 'nullable', Rule::in(self::SHIFTS)],
            'members.*.assignment_id' => ['present', 'nullable', 'string', 'max:200'],
            'members.*.assignment_source' => ['present', 'nullable', 'string', 'max:80'],
            'members.*.effective_from' => ['present', 'nullable', 'date_format:Y-m-d'],
            'members.*.effective_to' => ['present', 'nullable', 'date_format:Y-m-d'],
            'members.*.eligible' => ['required', 'boolean'],
            'members.*.employment_status' => ['required', Rule::in(['unknown', 'active', 'inactive', 'retired', 'separated'])],
        ];
        if (Validator::make($payload, $rules)->fails() || ($payload['schema_version'] ?? null) !== 1) {
            $this->unavailable();
        }
        $generated = CarbonImmutable::parse($payload['generated_at']);
        if ($generated->isBefore(now()->subMinutes(2)) || $generated->isAfter(now()->addSeconds(30))) {
            $this->unavailable();
        }

        return $payload;
    }

    /** Explicit inactive, retired and separated Bid statuses stay excluded; so does a departed Hub profile. */
    private function bridgeExclusion(array $member, ?Employee $employee): ?string
    {
        if (! in_array($member['employment_status'], ['active', 'unknown'], true)) {
            return 'bridge_status_'.$member['employment_status'];
        }
        if ($member['eligible'] !== true) {
            return 'bridge_not_eligible';
        }
        if ($employee === null) {
            return 'no_hub_profile';
        }

        return $employee->roster_status === 'active' ? null : 'hub_not_active';
    }

    /**
     * @param  array<int|string, \Illuminate\Database\Eloquent\Collection<int, EmployeeBidAssignment>>  $publishedAssignments
     * @return array{candidate: array<string, mixed>|null, reason: string}
     */
    private function consider(Employee $employee, array $member, array $publishedAssignments, int $bidYear, string $asOf, array $payload): array
    {
        $assignment = $this->publishedAssignment($publishedAssignments[$employee->getKey()] ?? null, $bidYear);
        $person = $this->person($member, $employee);
        if ($person === null) {
            return ['candidate' => null, 'reason' => 'name_missing'];
        }
        $base = [
            'employee_profile_id' => (int) $employee->getKey(),
            'employee_id' => (string) $employee->employee_id,
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'display_name' => (string) $employee->name,
            'rank' => $person['rank'],
            'name_source' => $person['name_source'],
            'rank_source' => $person['rank_source'],
            'effective_from' => null,
            'effective_to' => null,
            'as_of' => $asOf,
            'source_generated_at' => $payload['generated_at'],
            'source_updated_at' => $payload['source_updated_at'],
            'source_employment_status' => $member['employment_status'],
        ];
        if ($assignment === null) {
            // A position that does not bid has no selection to publish. Operational shift, when the
            // bridge knows one, is the only evidence; otherwise the person is grouped as command/staff.
            return ['candidate' => $base + [
                'shift' => in_array($member['shift'] ?? null, self::SHIFTS, true) ? $member['shift'] : self::STAFF_SHIFT_LABEL,
                'term_label' => null,
                'assignment_source' => 'non_bid_roster',
                'assignment_id' => $member['assignment_id'] ?? null,
                'assignment_basis' => 'hub_active_roster',
                'source_record_sha256' => hash('sha256', json_encode([$asOf, $member, null], JSON_THROW_ON_ERROR)),
            ], 'reason' => 'included'];
        }

        // Bid publishes the selected term/shift through the existing canonical
        // receiver. Operational placement can still describe the previous bid.
        // Only person names/rank come from the dated bridge (or the Hub profile).
        return ['candidate' => $base + [
            'shift' => $assignment->shift_label,
            'term_label' => $assignment->term_label,
            'assignment_source' => $assignment->assignment_source,
            'assignment_id' => 'bid-published:'.$assignment->getKey(),
            'assignment_basis' => 'published_bid_selection',
            'bid_year' => $assignment->bid_year,
            'bid_session_id' => $assignment->bid_session_id,
            'source_sequence' => $assignment->source_sequence,
            'source_result_hash' => $assignment->source_result_hash,
            'source_workbook_sha256' => $assignment->source_workbook_sha256,
            'published_payload_hash' => $assignment->payload_hash,
            'source_record_sha256' => hash('sha256', json_encode([$asOf, $member, $assignment->payload_hash], JSON_THROW_ON_ERROR)),
        ], 'reason' => 'included'];
    }

    /**
     * The one verified published selection, or null for a position that does not bid.
     * Ambiguous or unverifiable provenance fails closed for the whole roster.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, EmployeeBidAssignment>|null  $matches
     */
    private function publishedAssignment(?Collection $matches, int $bidYear): ?EmployeeBidAssignment
    {
        if ($matches === null) {
            return null;
        }
        if ($matches->count() !== 1) {
            $this->unavailable();
        }
        $assignment = $matches->firstOrFail();
        if (Validator::make($assignment->getAttributes(), [
            'term_label' => ['required', Rule::in([$bidYear.'–'.($bidYear + 1)])],
            'shift_label' => ['required', Rule::in(self::SHIFTS)],
            'assignment_source' => ['required', Rule::in(['bid_award', 'retained_nonbiddable'])],
            'bid_session_id' => ['required', 'string', 'max:100'],
            'source_sequence' => ['required', 'integer', 'min:0'],
            'source_result_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'source_workbook_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'payload_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ])->fails()) {
            $this->unavailable();
        }

        return $assignment;
    }

    /**
     * Bridge names and rank are authoritative. When the bridge lacks them the Hub profile supplies them,
     * recorded as derived so a reviewer can tell. Only a person with no usable name at all is left out.
     *
     * @return array{first_name: string, last_name: string, rank: string, name_source: string, rank_source: string}|null
     */
    private function person(array $member, Employee $employee): ?array
    {
        $first = trim((string) ($member['first_name'] ?? ''));
        $last = trim((string) ($member['last_name'] ?? ''));
        $nameSource = 'bridge';
        if ($first === '' || $last === '') {
            [$first, $last] = self::splitName((string) $employee->name);
            $nameSource = 'hub_profile_derived';
        }
        if ($last === '') {
            return null;
        }
        $rank = trim((string) ($member['rank'] ?? ''));
        $rankSource = 'bridge';
        if ($rank === '') {
            $rank = trim((string) $employee->rank);
            $rankSource = 'hub_profile';
        }
        if ($rank === '') {
            $rank = self::UNRECORDED_RANK_LABEL;
            $rankSource = 'unrecorded';
        }

        return ['first_name' => $first, 'last_name' => $last, 'rank' => self::KNOWN_RANKS[mb_strtolower($rank)] ?? $rank,
            'name_source' => $nameSource, 'rank_source' => $rankSource];
    }

    /**
     * "Last, First" or "First Middle Last". Display only: identity is the profile id, never the name.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (str_contains($name, ',')) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));

            return [$first, $last];
        }
        $position = mb_strrpos($name, ' ');

        return $position === false ? ['', $name] : [mb_substr($name, 0, $position), mb_substr($name, $position + 1)];
    }

    /** @return array{employee_profile_id: int|null, employee_id: string, rank: string|null, reason: string} */
    private function exclusion(?Employee $employee, string $employeeId, ?string $rank, string $reason): array
    {
        return ['employee_profile_id' => $employee === null ? null : (int) $employee->getKey(), 'employee_id' => $employeeId, 'rank' => $rank, 'reason' => $reason];
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['assignment' => 'Published bid assignments could not be verified. Ask the instructor for assistance.']);
    }
}
