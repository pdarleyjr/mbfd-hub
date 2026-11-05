<?php

declare(strict_types=1);

namespace App\Services\Ems\Kiosk;

use App\Models\Ems\KioskDevice;
use App\Models\Ems\KioskWithdrawal;
use App\Support\HubTimezone;

/**
 * What Captain 5 and admins see of the supply room screen: recent withdrawals, the screens and their health
 * (docs/ems/supply-room-kiosk.md section 7). Read only.
 */
final class KioskReport
{
    public function __construct(private readonly KioskDevices $devices, private readonly KioskSearchLearning $learning) {}

    /** @return array{withdrawals: list<array<string, mixed>>, screens: list<array<string, mixed>>, search_words_waiting: int} */
    public function overview(int $limit = 100): array
    {
        return [
            'withdrawals' => $this->withdrawals($limit),
            'screens' => $this->screens(),
            'search_words_waiting' => count($this->learning->suggestions()),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function withdrawals(int $limit = 100): array
    {
        return KioskWithdrawal::query()->with('lines')->orderByDesc('taken_at')->orderByDesc('id')->limit(max(1, min(500, $limit)))->get()
            ->map(fn (KioskWithdrawal $w): array => KioskNotifier::summary($w) + [
                'uuid' => $w->uuid,
                'taken_at_iso' => $w->taken_at->copy()->setTimezone(HubTimezone::NAME)->toIso8601String(),
                'count_due' => $w->lines->contains(fn ($line): bool => $line->shelf_showed_empty),
            ])->values()->all();
    }

    /** @return list<array{id: int, name: string, state: string, state_label: string, last_seen: ?string, paired_at: ?string, paired_by: string}> */
    public function screens(): array
    {
        $format = fn ($at): ?string => $at?->copy()->setTimezone(HubTimezone::NAME)->format('M j, g:i A');

        return $this->devices->all()->map(function (KioskDevice $device) use ($format): array {
            $state = match (true) {
                $device->revoked_at !== null => 'revoked',
                $device->paired_at === null => 'waiting',
                $device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinutes(KioskNotifier::OFFLINE_MINUTES)) => 'offline',
                default => 'online',
            };

            return [
                'id' => $device->id, 'name' => $device->name, 'state' => $state,
                'state_label' => ['revoked' => 'Revoked', 'waiting' => 'Waiting to be paired', 'offline' => 'Offline', 'online' => 'Online'][$state],
                'last_seen' => $format($device->last_seen_at), 'paired_at' => $format($device->paired_at), 'paired_by' => $device->created_by_name,
            ];
        })->values()->all();
    }
}
