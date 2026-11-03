<?php

declare(strict_types=1);

namespace App\Services\Ems\Kiosk;

use App\Models\Ems\KioskDevice;
use App\Models\Ems\KioskWithdrawal;
use App\Models\Ems\KioskWithdrawalLine;
use App\Models\User;
use App\Notifications\Ems\KioskScreenOfflineNotification;
use App\Notifications\Ems\KioskWithdrawalDailySummary;
use App\Notifications\Ems\KioskWithdrawalNotice;
use App\Notifications\Ems\KioskWithdrawalReceipt;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDispatchService;
use App\Support\HubTimezone;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies of a supply room screen withdrawal (docs/ems/supply-room-kiosk.md sections 4 and 7): the member's receipt,
 * an in-app notice to the EMS supply alert list, the daily email summary and the "screen offline" alert. A failure
 * here never undoes a withdrawal; it is logged.
 */
final class KioskNotifier
{
    public const OFFLINE_MINUTES = 15;

    public function __construct(private readonly NotificationDispatchService $notifications) {}

    public function taken(int $withdrawalId): void
    {
        $withdrawal = KioskWithdrawal::query()->with(['lines', 'employee'])->find($withdrawalId);
        // Undone within its minute: nothing was taken, so nobody is told it was.
        if ($withdrawal === null || $withdrawal->status !== KioskWithdrawal::TAKEN) {
            return;
        }
        $summary = self::summary($withdrawal);

        try {
            $receipt = new KioskWithdrawalReceipt($summary, 'kiosk_receipt:w'.$withdrawal->id);
            $user = $withdrawal->user_id !== null ? User::query()->find($withdrawal->user_id) : null;
            if ($user !== null) {
                $this->notifications->dispatch(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, $receipt, [$user], 'kiosk_receipt:w'.$withdrawal->id);
            } elseif (filled($withdrawal->employee?->city_email)) {
                // No Hub account: the receipt goes straight to the city email on the roster record.
                $withdrawal->employee->notify($receipt);
            }
        } catch (Throwable $exception) {
            Log::error('Supply room screen receipt could not be sent', ['withdrawal_id' => $withdrawal->id, 'exception' => $exception->getMessage()]);
        }

        try {
            $this->notifications->dispatch(NotificationCategoryRegistry::EMS_SUPPLY_WITHDRAWALS, new KioskWithdrawalNotice($summary, 'kiosk_withdrawal:w'.$withdrawal->id), eventKey: 'kiosk_withdrawal:w'.$withdrawal->id);
        } catch (Throwable $exception) {
            Log::error('Supply room screen withdrawal notice could not be sent', ['withdrawal_id' => $withdrawal->id, 'exception' => $exception->getMessage()]);
        }
    }

    /** Email the alert list every withdrawal of the past 24 hours. Returns how many withdrawals it covered. */
    public function dailySummary(): int
    {
        $withdrawals = KioskWithdrawal::query()->with('lines')->where('taken_at', '>=', now()->subDay())->orderBy('taken_at')->orderBy('id')->get();
        if ($withdrawals->isEmpty()) {
            return 0;
        }
        $rows = $withdrawals->map(fn (KioskWithdrawal $w): array => self::summary($w))->values()->all();
        $day = now(HubTimezone::NAME)->toDateString();
        $this->notifications->dispatch(NotificationCategoryRegistry::EMS_SUPPLY_WITHDRAWALS, new KioskWithdrawalDailySummary($rows, $day, 'kiosk_daily:'.$day), eventKey: 'kiosk_daily:'.$day);

        return $withdrawals->count();
    }

    /** Alert the alert list once about each paired screen silent for 15 minutes. Returns how many were alerted. */
    public function watch(): int
    {
        $silent = KioskDevice::query()->whereNotNull('paired_at')->whereNull('revoked_at')->whereNull('offline_alerted_at')
            ->where('last_seen_at', '<', now()->subMinutes(self::OFFLINE_MINUTES))->get();
        foreach ($silent as $device) {
            $claimed = KioskDevice::query()->whereKey($device->id)->whereNull('offline_alerted_at')->update(['offline_alerted_at' => now()]);
            if ($claimed === 1) {
                $key = 'kiosk_offline:d'.$device->id.':'.$device->last_seen_at?->timestamp;
                $this->notifications->dispatch(NotificationCategoryRegistry::EMS_LOW_STOCK, new KioskScreenOfflineNotification($device->name, $device->last_seen_at?->setTimezone(HubTimezone::NAME)->format('M j, g:i A') ?? 'never', $key), eventKey: $key);
            }
        }

        return $silent->count();
    }

    /** @return array{number: string, employee_name: string, unit_label: ?string, taken_at: string, status: string, note: ?string, lines: list<array{name: string, quantity: int, unit_label: string, count_due: bool}>} */
    public static function summary(KioskWithdrawal $withdrawal): array
    {
        return [
            'number' => $withdrawal->number(),
            'employee_name' => $withdrawal->employee_name,
            'unit_label' => $withdrawal->unit_label,
            'taken_at' => $withdrawal->taken_at->copy()->setTimezone(HubTimezone::NAME)->format('M j, g:i A'),
            'status' => $withdrawal->status,
            'note' => $withdrawal->note,
            'lines' => $withdrawal->lines->map(fn (KioskWithdrawalLine $line): array => [
                'name' => $line->item_name, 'quantity' => $line->quantity, 'unit_label' => $line->unit_label, 'count_due' => $line->shelf_showed_empty,
            ])->values()->all(),
        ];
    }
}
