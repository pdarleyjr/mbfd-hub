<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrainingAssignmentProvider
{
    /** @return list<array<string, mixed>> */
    public function candidates(string $asOf): array
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
        $publishedAssignments = $published->where('bid_year', $bidYear)->get()->groupBy('employee_profile_id');
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
            'members.*.shift' => ['present', 'nullable', Rule::in(['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'])],
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
        $employees = Employee::query()->whereIn('employee_id', array_column($payload['members'], 'employee_id'))
            ->get(['id', 'employee_id', 'name', 'roster_status'])->groupBy('employee_id');
        $result = [];
        foreach ($payload['members'] as $member) {
            $matches = $employees->get($member['employee_id']);
            if ($matches !== null && $matches->count() !== 1) {
                $this->unavailable();
            }
            $employee = $matches?->first();
            if ($member['eligible'] !== true || ! in_array($member['employment_status'], ['active', 'unknown'], true)
                || $employee === null || $employee->roster_status !== 'active') {
                continue;
            }
            $matches = $publishedAssignments->get($employee->getKey());
            if ($matches === null) {
                continue;
            }
            if ($matches->count() !== 1) {
                $this->unavailable();
            }
            $assignment = $matches->firstOrFail();
            if (Validator::make($assignment->getAttributes(), [
                'term_label' => ['required', Rule::in([$bidYear.'–'.($bidYear + 1)])],
                'shift_label' => ['required', Rule::in(['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'])],
                'assignment_source' => ['required', Rule::in(['bid_award', 'retained_nonbiddable'])],
                'bid_session_id' => ['required', 'string', 'max:100'],
                'source_sequence' => ['required', 'integer', 'min:0'],
                'source_result_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
                'source_workbook_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
                'payload_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            ])->fails()) {
                $this->unavailable();
            }
            // Bid publishes the selected term/shift through the existing canonical
            // receiver. Operational placement can still describe the previous bid.
            // Only authoritative person names/rank come from the dated bridge.
            if (blank($member['first_name']) || blank($member['last_name']) || blank($member['rank'])) {
                continue;
            }
            $result[] = [
                'employee_profile_id' => (int) $employee->getKey(),
                'employee_id' => (string) $employee->employee_id,
                'first_name' => $member['first_name'],
                'last_name' => $member['last_name'],
                'display_name' => (string) $employee->name,
                'rank' => $member['rank'],
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
                'effective_from' => null,
                'effective_to' => null,
                'as_of' => $asOf,
                'source_generated_at' => $payload['generated_at'],
                'source_updated_at' => $payload['source_updated_at'],
                'source_employment_status' => $member['employment_status'],
                'source_record_sha256' => hash('sha256', json_encode([$asOf, $member, $assignment->payload_hash], JSON_THROW_ON_ERROR)),
            ];
        }
        usort($result, static fn (array $a, array $b): int => strcasecmp($a['last_name'].' '.$a['first_name'].' '.$a['employee_id'], $b['last_name'].' '.$b['first_name'].' '.$b['employee_id']));

        return $result;
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['assignment' => 'Published bid assignments could not be verified. Ask the instructor for assistance.']);
    }
}
