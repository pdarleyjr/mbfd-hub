<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\MemberActivationVerifier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Loads owner-approved date-of-birth and phone records as keyed digests for
 * members awaiting first-time activation. Plaintext values exist only in
 * memory during the run and are never logged, reported or stored.
 */
final class MemberActivationVerifierImporter
{
    private const DOB_HEADERS = ['Date of Birth', 'DOB', 'Birth Date', 'Birthdate'];

    private const PHONE_HEADERS = ['Phone', 'Phone Number', 'Personal Phone', 'Cell Phone', 'Mobile Phone', 'Mobile'];

    public function __construct(
        private readonly MemberActivationVerifierDigest $digests,
        private readonly MemberSelfServiceActivation $activation,
    ) {}

    /** @return array{source_sha256:string,records:list<array{employee_id:string,status:string,employee_profile_id:int|null,dob:CarbonImmutable|null,phone:string|null}>,statuses:array<string,int>} */
    public function preview(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The activation records CSV is missing or unreadable.');
        }
        $source = file_get_contents($path);
        if ($source === false) {
            throw new InvalidArgumentException('The activation records CSV could not be read.');
        }
        $rows = $this->parse((string) preg_replace('/^\xEF\xBB\xBF/', '', $source));
        $ids = array_count_values(array_column($rows, 'employee_id'));
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $records = [];
        foreach ($rows as $row) {
            $dob = $this->parseDate($row['dob'], $today);
            $phone = $this->digests->normalizePhone($row['phone']);
            $status = $row['employee_id'] === '' ? 'missing_employee_id' : null;
            $status ??= $ids[$row['employee_id']] > 1 ? 'duplicate_roster_employee_id' : null;
            $status ??= trim($row['dob']) === '' ? 'missing_date_of_birth' : null;
            $status ??= $dob === null ? 'invalid_date_of_birth' : null;
            $status ??= trim($row['phone']) === '' ? 'missing_phone' : null;
            $status ??= $phone === null ? 'invalid_phone' : null;
            $assessed = $status === null ? $this->assess($row['employee_id'], $dob, $phone) : ['status' => $status, 'employee_profile_id' => null];
            $records[] = [
                'employee_id' => $row['employee_id'],
                'status' => $assessed['status'],
                'employee_profile_id' => $assessed['employee_profile_id'],
                'dob' => $dob,
                'phone' => $phone,
            ];
        }

