<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingPaperAttendance;
use App\Models\Training\TrainingPaperReviewAudit;
use App\Models\Training\TrainingPaperRosterImport;
use App\Models\Training\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;

final class TrainingPaperRosterService
{
    public const VERSION = 'paper-v1';

    public function authorize(User $actor, bool $write = true): void
    {
        $fresh = User::query()->findOrFail($actor->id);
        abort_unless($fresh->isAuthenticationAllowed() && $fresh->hasAnyRole($write
            ? ['super_admin', 'training_admin'] : ['super_admin', 'training_admin', 'training_viewer']), 403);
        abort_unless($fresh->security_version === $actor->security_version, 403);
    }

    /** No writes, including files, occur unless apply is explicitly true. */
    public function importManifest(array $manifest, User $reviewer, bool $apply = false): array
    {
        $this->authorize($reviewer);
        Validator::make($manifest, [
            'schema_version' => ['required', 'integer', Rule::in([1])],
            'archive_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'reviewer_id' => ['required', 'integer', Rule::in([$reviewer->id])],
            'documents' => ['required', 'array', 'min:1', 'max:20'],
            'documents.*.course_id' => ['required', 'integer', 'min:1'],
            'documents.*.module_id' => ['required', 'integer', 'min:1'],
            'documents.*.held_on' => ['required', 'date_format:Y-m-d'],
            'documents.*.source_filename' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\/\x00-\x1f]+\.pdf$/i'],
            'documents.*.source_path' => ['required', 'string', 'max:4096'],
            'documents.*.source_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/', 'distinct'],
            'documents.*.page_count' => ['required', 'integer', 'min:1', 'max:100'],
            'documents.*.rows' => ['required', 'array', 'min:1', 'max:2000'],
            'documents.*.rows.*.page' => ['required', 'integer', 'min:1'],
            'documents.*.rows.*.row' => ['required', 'string', 'max:80'],
            'documents.*.rows.*.raw_transcription' => ['present', 'string', 'max:4000'],
            'documents.*.rows.*.evidence_present' => ['required', 'boolean'],
            'documents.*.rows.*.review_status' => ['required', Rule::in(['pending', 'approved', 'excluded'])],
            'documents.*.rows.*.employee_profile_id' => ['nullable', 'integer', 'min:1'],
            'documents.*.rows.*.identity_confirmed' => ['required', 'boolean'],
            'documents.*.rows.*.review_reason' => ['required', 'string', 'max:4000'],
        ])->validate();
        foreach ($manifest['documents'] as &$document) {
            foreach (['course_id', 'module_id', 'page_count'] as $field) {
                $document[$field] = (int) $document[$field];
            }
            foreach ($document['rows'] as &$row) {
                $row['page'] = (int) $row['page'];
                $row['employee_profile_id'] = isset($row['employee_profile_id']) ? (int) $row['employee_profile_id'] : null;
                $row['evidence_present'] = (bool) $row['evidence_present'];
                $row['identity_confirmed'] = (bool) $row['identity_confirmed'];
            }
            unset($row);
        }
        unset($document);
        $digest = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($manifest, $reviewer, $apply, $digest): array {
            $this->authorize($reviewer);
            $result = ['apply' => $apply, 'created' => 0, 'replayed' => 0, 'approved' => 0, 'pending' => 0, 'excluded' => 0, 'import_ids' => []];
            foreach ($manifest['documents'] as $document) {
                $course = TrainingCourse::query()->lockForUpdate()->findOrFail($document['course_id']);
                $module = DB::table('training_modules')->where('id', $document['module_id'])->where('training_course_id', $course->id)->first();
                $this->check($module !== null, 'The module does not belong to the confirmed course.');
                $bytes = $this->originalBytes($document);
                $existing = TrainingPaperRosterImport::query()->where('source_sha256', $document['source_sha256'])->first();
                if ($existing !== null) {
                    $session = $existing->session;
                    $this->check(hash_equals($existing->manifest_sha256, $digest)
                        && (int) $session->training_course_id === (int) $course->id
                        && (int) $session->training_module_id === (int) $document['module_id']
                        && $session->held_on->toDateString() === $document['held_on'], 'This source was already imported differently. Use audited review.');
                    $this->sourceBytes($existing, $reviewer);
                    $result['replayed']++;
                    $result['import_ids'][] = $existing->id;

                    continue;
                }
                $locations = [];
                $employees = [];
                foreach ($document['rows'] as $row) {
                    $key = $row['page'].':'.$row['row'];
                    $this->check($row['page'] <= $document['page_count'] && ! isset($locations[$key]), 'Invalid or duplicate source location.');
                    $locations[$key] = true;
                    $this->validateDecision($row['review_status'], $row['employee_profile_id'] ?? null, $row['evidence_present'], $row['identity_confirmed']);
                    if ($row['review_status'] === 'approved') {
                        $this->check(! isset($employees[$row['employee_profile_id']]), 'Repeated employee evidence must be retained as an excluded duplicate.');
                        $employees[$row['employee_profile_id']] = true;
                    }
                    $result[$row['review_status']]++;
                }
                $result['created']++;
                if (! $apply) {
                    continue;
                }
                $disk = (string) config('filesystems.private', 'local');
                $this->check(in_array($disk, ['local', 'r2'], true) && config('filesystems.disks.'.$disk.'.visibility') !== 'public'
                    && ! config('filesystems.disks.'.$disk.'.url'), 'Paper evidence requires a private disk without a public URL.');
                $path = 'training-paper/originals/'.$document['source_sha256'].'.pdf';
                $storage = Storage::disk($disk);
                if ($storage->exists($path)) {
                    $this->check(hash_equals($document['source_sha256'], hash('sha256', $storage->get($path))), 'Archived source integrity failed.');
                } else {
                    $this->check($storage->put($path, $bytes, ['visibility' => 'private']), 'The private original could not be stored.');
                }
                $this->check(hash_equals($document['source_sha256'], hash('sha256', $storage->get($path))), 'Archived source integrity failed.');
                $session = TrainingSession::query()->create([
                    'public_id' => (string) Str::ulid(), 'training_course_id' => $course->id,
                    'training_module_id' => $document['module_id'], 'course_name' => $course->name,
                    'classifications' => $course->classifications, 'held_on' => $document['held_on'],
                    'starts_at' => null, 'location' => null, 'status' => 'paper', 'attendance_source' => 'paper',
                    'revision' => 1, 'created_by' => $reviewer->id,
                ]);
                $import = TrainingPaperRosterImport::query()->create([
                    'training_session_id' => $session->id, 'source_sha256' => $document['source_sha256'],
                    'archive_sha256' => $manifest['archive_sha256'], 'manifest_sha256' => $digest,
                    'source_filename' => $document['source_filename'], 'disk' => $disk, 'path' => $path,
                    'page_count' => $document['page_count'], 'importer_version' => self::VERSION,
                    'imported_by' => $reviewer->id, 'imported_at' => now(),
                ]);
                foreach ($document['rows'] as $row) {
                    $entry = TrainingPaperAttendance::query()->create([
                        'training_paper_roster_import_id' => $import->id, 'training_session_id' => $session->id,
                        'employee_profile_id' => $row['review_status'] === 'approved' ? $row['employee_profile_id'] : null,
                        'source_page' => $row['page'], 'source_row' => $row['row'], 'raw_transcription' => $row['raw_transcription'],
                        'evidence_present' => $row['evidence_present'], 'review_status' => $row['review_status'],
                        'reviewed_by' => $row['review_status'] === 'pending' ? null : $reviewer->id,
                        'reviewed_at' => $row['review_status'] === 'pending' ? null : now(), 'reason' => $row['review_reason'],
                    ]);
                    $this->audit($entry, $reviewer, null, $row['review_reason']);
                }
                $result['import_ids'][] = $import->id;
            }

            return $result;
        }, 3);
    }

    public function review(TrainingPaperAttendance $entry, User $actor, string $decision, ?int $employeeProfileId, string $reason, int $expectedVersion): TrainingPaperAttendance
    {
        $this->authorize($actor);
        Validator::make(['decision' => $decision, 'reason' => $reason], [
            'decision' => ['required', Rule::in(['approved', 'excluded', 'voided'])], 'reason' => ['required', 'string', 'max:4000'],
        ])->validate();

        return DB::transaction(function () use ($entry, $actor, $decision, $employeeProfileId, $reason, $expectedVersion): TrainingPaperAttendance {
            TrainingSession::query()->whereKey($entry->training_session_id)->lockForUpdate()->firstOrFail();
            $entry = TrainingPaperAttendance::query()->lockForUpdate()->findOrFail($entry->id);
            $this->check($entry->review_version === $expectedVersion, 'This entry was reviewed elsewhere. Reload it before deciding.');
            $this->authorize($actor);
            $this->sourceBytes($entry->sourceImport, $actor);
            $this->validateDecision($decision, $employeeProfileId, $entry->evidence_present, true);
            if ($decision === 'approved') {
                $this->check(! TrainingPaperAttendance::query()->where('training_session_id', $entry->training_session_id)
                    ->where('employee_profile_id', $employeeProfileId)->whereKeyNot($entry->id)->exists(), 'That employee already has a paper entry for this session.');
            }
            $before = $entry->only(['review_status', 'review_version', 'employee_profile_id', 'reviewed_by', 'reviewed_at', 'reason']);
            $entry->update(['review_status' => $decision, 'review_version' => $entry->review_version + 1, 'employee_profile_id' => $decision === 'approved' ? $employeeProfileId : null,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'reason' => $reason]);
            $this->audit($entry, $actor, $before, $reason);

            return $entry;
        });
    }

    public function sourceBytes(TrainingPaperRosterImport $import, User $actor): string
    {
        $this->authorize($actor, false);
        $this->check(in_array($import->disk, ['local', 'r2'], true)
            && $import->path === 'training-paper/originals/'.$import->source_sha256.'.pdf', 'Invalid original document location.');
        $bytes = Storage::disk($import->disk)->get($import->path);
        $this->check(is_string($bytes) && hash_equals($import->source_sha256, hash('sha256', $bytes)), 'The original paper document could not be verified.');

        return $bytes;
    }

    private function originalBytes(array $document): string
    {
        $path = realpath($document['source_path']);
        $this->check($path !== false && is_file($path) && ! is_link($document['source_path']) && filesize($path) <= 30 * 1024 * 1024
            && basename($path) === $document['source_filename'], 'The original PDF path or size is invalid.');
        $bytes = file_get_contents($path);
        $this->check(is_string($bytes) && str_starts_with($bytes, '%PDF-')
            && hash_equals($document['source_sha256'], hash('sha256', $bytes)), 'The original PDF hash does not match.');
        try {
            $pages = count((new Parser)->parseContent($bytes)->getPages());
        } catch (\Throwable) {
            $this->check(false, 'The original PDF could not be parsed.');
        }
        $this->check($pages === $document['page_count'], 'The original PDF page count does not match.');

        return $bytes;
    }

    private function validateDecision(string $status, ?int $employeeId, bool $evidence, bool $confirmed): void
    {
        if ($status !== 'approved') {
            $this->check($employeeId === null, 'Unresolved or excluded evidence cannot bind an employee.');

            return;
        }
        $this->check($evidence && $confirmed && $employeeId !== null && Employee::query()->whereKey($employeeId)->exists(),
            'Approval requires original sign-in evidence and a reviewer-confirmed canonical employee.');
    }

    private function audit(TrainingPaperAttendance $entry, User $actor, ?array $before, string $reason): void
    {
        TrainingPaperReviewAudit::query()->create(['training_paper_attendance_id' => $entry->id,
            'actor_user_id' => $actor->id, 'before' => $before,
            'after' => $entry->only(['review_status', 'review_version', 'employee_profile_id', 'reviewed_by', 'reviewed_at', 'reason']),
            'reason' => $reason, 'created_at' => now()]);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['paper' => $message]);
        }
    }
}
