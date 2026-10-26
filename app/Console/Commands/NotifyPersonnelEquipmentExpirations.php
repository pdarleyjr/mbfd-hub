<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\EquipmentExpirationNotification;
use App\Models\User;
use App\Notifications\OperationalAlertNotification;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationRecipientEvaluator;
use App\Support\HubTimezone;
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
                // Whoever may manage assigned equipment right now (the policy, not a role-name list).
                $admins = app(NotificationRecipientEvaluator::class)->recipients(NotificationCategoryRegistry::EQUIPMENT_EXPIRATIONS);
                foreach ($assignments as $assignment) {
                    $days = HubTimezone::today()->diffInDays($assignment->expires_at, false);
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

            $expired = $assignment->expires_at->isBefore(HubTimezone::today());
            $title = $expired ? 'Assigned equipment expired' : 'Assigned equipment expiring soon';
            $body = "{$assignment->item_description} for {$assignment->employee?->name} ".($expired ? 'expired' : 'expires')." on {$assignment->expires_at->format('M j, Y')}.";
            $icon = $expired ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-clock';
            $url = $recipientType === 'employee' ? '/employee/my-equipment-page' : '/admin/personnel-uniforms-equipment/assignments';

            if ($recipient instanceof Employee) {
                $this->notifyEmployee($recipient, $title, $body, $icon, $url);

                return true;
            }

            app(NotificationDispatchService::class)->notifyUser(NotificationCategoryRegistry::EQUIPMENT_EXPIRATIONS, $recipient, new OperationalAlertNotification(
                category: NotificationCategoryRegistry::EQUIPMENT_EXPIRATIONS,
                title: $title,
                body: $body,
                url: $url,
                icon: $icon,
                iconColor: $expired ? 'danger' : 'warning',
                actionLabel: 'View',
            ));

            return true;
        });
    }

    /**
     * The member's own equipment: an update about something of theirs, decided by the evaluator for their account.
     * Personnel with no bound account keep the personnel inbox (carried over to the account at first sign-in).
     */
    private function notifyEmployee(Employee $employee, string $title, string $body, string $icon, string $url): void
    {
        $account = $employee->user;
        if ($account instanceof User && $account->employee_id === $employee->employee_id) {
            app(NotificationDispatchService::class)->notifyUser(User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES, $account, new OperationalAlertNotification(
                category: User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES,
                title: $title,
                body: $body,
                url: $url,
                icon: $icon,
                iconColor: 'warning',
                actionLabel: 'View',
            ));

            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->actions([Action::make('view')->url($url)->markAsRead()])
            ->sendToDatabase($employee);
    }
}
