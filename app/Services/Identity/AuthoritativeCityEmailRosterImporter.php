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

    /** @return array{source_sha256:string,fill_missing:bool,records:list<array{employee_id:string,city_email:string,status:string,employee_profile_id:int|null,user_id:int|null}>,statuses:array<string,int>} */
    public function preview(string $path, bool $fillMissing = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The authoritative roster CSV is missing or unreadable.');
        }
        $source = file_get_contents($path);
        if ($source === false) {
            throw new InvalidArgumentException('The authoritative roster CSV could not be read.');
        }
        $rows = $this->parse($source, $fillMissing);
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
            if ($fillMissing && $status === null && $row['match_status'] !== 'Exact') {
                $status = 'source_review_required';
            }
            $records[] = $fillMissing
                ? $this->assessMissing($employeeId, $cityEmail, $status)
                : $this->assess($employeeId, $cityEmail, $status);
        }

        return [
            'source_sha256' => hash('sha256', $source),
            'fill_missing' => $fillMissing,
            'records' => $records,
            'statuses' => collect($records)->countBy('status')->sortKeys()->all(),
        ];
    }

    /** @param array{source_sha256:string,fill_missing?:bool,records:list<array{employee_id:string,city_email:string,status:string,employee_profile_id:int|null,user_id:int|null}>,statuses:array<string,int>} $preview
     * @return array{bound:int,unchanged:int,invalidated:int,emails_filled?:int,bindings_filled?:int}
     */
    public function apply(array $preview): array
    {
        $fillMissing = $preview['fill_missing'] ?? false;
        $blocked = $this->blockedStatuses($preview);
        if ($blocked !== []) {
            throw new InvalidArgumentException('Roster conflicts require review; no City emails were imported.');
        }

        $result = DB::transaction(function () use ($preview, $fillMissing): array {
            $bound = 0;
            $unchanged = 0;
            $emailsFilled = 0;
            $bindingsFilled = 0;
            $approvedProfileIds = [];
            foreach ($preview['records'] as $record) {
                if (! in_array($record['status'], ['ready', 'ready_binding', 'unchanged'], true)) {
                    continue;
                }
                $user = User::query()->lockForUpdate()->findOrFail($record['user_id']);
                $employee = Employee::query()->lockForUpdate()->findOrFail($record['employee_profile_id']);
                $current = $fillMissing
                    ? $this->assessMissing($record['employee_id'], $record['city_email'], null)
                    : $this->assess($record['employee_id'], $record['city_email'], null);
                if (! in_array($current['status'], ['ready', 'ready_binding', 'unchanged'], true)
                    || ($fillMissing && $current['status'] !== $record['status'])
                    || $current['user_id'] !== $user->id || $current['employee_profile_id'] !== $employee->id) {
                    throw new RuntimeException('A canonical identity changed after roster preview; no City emails were imported.');
                }
                if ($record['status'] === 'ready') {
                    if ($fillMissing) {
                        // These approved repairs change no credentials, mailbox proof or
                        // sessions. Model email observers would clear proof and queue SSO.
                        Employee::query()->whereKey($employee->id)->update(['city_email' => $record['city_email']]);
                        User::query()->whereKey($user->id)->update(['email' => $record['city_email']]);
                        $emailsFilled++;
                    } else {
                        $this->cityEmails->sync($employee, $user, $record['city_email']);
                    }
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
                    if ($record['status'] === 'ready_binding') {
                        $bindingsFilled++;
                    }
                }
                if ($fillMissing) {
                    $audit = [
                        'source_sha256' => $preview['source_sha256'], 'employee_id' => $record['employee_id'],
                        'employee_profile_id' => $employee->id, 'user_id' => $user->id,
                        'repair' => $record['status'] === 'ready_binding' ? 'missing_binding' : 'missing_email',
                    ];
                    DB::afterCommit(static fn () => Log::notice('authoritative_city_email_missing_filled', $audit));
                }
                $approvedProfileIds[] = $employee->id;
                $record['status'] === 'unchanged' ? $unchanged++ : $bound++;
            }

            // This CSV is the complete approved onboarding roster. A pending
            // account omitted from it, or now lacking an email, loses its proof.
            $invalidated = 0;
            if (! $fillMissing) {
                $pendingProfileIds = User::query()
                    ->where('account_status', AccountStatus::PendingActivation->value)
                    ->where('bootstrap_onboarding_eligible', true)
                    ->whereNotNull('employee_profile_id')
                    ->pluck('employee_profile_id');
                $invalidated = MemberOnboardingRosterBinding::query()
                    ->whereIn('employee_profile_id', $pendingProfileIds)
                    ->whereNotIn('employee_profile_id', $approvedProfileIds)
                    ->delete();
            }

            return compact('bound', 'unchanged', 'invalidated')
                + ($fillMissing ? ['emails_filled' => $emailsFilled, 'bindings_filled' => $bindingsFilled] : []);
        }, 3);

        Log::notice('authoritative_city_email_roster_imported', ['source_sha256' => $preview['source_sha256'], 'fill_missing' => $fillMissing] + $result);

        return $result;
    }

    /** @return list<string> */
    public function blockedStatuses(array $preview): array
    {
        $allowed = ['ready', 'unchanged', 'established_skipped', 'inactive_skipped', 'missing_city_email'];
        if ($preview['fill_missing'] ?? false) {
            $allowed = [...$allowed, 'ready_binding', 'source_review_required', 'employee_not_found', 'no_login_account',
                'existing_email_preserved', 'upstream_identity_skipped', 'not_pending_onboarding', 'binding_review_required'];
        }

        return array_values(array_diff(array_keys($preview['statuses']), $allowed));
    }

    /** @return list<array{employee_id:string,city_email:string,match_status:string|null}> */
    private function parse(string $source, bool $fillMissing): array
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
            $matchIndex = array_search('Email Match Status', $header, true);
            if ($employeeIdIndex === false || $emailIndex === false || count(array_unique($header)) !== count($header)) {
                throw new InvalidArgumentException('The roster CSV requires unique Employee ID and Email Address columns.');
            }
            if ($fillMissing && $matchIndex === false) {
                throw new InvalidArgumentException('Missing-email repairs require the source Email Match Status column.');
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
                    'employee_id' => EmployeeIdentifier::normalize((string) $values[$employeeIdIndex]),
                    'city_email' => strtolower(trim((string) $values[$emailIndex])),
                    'match_status' => $matchIndex === false ? null : trim((string) $values[$matchIndex]),
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
    private function assessMissing(string $employeeId, string $cityEmail, ?string $status): array
    {
        $employee = null;
        $user = null;
        if ($status === null) {
            $employees = Employee::query()->where('employee_id', $employeeId)->limit(2)->get();
            $employee = $employees->first();
            $status = match (true) {
                $employees->isEmpty() => 'employee_not_found',
                $employees->count() > 1 => 'duplicate_database_employee_id',
                $employee->roster_status !== 'active' => 'inactive_skipped',
                default => null,
            };
        }
        if ($status === null && $employee instanceof Employee) {
            $users = User::query()->where('employee_profile_id', $employee->id)->limit(2)->get();
            $identified = User::query()->where('employee_id', $employeeId)->limit(2)->pluck('id');
            $user = $users->first();
            $status = match (true) {
                $users->isEmpty() && $identified->isEmpty() => 'no_login_account',
                $users->count() !== 1 || $identified->count() !== 1
                    || $identified->first() !== $user?->id || $user->employee_id !== $employeeId => 'canonical_identity_conflict',
                ! in_array($user->getRawOriginal('account_status'), [AccountStatus::Active->value, AccountStatus::PendingActivation->value], true) => 'not_pending_onboarding',
                $user->identityLinks()->exists() => 'upstream_identity_skipped',
                default => null,
            };
        }
        if ($status === null && $user instanceof User && $employee instanceof Employee) {
            $synthetic = preg_match('/\A[^@\s]+@canonical\.mbfdhub\.invalid\z/', $user->email) === 1;
            $binding = MemberOnboardingRosterBinding::query()->where('employee_profile_id', $employee->id)->first();
            $employeeEmail = trim((string) $employee->city_email);
            $needsBinding = ($user->getRawOriginal('account_status') === AccountStatus::Active->value
                    && $user->bootstrap_onboarding_completed_at !== null && $user->email_verified_at === null)
                || ($user->getRawOriginal('account_status') === AccountStatus::PendingActivation->value
                    && $user->bootstrap_onboarding_eligible && $user->bootstrap_onboarding_completed_at === null);
            if ($employeeEmail !== '') {
                $status = match (true) {
                    $employeeEmail !== $cityEmail || $user->email !== $cityEmail => 'existing_email_preserved',
                    $binding !== null && ($binding->employee_id !== $employeeId || $binding->city_email !== $cityEmail) => 'existing_roster_binding_conflict',
                    $binding === null && $needsBinding => 'ready_binding',
                    default => 'existing_email_preserved',
                };
            } else {
                $status = match (true) {
                    ! $synthetic && $user->email !== $cityEmail => 'existing_user_email_conflict',
                    $synthetic && $user->email_verified_at !== null => 'verified_synthetic_email_conflict',
                    $binding !== null && ($binding->employee_id !== $employeeId || $binding->city_email !== $cityEmail) => 'existing_roster_binding_conflict',
                    default => 'ready',
                };
            }
            if (in_array($status, ['ready', 'ready_binding'], true)
                && (Employee::query()->whereKeyNot($employee->id)->whereRaw('LOWER(city_email) = ?', [$cityEmail])->exists()
                    || User::query()->whereKeyNot($user->id)->whereRaw('LOWER(email) = ?', [$cityEmail])->exists()
                    || MemberOnboardingRosterBinding::query()->where('employee_profile_id', '!=', $employee->id)->where('city_email', $cityEmail)->exists())) {
                $status = 'city_email_collision';
            }
        }

        return ['employee_id' => $employeeId, 'city_email' => $cityEmail, 'status' => $status ?? 'unknown',
            'employee_profile_id' => $employee?->id, 'user_id' => $user?->id];
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
