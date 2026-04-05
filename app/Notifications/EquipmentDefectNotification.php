<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EquipmentDefectNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $type,
        protected string $assetName,
        protected string $assetTag,
        protected string $apparatusName,
        protected string $operatorName,
        protected string $notes,
        protected string $inspectionRef,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->type === 'damaged'
            ? "Equipment DAMAGED: {$this->assetName} ({$this->assetTag}) on {$this->apparatusName}"
            : "Equipment MISSING: {$this->assetName} ({$this->assetTag}) on {$this->apparatusName}";

        $statusLabel = $this->type === 'damaged' ? 'DAMAGED' : 'MISSING';

        $message = (new MailMessage())
            ->subject($subject)
            ->greeting("Equipment Defect Alert — {$statusLabel}")
            ->line("**Equipment:** {$this->assetName} (Tag: {$this->assetTag})")
            ->line("**Apparatus:** {$this->apparatusName}")
            ->line("**Reported by:** {$this->operatorName}")
            ->line("**Status:** {$statusLabel}")
            ->line("**Inspection Ref:** {$this->inspectionRef}");

        if ($this->notes) {
            $message->line("**Notes:** {$this->notes}");
        }

        $snipeitUrl = rtrim(config('snipeit.url', 'https://inventory.mbfdhub.com'), '/api/v1');
        $message->action('View in Snipe-IT', $snipeitUrl)
            ->line('The equipment status has been automatically changed to Out for Repair in Snipe-IT.');

        if ($this->type === 'damaged') {
            $message->line('A maintenance work order has been created.');
        }

        return $message;
    }
}