        return [
            'source_sha256' => hash('sha256', $source),
            'records' => $records,
            'statuses' => collect($records)->countBy('status')->sortKeys()->all(),
        ];
    }

    /**
     * Stores digests for the rows that are ready. Every other row leaves its
     * member ineligible for self-service activation rather than guessing.
     *
     * @param  array{source_sha256:string,records:list<array{employee_id:string,status:string,employee_profile_id:int|null,dob:CarbonImmutable|null,phone:string|null}>,statuses:array<string,int>}  $preview
     * @return array{stored:int,unchanged:int}
     */
    public function apply(array $preview): array
    {
        $result = DB::transaction(function () use ($preview): array {
            $stored = 0;
            $unchanged = 0;
            $at = CarbonImmutable::now();
            foreach ($preview['records'] as $record) {
                if (! in_array($record['status'], ['ready', 'unchanged'], true)) {
                    continue;
                }
                // Serialize with a member claiming the same account right now.
                User::query()->where('employee_profile_id', $record['employee_profile_id'])->lockForUpdate()->get(['id']);
                $current = $this->assess($record['employee_id'], $record['dob'], $record['phone']);
                if ($current['employee_profile_id'] !== $record['employee_profile_id']) {
                    throw new RuntimeException('A personnel identity changed after preview; no activation records were stored.');
                }
                if (! in_array($current['status'], ['ready', 'unchanged'], true)) {
                    // The member activated or changed state since the preview: leave them alone.
                    continue;
                }
                if ($current['status'] === 'unchanged') {
                    $unchanged++;

                    continue;
                }
                assert($record['dob'] instanceof CarbonImmutable && is_string($record['phone']) && is_int($record['employee_profile_id']));
                MemberActivationVerifier::query()->updateOrCreate(
                    ['employee_profile_id' => $record['employee_profile_id']],
                    [
                        'employee_id' => $record['employee_id'],
                        'dob_digest' => $this->digests->dateOfBirth($record['employee_profile_id'], $record['dob']),
                        'phone_digest' => $this->digests->phone($record['employee_profile_id'], $record['phone']),
                        'source_sha256' => $preview['source_sha256'],
                        'approved_at' => $at,
                        // A fresh owner approval is the documented way to clear a lockout.
                        'failed_attempts' => 0,
                        'last_failed_at' => null,
                        'locked_at' => null,
                        'consumed_at' => null,
                    ],
                );
                $stored++;
            }

            return compact('stored', 'unchanged');
        }, 3);

        Log::notice('member_activation_verifiers_imported', ['source_sha256' => $preview['source_sha256']] + $result);

        return $result;
    }

    /** @return list<array{employee_id:string,dob:string,phone:string}> */
    private function parse(string $source): array
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('The activation records CSV could not be parsed.');
        }
        fwrite($stream, $source);
        rewind($stream);
        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            if (! is_array($header)) {
                throw new InvalidArgumentException('The activation records CSV is empty.');
            }
            $header = array_map(static fn (mixed $value): string => trim((string) $value), $header);
            $idIndex = array_search('Employee ID', $header, true);
            $dobIndex = $this->soleColumn($header, self::DOB_HEADERS);
            $phoneIndex = $this->soleColumn($header, self::PHONE_HEADERS);
            if ($idIndex === false || $dobIndex === null || $phoneIndex === null || count(array_unique($header)) !== count($header)) {
                throw new InvalidArgumentException('The CSV requires unique Employee ID, Date of Birth and Phone columns.');
            }
            $rows = [];
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw new InvalidArgumentException('An activation records CSV row has the wrong number of columns.');
                }
                $rows[] = [
                    'employee_id' => trim((string) $values[$idIndex]),
                    'dob' => trim((string) $values[$dobIndex]),
                    'phone' => trim((string) $values[$phoneIndex]),
                ];
            }
            if ($rows === []) {
                throw new InvalidArgumentException('The activation records CSV has no member rows.');
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $names
     */
    private function soleColumn(array $header, array $names): ?int
    {
        $matches = array_keys(array_filter($header, static fn (string $column): bool => in_array($column, $names, true)));

        return count($matches) === 1 ? (int) $matches[0] : null;
    }

    /** Accepts only unambiguous ISO (YYYY-MM-DD) or US (M/D/YYYY) dates. */
    private function parseDate(string $value, CarbonImmutable $today): ?CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/\A(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]00:00(?::00)?)?\z/', $value, $m) === 1) {
            return $this->digests->parseDateOfBirth((int) $m[1], (int) $m[2], (int) $m[3], $today);
        }
        if (preg_match('#\A(\d{1,2})/(\d{1,2})/(\d{4})\z#', $value, $m) === 1) {
            return $this->digests->parseDateOfBirth((int) $m[3], (int) $m[1], (int) $m[2], $today);
        }

        return null;
    }

    /** @return array{status:string,employee_profile_id:int|null} */
    private function assess(string $employeeId, ?CarbonImmutable $dob, ?string $phone): array
    {
        $employees = Employee::query()->where('employee_id', $employeeId)->limit(2)->get();
        if ($employees->count() !== 1) {
            return ['status' => $employees->isEmpty() ? 'employee_not_found' : 'duplicate_database_employee_id', 'employee_profile_id' => null];
        }
        $employee = $employees->first();
        $result = static fn (string $status): array => ['status' => $status, 'employee_profile_id' => $employee->id];
        if ($employee->roster_status !== 'active') {
            return $result('inactive_skipped');
        }
        $users = User::query()->where('employee_profile_id', $employee->id)->limit(2)->get();
        $identifierUsers = User::query()->where('employee_id', $employeeId)->limit(2)->get();
        if ($users->count() !== 1 || $identifierUsers->count() !== 1 || ! $users->first()->is($identifierUsers->first())) {
            return $result('canonical_identity_conflict');
        }
        $user = $users->first();
        if ($user->getRawOriginal('account_status') === AccountStatus::Active->value) {
            return $result('established_skipped');
        }
        if ($this->activation->hasElevatedAccess($user)) {
            return $result('privileged_skipped');
        }
        if (! $this->activation->eligible($user, $employee)) {
            return $result('not_pending_onboarding');
        }
        $verifier = MemberActivationVerifier::query()->where('employee_profile_id', $employee->id)->first();
        $unchanged = $verifier instanceof MemberActivationVerifier && $verifier->isUsable()
            && $verifier->employee_id === $employeeId && $dob !== null && $phone !== null
            && hash_equals((string) $verifier->dob_digest, $this->digests->dateOfBirth($employee->id, $dob))
            && hash_equals((string) $verifier->phone_digest, $this->digests->phone($employee->id, $phone));

        return $result($unchanged ? 'unchanged' : 'ready');
    }
}
