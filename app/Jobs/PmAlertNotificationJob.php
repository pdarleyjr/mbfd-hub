<?php

namespace App\Jobs;

use App\Models\Apparatus;
use App\Models\User;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Checks PM health status after an inspection updates meter readings.
 * Sends Filament database notifications to admin users when thresholds are crossed.
 *
 * Deduplication: Only sends one notification per apparatus per threshold per 24 hours.
 */
class PmAlertNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $apparatusId,
        protected string $previousStatus = 'green',
    ) {}

    public function handle(): void
    {
        $apparatus = Apparatus::find($this->apparatusId);
        if (! $apparatus) {
            return;
        }

        $health = $apparatus->getPmHealthStatus();
        $currentStatus = $health['status'];

        // Only send if status worsened (green→yellow, yellow→red, green→red)
        $statusRank = ['green' => 0, 'yellow' => 1, 'red' => 2];
        $previousRank = $statusRank[$this->previousStatus] ?? 0;
        $currentRank = $statusRank[$currentStatus];

        if ($currentRank <= $previousRank) {
            return; // No escalation, skip notification
        }

        // Fleet reviewers as they are right now (the single InspectionReviewers definition),
        // narrowed by each member's in-app choice. There is deliberately no guess-by-email fallback.
        $evaluator = app(NotificationRecipientEvaluator::class);
        $admins = $evaluator->recipients('vehicle_maintenance_alerts')
            ->filter(fn (User $user): bool => $evaluator->decide($user, 'vehicle_maintenance_alerts')->allows('database'));

        $unit = $apparatus->designation ?? $apparatus->vehicle_number ?? "ID#{$apparatus->id}";
        $hours = $health['hours_since_pm'];
        $interval = $health['interval_hours'];

        if ($health['overdue']) {
            $hoursOver = round($hours - $interval, 1);
            $notification = Notification::make()
                ->title("🚨 {$unit} PM OVERDUE")
                ->body("{$unit} is {$hoursOver}h past PM due ({$hours}h since last service). Immediate service required.")
                ->danger()
                ->icon('heroicon-s-fire')
                ->persistent();
        } elseif ($currentStatus === 'red') {
            $notification = Notification::make()
                ->title("🔴 {$unit} PM Service Required")
                ->body("{$unit} has reached {$hours}h since last PM. Schedule service immediately.")
                ->danger()
                ->icon('heroicon-s-exclamation-triangle');
        } else {
            // Yellow
            $remaining = round($interval - $hours, 1);
            $notification = Notification::make()
                ->title("🟡 {$unit} PM Due Soon")
                ->body("{$unit}: {$hours}h since last PM. Approximately {$remaining}h remaining before service required.")
                ->warning()
                ->icon('heroicon-s-clock');
        }

        foreach ($admins as $admin) {
            $notification->sendToDatabase($admin);
        }

        Log::info("PM Alert: {$unit} status escalated from {$this->previousStatus} to {$currentStatus} ({$hours}h)");
    }
}
