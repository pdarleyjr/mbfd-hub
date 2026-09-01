<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\StationRequestSubmissionResult;
use App\Models\Room;
use App\Models\StationRequest;
use App\Services\Identity\AuthenticatedActor;
use App\Support\Security\Base64Image;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class StationRequestSubmissionService
{
    public function __construct(private readonly StationRequestSideEffectService $sideEffects) {}

    /** @param array<string, mixed> $data */
    public function submit(array $data, AuthenticatedActor $actor): StationRequestSubmissionResult
    {
        $employee = $actor->requireEmployee();
        $existing = StationRequest::query()
            ->where('client_submission_id', $data['client_submission_id'])
            ->first();
        if ($existing !== null) {
            $this->assertReplayOwner($existing, $actor);

            return new StationRequestSubmissionResult($this->load($existing), false);
        }

        $storedPaths = [];
        try {
            $prepared = $this->prepareImages($data, $storedPaths);
            $result = DB::transaction(function () use ($prepared, $actor, $employee): StationRequestSubmissionResult {
                $existing = StationRequest::query()
                    ->where('client_submission_id', $prepared['client_submission_id'])
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $this->assertReplayOwner($existing, $actor);

                    return new StationRequestSubmissionResult($this->load($existing), false);
                }

                $room = isset($prepared['room_id'])
                    ? Room::query()
                        ->whereKey($prepared['room_id'])
                        ->where('station_id', $prepared['station_id'])
                        ->first()
                    : null;
                $metadata = array_merge((array) ($prepared['_metadata'] ?? []), [
                    'source' => $prepared['_source'] ?? 'daily_station_request',
                    'submitted_at' => $prepared['submitted_at'] ?? now()->toIso8601String(),
                ]);
                if (isset($prepared['member_signature_path']) || isset($prepared['officer_signature_path'])) {
                    $metadata['signatures'] = array_filter([
                        'member' => $prepared['member_signature_path'] ?? null,
                        'officer' => $prepared['officer_signature_path'] ?? null,
                    ]);
                }

                $request = StationRequest::query()->create([
                    'client_submission_id' => $prepared['client_submission_id'],
                    'station_id' => $prepared['station_id'],
                    'room_id' => $room?->id,
                    'room_name_snapshot' => $room?->name
                        ?: (filled($prepared['room_name_snapshot'] ?? null)
                            ? trim((string) $prepared['room_name_snapshot'])
                            : null),
                    'requested_by_employee_id' => $employee->id,
                    'actor_user_id' => $actor->userId(),
                    'requester_name_snapshot' => $employee->name,
                    'request_type' => $prepared['request_type'],
                    'subject_type' => $prepared['subject_type'],
                    'title' => $prepared['title'],
                    'description' => $prepared['description'],
                    'priority' => $prepared['priority'],
                    'status' => 'pending',
                    'metadata' => $metadata,
                ]);

                $request->items()->createMany(collect($prepared['items'])->map(fn (array $item): array => [
                    'room_asset_id' => $item['room_asset_id'] ?? null,
                    'item_name' => $item['item_name'],
                    'category' => $item['category'] ?? null,
                    'quantity' => $item['quantity'],
                    'reason' => $item['reason'] ?? null,
                    'requested_action' => $item['requested_action'] ?? null,
                    'condition' => $item['condition'] ?? null,
                    'serial_number' => $item['serial_number'] ?? null,
                    'manufacturer' => $item['manufacturer'] ?? null,
                    'model_number' => $item['model_number'] ?? null,
                    'pd_case_number' => $item['pd_case_number'] ?? null,
                    'photo_path' => $item['photo_path'] ?? null,
                ])->all());
                $request->updates()->create([
                    'status' => 'pending',
                    'public_note' => 'Request submitted.',
                    'metadata' => ['event' => 'submitted'],
                ]);

                DB::afterCommit(fn () => $this->sideEffects->requestCreated($request));

                return new StationRequestSubmissionResult($this->load($request), true);
            }, 3);

            if (! $result->created) {
                $this->deleteStoredPaths($storedPaths);
            }

            return $result;
        } catch (QueryException $exception) {
            $existing = StationRequest::query()
                ->where('client_submission_id', $data['client_submission_id'])
                ->first();
            if ($existing !== null) {
                $this->assertReplayOwner($existing, $actor);
                $this->deleteStoredPaths($storedPaths);

                return new StationRequestSubmissionResult($this->load($existing), false);
            }

            $this->deleteStoredPaths($storedPaths);
            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteStoredPaths($storedPaths);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data @param list<string> $storedPaths @return array<string, mixed> */
    private function prepareImages(array $data, array &$storedPaths): array
    {
        foreach ($data['items'] as $index => &$item) {
            if (filled($item['photo'] ?? null)) {
                $item['photo_path'] = $this->storeImage(
                    $item['photo'],
                    'station-requests/photos',
                    "item-{$index}",
                    "items.{$index}.photo",
                );
                $storedPaths[] = $item['photo_path'];
            }
            unset($item['photo']);
        }
        unset($item);

        if ($data['request_type'] === 'equipment' && filled($data['member_signature'] ?? null)) {
            $data['member_signature_path'] = $this->storeImage(
                $data['member_signature'],
                'station-requests/signatures',
                'member-signature',
                'member_signature',
            );
            $storedPaths[] = $data['member_signature_path'];
        }
        if ($data['request_type'] === 'equipment' && filled($data['officer_signature'] ?? null)) {
            $data['officer_signature_path'] = $this->storeImage(
                $data['officer_signature'],
                'station-requests/signatures',
                'officer-signature',
                'officer_signature',
            );
            $storedPaths[] = $data['officer_signature_path'];
        }
        unset($data['member_signature'], $data['officer_signature']);

        return $data;
    }

    private function storeImage(string $payload, string $directory, string $prefix, string $field): string
    {
        $path = Base64Image::store($payload, $directory, $prefix);
        if ($path === null) {
            throw ValidationException::withMessages([$field => 'The uploaded image must be a valid JPEG, PNG, WebP, or GIF image.']);
        }

        return $path;
    }

    /** @param list<string> $paths */
    private function deleteStoredPaths(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function load(StationRequest $request): StationRequest
    {
        return $request->fresh(['station:id,station_number', 'room:id,station_id,name', 'items.roomAsset:id,room_id,name', 'updates']) ?? $request;
    }

    private function assertReplayOwner(StationRequest $request, AuthenticatedActor $actor): void
    {
        if ((int) $request->actor_user_id === $actor->userId()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This queued submission belongs to a different authenticated account.',
            'code' => 'OFFLINE_QUEUE_OWNER_MISMATCH',
        ], 409));
    }
}
