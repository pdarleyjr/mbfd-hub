<?php

declare(strict_types=1);

namespace App\Services\HubSupport;

use App\Data\HubSupportTicketSubmissionResult;
use App\Enums\HubSupportTicketCategory;
use App\Enums\HubSupportTicketImpact;
use App\Models\HubSupportTicket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Throwable;

final class HubSupportTicketSubmissionService
{
    public function __construct(
        private readonly HubIssueSanitizer $sanitizer,
        private readonly HubSupportClassifier $classifier,
        private readonly HubSupportTicketSideEffectService $sideEffects,
    ) {}

    /** @param array<string, mixed> $input @param list<UploadedFile> $attachments */
    public function submit(User $reporter, array $input, array $attachments = []): HubSupportTicketSubmissionResult
    {
        $validated = validator($input, [
            'client_submission_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'min:3', 'max:10000'],
            'page_path' => ['nullable', 'string', 'max:4096'],
            'route_name' => ['nullable', 'string', 'max:255'],
            'referrer_path' => ['nullable', 'string', 'max:4096'],
            'client_metadata' => ['nullable', 'array'],
            'diagnostics' => ['nullable', 'array'],
        ])->validate();

        validator(['attachments' => $attachments], [
            'attachments' => ['array', 'max:2'],
            'attachments.*' => ['required', File::types(['png', 'jpg', 'jpeg', 'pdf'])->max('10mb')],
        ])->validate();

        $clientId = (string) $validated['client_submission_id'];
        if ($existing = $this->existing($clientId, $reporter)) {
            return new HubSupportTicketSubmissionResult($existing, false);
        }

        $stored = [];
        try {
            return DB::transaction(function () use ($reporter, $validated, $attachments, $clientId, &$stored): HubSupportTicketSubmissionResult {
                if ($existing = $this->existing($clientId, $reporter, true)) {
                    return new HubSupportTicketSubmissionResult($existing, false);
                }

                $description = $this->normalizeDescription($validated['description']);
                $path = $this->sanitizer->path($validated['page_path'] ?? null);
                $diagnostics = $this->sanitizer->sanitizeDiagnostics($validated['diagnostics'] ?? null);
                try {
                    $classification = $this->classifier->classify($description, $path, $diagnostics);
                } catch (Throwable) {
                    $classification = [
                        'category' => HubSupportTicketCategory::Other,
                        'impact' => HubSupportTicketImpact::Minor,
                        'affected_component' => 'unknown',
                        'generated_title' => mb_strimwidth($description, 0, 120, '…'),
                    ];
                }

                $employee = $reporter->employeeProfile;
                $ticket = HubSupportTicket::query()->create([
                    'client_submission_id' => $clientId,
                    'reported_by_user_id' => $reporter->id,
                    'reported_by_employee_id' => $employee?->id,
                    'reporter_name_snapshot' => $employee?->name ?: $reporter->name,
                    'reporter_employee_identifier_snapshot' => $employee?->employee_id,
                    'description' => $description,
                    'generated_title' => $classification['generated_title'],
                    'category' => $classification['category'],
                    'impact' => $classification['impact'],
                    'affected_component' => $classification['affected_component'],
                    'status' => 'new',
                    'page_path' => $path,
                    'route_name' => $this->routeName($validated['route_name'] ?? null),
                    'referrer_path' => $this->sanitizer->path($validated['referrer_path'] ?? null),
                    'client_metadata' => $this->sanitizer->sanitizeMetadata($validated['client_metadata'] ?? null),
                    'diagnostics' => $diagnostics,
                    'diagnostics_schema_version' => 1,
                    'application_commit' => $this->deployedCommit(),
                    'issue_fingerprint' => hash('sha256', implode('|', [
                        $classification['affected_component'], $path ?? '', $classification['category']->value,
                        $this->deployedCommit(),
                    ])),
                ]);
                $ticket->updates()->create([
                    'status' => 'new',
                    'changed_by_user_id' => $reporter->id,
                    'metadata' => ['event' => 'submitted'],
                ]);

                foreach ($attachments as $file) {
                    $this->storeAttachment($ticket, $reporter, $file, $stored);
                }

                DB::afterCommit(function () use ($ticket): void {
                    try {
                        $this->sideEffects->ticketCreated($ticket);
                    } catch (Throwable $exception) {
                        Log::error('Hub support report notification could not be queued', [
                            'ticket_id' => $ticket->id,
                            'exception' => $exception::class,
                        ]);
                    }
                });

                return new HubSupportTicketSubmissionResult($this->load($ticket), true);
            });
        } catch (QueryException $exception) {
            $this->removeStored($stored);
            if ($existing = $this->existing($clientId, $reporter)) {
                return new HubSupportTicketSubmissionResult($existing, false);
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->removeStored($stored);
            throw $exception;
        }
    }

    private function existing(string $clientId, User $reporter, bool $lock = false): ?HubSupportTicket
    {
        $query = HubSupportTicket::query()->where('client_submission_id', $clientId);
        $ticket = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($ticket && $ticket->reported_by_user_id !== $reporter->id) {
            throw ValidationException::withMessages(['client_submission_id' => 'This submission cannot be reused.']);
        }

        return $ticket ? $this->load($ticket) : null;
    }

    private function load(HubSupportTicket $ticket): HubSupportTicket
    {
        return $ticket->fresh(['updates', 'attachments']) ?? $ticket;
    }

    /** @param list<array{disk: string, path: string}> $stored */
    private function storeAttachment(HubSupportTicket $ticket, User $reporter, UploadedFile $file, array &$stored): void
    {
        $mime = $file->getMimeType();
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'application/pdf' => 'pdf',
            default => throw ValidationException::withMessages(['attachments' => 'Only PNG, JPEG, and PDF files are accepted.']),
        };
        $disk = (string) config('filesystems.private', 'local');
        $publicId = (string) Str::ulid();
        $generated = $publicId.'.'.$extension;
        $path = 'hub-support/attachments/'.now()->format('Y/m').'/'.$generated;
        $bytes = $file->getContent();
        if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
            throw ValidationException::withMessages(['attachments' => 'We could not store the file. Please try again.']);
        }
        $stored[] = ['disk' => $disk, 'path' => $path];

        $ticket->attachments()->create([
            'public_id' => $publicId,
            'disk' => $disk,
            'storage_path' => $path,
            'generated_filename' => $generated,
            'original_filename' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
            'mime_type' => $mime,
            'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'uploaded_by_user_id' => $reporter->id,
        ]);
    }

    /** @param list<array{disk: string, path: string}> $stored */
    private function removeStored(array $stored): void
    {
        foreach ($stored as $object) {
            Storage::disk($object['disk'])->delete($object['path']);
        }
    }

    private function routeName(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9._-]{1,255}$/', $value) === 1 ? $value : null;
    }

    private function normalizeDescription(string $description): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $description);
        $normalized = preg_replace('/[^\S\r\n]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function deployedCommit(): ?string
    {
        $marker = public_path('deploy-marker.json');
        if (is_file($marker)) {
            $data = json_decode((string) file_get_contents($marker), true);
            if (is_array($data) && is_string($data['sha'] ?? null) && preg_match('/^[a-f0-9]{40}$/i', $data['sha'])) {
                return strtolower($data['sha']);
            }
        }
        $source = base_path('.git-sha');
        $sha = is_file($source) ? trim((string) file_get_contents($source)) : null;

        return is_string($sha) && preg_match('/^[a-f0-9]{40}$/i', $sha) ? strtolower($sha) : null;
    }
}
