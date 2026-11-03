<?php

declare(strict_types=1);

namespace App\Notifications\Ems;

use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationCategoryRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One email a day to the EMS supply alert list: every supply room screen withdrawal of the past 24 hours. */
class KioskWithdrawalDailySummary extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    /** @param list<array<string, mixed>> $rows see KioskNotifier::summary() */
    public function __construct(
        public readonly array $rows,
        public readonly string $day,
        private readonly string $eventKey,
    ) {}

    protected function deliveryCategory(): ?string
    {
        return NotificationCategoryRegistry::EMS_SUPPLY_WITHDRAWALS;
    }

    /** @return list<string> */
    protected function deliveryChannelMask(): ?array
    {
        return [NotificationCategory::CHANNEL_EMAIL];
    }

    protected function deliveryEventKey(): ?string
    {
        return $this->eventKey;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    /** @return array{subject: string, text: string, html: string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        $count = count($this->rows);
        $subject = "EMS supply room screen: {$count} ".($count === 1 ? 'withdrawal' : 'withdrawals').' in the last 24 hours';
        $text = implode("\n\n", array_map(
            fn (array $row): string => "{$row['taken_at']}  {$row['employee_name']}".($row['unit_label'] ? " ({$row['unit_label']})" : '')
                .($row['status'] === 'undone' ? ' (undone)' : '')."\n".KioskWithdrawalNotice::lines($row),
            $this->rows,
        ));
        $url = url($this->deliveryUrl($notifiable, KioskWithdrawalNotice::URL));

        return [
            'subject' => $subject,
            'text' => $subject."\n\n".$text."\n\nOpen the Master Supply Room Inventory: {$url}",
            'html' => '<h1>'.e($subject).'</h1>'.KioskWithdrawalNotice::table($this->rows, true)
                .'<p><a href="'.e($url).'">Open the Master Supply Room Inventory</a></p>',
        ];
    }

    public function viaQueues(): array
    {
        return [BudgetedMailChannel::class => 'notifications'];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Supply room screen daily summary delivery failed', ['event_key' => $this->eventKey, 'exception' => $exception->getMessage()]);
    }
}
