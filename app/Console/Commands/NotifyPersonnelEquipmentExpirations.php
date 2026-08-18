<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AssignedEquipment;
use App\Models\EquipmentExpirationNotification;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifyPersonnelEquipmentExpirations extends Command
{
    protected $signature = 'personnel-equipment:notify-expirations';

    protected $description = 'Send deduplicated personnel equipment expiration notifications.';

    public function handle(): int
    {
        $thresholds = collect(config('personnel_requests.expiration_thresholds', [60, 30, 7, 0]))
            ->map(fn ($value) => (int) $value)->unique()->sort()->values();
        $sent = 0;

        AssignedEquipment::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->with('employee')
            ->chunkById(100, function ($assignments) use ($thresholds, &$sent): void {
                $admins = User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', ['super_admin', 'admin', 'logistics_admin']))->get();
                foreach ($assignments as $assignment) {
                    $days = today()->diffInDays($assignment->expires_at, false);
                    $threshold = $thresholds->first(fn (int $candidate) => $candidate >= $days);
                    if ($threshold === null) {
                        continue;
                    }

                    if ($assignment->employee && $this->notify($assignment, $threshold, 'employee', $assignment->employee)) {
                        $sent++;
                    }
                    foreach ($admins as $admin) {
                        if ($this->notify($assignment, $threshold, 'admin', $admin)) {
                            $sent++;
                        }
                    }
                }
            });

        $this->info("Sent {$sent} expiration notification(s).");

        return self::SUCCESS;
    }

    private function notify(AssignedEquipment $assignment, int $threshold, string $recipientType, $recipient): bool
    {
        return DB::transaction(function () use ($assignment, $threshold, $recipientType, $recipient): bool {
            $ledger = EquipmentExpirationNotification::query()->firstOrCreate([
                'assigned_equipment_id' => $assignment->id,
                'expiration_date' => $assignment->expires_at->copy()->startOfDay(),
                'threshold_days' => $threshold,
                'recipient_type' => $recipientType,
                'recipient_id' => $recipient->id,
            ], ['sent_at' => now()]);

            if (! $ledger->wasRecentlyCreated) {
                return false;
            }

            $expired = $assignment->expires_at->isBefore(today());
            Notification::make()
                ->title($expired ? 'Assigned equipment expired' : 'Assigned equipment expiring soon')
                ->body("{$assignment->item_description} for {$assignment->employee?->name} ".($expired ? 'expired' : 'expires')." on {$assignment->expires_at->format('M j, Y')}.")
                ->icon($expired ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-clock')
                ->actions([
                    Action::make('view')->url($recipientType === 'employee' ? '/employee/my-equipment-page' : '/admin/personnel-uniforms-equipment/assignments')->markAsRead(),
                ])
                ->sendToDatabase($recipient);

            return true;
        });
    }
}
