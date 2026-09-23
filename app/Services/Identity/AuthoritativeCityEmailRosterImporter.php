<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

final class AuthoritativeCityEmailRosterImporter
{
    public function __construct(private readonly CanonicalCityEmailService $cityEmails) {}

    /** @return array{source_sha256:string,records:list<array{employee_id:string,city_email:string,status:string,employee_profile_id:int|null,user_id:int|null}>,statuses:array<string,int>} */
    public function preview(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The authoritative roster CSV is missing or unreadable.');
        }
        $source = file_get_contents($path);
        if ($source === false) {
            throw new InvalidArgumentException('The authoritative roster CSV could not be read.');
        }
        $rows = $this->parse($source);
        $ids = array_count_values(array_column($rows, 'employee_id'));
        $emails = array_count_values(array_filter(array_column($rows, 'city_email')));
        $records = [];
        foreach ($rows as $row) {
            $employeeId = $row['employee_id'];
            $cityEmail = $row['city_email'];
            $status = $employeeId === '' ? 'missing_employee_id' : null;
            $status ??= ($ids[$employeeId] ?? 0) > 1 ? 'duplicate_roster_employee_id' : null;
            $status ??= $cityEmail === '' ? 'missing_city_email' : null;
            $status ??= ($emails[$cityEmail] ?? 0) > 1 ? 'duplicate_roster_city_email' : null;
            $status ??= filter_var($cityEmail, FILTER_VALIDATE_EMAIL) === false
                || ! str_ends_with($cityEmail, '@miamibeachfl.gov') ? 'invalid_city_email' : null;
            $records[] = $this->assess($employeeId, $cityEmail, $status);
        }

