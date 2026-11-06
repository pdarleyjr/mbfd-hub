<?php

declare(strict_types=1);

namespace App\Notifications\Accidents;

use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\NotificationCategoryRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Accident alerts.
 *
 * - AUDIENCE_ADMIN: an accident report was submitted (category accident_reports). Sent the moment the first part
 *   is submitted.
 * - AUDIENCE_SUPERVISOR: a Vehicle Accident Report was routed to this supervisor to complete their part.
 *
 * Accident reports hold medical and third-party details, so no channel carries a name, vehicle, injury or
 * description: the lock screen, the email and the in-app alert give the report type and reference only, and the
 * link opens the restricted report, where access is checked again.
 */
final class AccidentReportNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public const AUDIENCE_ADMIN = 'admin';

    public const AUDIENCE_SUPERVISOR = 'supervisor';

    public function __construct(
        public readonly string $audience,
        public readonly int $reportId,
        public readonly string $reportUlid,
        private readonly string $typeLabel,
        private readonly string $reference,
    ) {
        $this->onQueue('notifications');
    }

    public static function eventKey(string $kind, int $reportId): string
    {
        return 'accident-'.$kind.':'.$reportId;
    }

    public function categoryKey(): string
    {
        return $this->audience === self::AUDIENCE_ADMIN
            ? NotificationCategoryRegistry::ACCIDENT_REPORTS
            : NotificationCategoryRegistry::ACCIDENT_SUPERVISOR_REQUESTS;
    }

    protected function deliveryCategory(): string
    {
        return $this->categoryKey();
    }

    protected function deliveryEventKey(): string
    {
        return self::eventKey($this->audience === self::AUDIENCE_ADMIN ? 'alert' : 'supervisor', $this->reportId);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->evaluatedChannels($notifiable);
    }

    public function path(): string
    {
        return $this->audience === self::AUDIENCE_ADMIN
            ? '/admin/accident-reports/'.$this->reportUlid
            : '/employee/forms?form=accident-report&report='.$this->reportUlid;
    }

    private function title(): string
    {
        return $this->audience === self::AUDIENCE_ADMIN ? 'Accident reported. Action required.' : 'Accident report needs your supervisor\'s part';
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->typeLabel.' '.$this->reference.'. Open the report in the Hub.',
            'icon' => 'heroicon-o-exclamation-triangle',
            'iconColor' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'view',
                'label' => $this->audience === self::AUDIENCE_ADMIN ? 'Open report' : 'Complete supervisor\'s part',
                'url' => $this->deliveryUrl($notifiable, $this->path()),
                'color' => 'danger',
                'isOutlined' => false,
            ]],
            'accident_report' => ['id' => $this->reportId, 'reference' => $this->reference],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    /** @return array{title: string, body: string} */
    public function pushText(): array
    {
        return ['title' => $this->title(), 'body' => 'Open MBFD Hub to see the report. Details stay in the Hub.'];
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        $push = $this->pushText();

        return (new WebPushMessage)
            ->title($push['title'])
            ->body($push['body'])
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-accident-'.$this->audience.'-'.$this->reportId)
            ->data(['url' => $this->deliveryUrl($notifiable, $this->path())]);
    }

    /** @return array{subject: string, text: string, html: string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        $push = $this->pushText();
        $url = url($this->deliveryUrl($notifiable, $this->path()));

        return [
            'subject' => $push['title'].' ('.$this->reference.')',
            'text' => $push['body']."\n\nOpen in MBFD Hub: {$url}",
            'html' => '<h1>'.e($push['title']).'</h1><p>'.e($push['body']).'</p><p><a href="'.e($url).'">Open in MBFD Hub</a></p>',
        ];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            WebPushChannel::class => 'notifications',
            BudgetedMailChannel::class => 'notifications',
        ];
    }
}
