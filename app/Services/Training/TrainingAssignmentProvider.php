<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Employee;
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
        $assignments = [];
        foreach ($payload['members'] as $member) {
            $matches = $employees->get($member['employee_id']);
            if ($matches !== null && $matches->count() !== 1) {
                $this->unavailable();
            }
            $employee = $matches?->first();
            if ($member['eligible'] !== true || $employee === null || $employee->roster_status !== 'active') {
                continue;
            }
            // Missing placement/name evidence requires the supervised exception flow.
            if (blank($member['assignment_id']) || blank($member['assignment_source']) || blank($member['first_name'])
                || blank($member['last_name']) || blank($member['rank']) || blank($member['shift']) || blank($member['effective_from'])) {
                continue;
            }
            if ($member['effective_from'] > $asOf || ($member['effective_to'] !== null && $member['effective_to'] < $asOf)
                || isset($assignments[$member['assignment_id']])) {
                $this->unavailable();
            }
            $assignments[$member['assignment_id']] = true;
            $result[] = [
                'employee_profile_id' => (int) $employee->getKey(),
                'employee_id' => (string) $employee->employee_id,
                'first_name' => $member['first_name'],
                'last_name' => $member['last_name'],
                'display_name' => (string) $employee->name,
                'rank' => $member['rank'],
                'shift' => $member['shift'],
                'term_label' => $payload['term_label'] ?? 'Current assignment',
                'assignment_source' => $member['assignment_source'],
                'assignment_id' => $member['assignment_id'],
                'effective_from' => $member['effective_from'],
                'effective_to' => $member['effective_to'],
                'as_of' => $asOf,
                'source_generated_at' => $payload['generated_at'],
                'source_updated_at' => $payload['source_updated_at'],
                'source_record_sha256' => hash('sha256', json_encode([$asOf, $member], JSON_THROW_ON_ERROR)),
            ];
        }
        usort($result, static fn (array $a, array $b): int => strcasecmp($a['last_name'].' '.$a['first_name'].' '.$a['employee_id'], $b['last_name'].' '.$b['first_name'].' '.$b['employee_id']));

        return $result;
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['assignment' => 'Current assignments could not be verified. Ask the instructor for assistance.']);
    }
}