        return [
            'source_sha256' => hash('sha256', $source),
            'records' => $records,
            'statuses' => collect($records)->countBy('status')->sortKeys()->all(),
        ];
    }

    /** @param array{source_sha256:string,records:list<array{employee_id:string,city_email:string,status:string,employee_profile_id:int|null,user_id:int|null}>,statuses:array<string,int>} $preview
     * @return array{bound:int,unchanged:int,invalidated:int}
     */
    public function apply(array $preview): array
    {
        $blocked = array_diff(array_keys($preview['statuses']), ['ready', 'unchanged', 'established_skipped', 'inactive_skipped', 'missing_city_email']);
        if ($blocked !== []) {
            throw new InvalidArgumentException('Roster conflicts require review; no City emails were imported.');
        }

        $result = DB::transaction(function () use ($preview): array {
            $bound = 0;
            $unchanged = 0;
            $approvedProfileIds = [];
            foreach ($preview['records'] as $record) {
                if (! in_array($record['status'], ['ready', 'unchanged'], true)) {
                    continue;
                }
                $user = User::query()->lockForUpdate()->findOrFail($record['user_id']);
                $employee = Employee::query()->lockForUpdate()->findOrFail($record['employee_profile_id']);
                $current = $this->assess($record['employee_id'], $record['city_email'], null);
                if (! in_array($current['status'], ['ready', 'unchanged'], true)
                    || $current['user_id'] !== $user->id || $current['employee_profile_id'] !== $employee->id) {
                    throw new RuntimeException('A canonical identity changed after roster preview; no City emails were imported.');
                }
                if ($record['status'] === 'ready') {
                    $this->cityEmails->sync($employee, $user, $record['city_email']);
                }
                $binding = MemberOnboardingRosterBinding::query()->where('employee_profile_id', $employee->id)->lockForUpdate()->first();
                if ($binding?->employee_id !== $record['employee_id']
                    || $binding->city_email !== $record['city_email']
                    || $binding->source_sha256 !== $preview['source_sha256']) {
                    MemberOnboardingRosterBinding::query()->updateOrCreate(
                        ['employee_profile_id' => $employee->id],
                        [
                            'employee_id' => $record['employee_id'],
                            'city_email' => $record['city_email'],
                            'source_sha256' => $preview['source_sha256'],
                            'approved_at' => now(),
                        ],
                    );
                }
                $approvedProfileIds[] = $employee->id;
                $record['status'] === 'unchanged' ? $unchanged++ : $bound++;
            }

            // This CSV is the complete approved onboarding roster. A pending
            // account omitted from it, or now lacking an email, loses its proof.
            $pendingProfileIds = User::query()
                ->where('account_status', AccountStatus::PendingActivation->value)
                ->where('bootstrap_onboarding_eligible', true)
                ->whereNotNull('employee_profile_id')
                ->pluck('employee_profile_id');
            $invalidated = MemberOnboardingRosterBinding::query()
                ->whereIn('employee_profile_id', $pendingProfileIds)
                ->whereNotIn('employee_profile_id', $approvedProfileIds)
                ->delete();

            return compact('bound', 'unchanged', 'invalidated');
        }, 3);

        Log::notice('authoritative_city_email_roster_imported', ['source_sha256' => $preview['source_sha256']] + $result);

        return $result;
    }

    /** @return list<array{employee_id:string,city_email:string}> */
    private function parse(string $source): array
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('The roster CSV could not be parsed.');
        }
        fwrite($stream, $source);
        rewind($stream);
        try {
            $header = fgetcsv($stream);
            if (! is_array($header)) {
                throw new InvalidArgumentException('The roster CSV is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $employeeIdIndex = array_search('Employee ID', $header, true);
            $emailIndex = array_search('Email Address', $header, true);
            if ($employeeIdIndex === false || $emailIndex === false || count(array_unique($header)) !== count($header)) {
                throw new InvalidArgumentException('The roster CSV requires unique Employee ID and Email Address columns.');
            }
            $rows = [];
            while (($values = fgetcsv($stream)) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new InvalidArgumentException('A roster CSV row has the wrong number of columns.');
                }
                $rows[] = [
                    'employee_id' => trim((string) $values[$employeeIdIndex]),
                    'city_email' => strtolower(trim((string) $values[$emailIndex])),
                ];
            }
            if ($rows === []) {
                throw new InvalidArgumentException('The roster CSV has no employee rows.');
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /** @return array{employee_id:string,city_email:string,status:string,employee_profile_id:int|null,user_id:int|null} */
    private function assess(string $employeeId, string $cityEmail, ?string $knownStatus): array
    {
        $status = $knownStatus;
        $employee = null;
        $user = null;
        if ($status === null) {
            $employees = Employee::query()->where('employee_id', $employeeId)->limit(2)->get();
            $status = $employees->count() !== 1 ? ($employees->isEmpty() ? 'employee_not_found' : 'duplicate_database_employee_id') : null;
            $employee = $employees->first();
        }
        if ($status === null && $employee instanceof Employee) {
            $status = $employee->roster_status !== 'active' ? 'inactive_skipped' : null;
            $users = User::query()->where('employee_profile_id', $employee->id)->limit(2)->get();
            $identifierUsers = User::query()->where('employee_id', $employeeId)->limit(2)->get();
            if ($status === null && ($users->count() !== 1 || $identifierUsers->count() !== 1 || ! $users->first()->is($identifierUsers->first()))) {
                $status = 'canonical_identity_conflict';
            }
            $user = $users->count() === 1 ? $users->first() : null;
            if ($status === null && $user instanceof User) {
                $status = $user->getRawOriginal('account_status') === AccountStatus::Active->value ? 'established_skipped' : null;
                $status ??= $user->getRawOriginal('account_status') !== AccountStatus::PendingActivation->value
                    || ! $user->bootstrap_onboarding_eligible
                    || $user->bootstrap_onboarding_completed_at !== null ? 'not_pending_onboarding' : null;
            }
            if ($status === null && $user instanceof User) {
                $collision = Employee::query()->whereKeyNot($employee->id)->whereRaw('LOWER(city_email) = ?', [$cityEmail])->exists()
                    || User::query()->whereKeyNot($user->id)->whereRaw('LOWER(email) = ?', [$cityEmail])->exists()
                    || MemberOnboardingRosterBinding::query()->where('employee_profile_id', '!=', $employee->id)->where('city_email', $cityEmail)->exists();
                $status = $collision ? 'city_email_collision' : null;
            }
            if ($status === null && $user instanceof User) {
                $binding = MemberOnboardingRosterBinding::query()->where('employee_profile_id', $employee->id)->first();
                $status = $employee->city_email === $cityEmail && $user->email === $cityEmail
                    && $binding?->employee_id === $employeeId && $binding->city_email === $cityEmail ? 'unchanged' : 'ready';
            }
        }

        return [
            'employee_id' => $employeeId,
            'city_email' => $cityEmail,
            'status' => $status ?? 'unknown',
            'employee_profile_id' => $employee?->id,
            'user_id' => $user?->id,
        ];
    }
}
