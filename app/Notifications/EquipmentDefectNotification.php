<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\BudgetedMailChannel;
use Illuminate\Bus\Queueable;
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
        return [BudgetedMailChannel::class];
    }

    /** @return array{subject: string, text: string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        $statusLabel = $this->type === 'damaged' ? 'damaged' : 'missing';
        $subject = "Equipment {$statusLabel}: {$this->assetName} ({$this->assetTag}) on {$this->apparatusName}";

        $snipeitUrl = $this->snipeItBaseUrl();
        $lines = [
            "Equipment defect alert: {$statusLabel}",
            "Equipment: {$this->assetName} (Tag: {$this->assetTag})",
            "Apparatus: {$this->apparatusName}",
            "Reported by: {$this->operatorName}",
            "Status: {$statusLabel}",
            "Inspection Ref: {$this->inspectionRef}",
        ];
        if ($this->notes !== '') {
            $lines[] = "Notes: {$this->notes}";
        }
        $lines[] = "View in Snipe-IT: {$snipeitUrl}";
        $lines[] = 'The equipment status has been automatically changed to Out for Repair in Snipe-IT.';
        if ($this->type === 'damaged') {
            $lines[] = 'A maintenance work order has been created.';
        }

        return ['subject' => $subject, 'text' => implode("\n", $lines)];
    }

    /** The Snipe-IT address people open, from the API address in configuration (which ends in /api/v1). */
    private function snipeItBaseUrl(): string
    {
        $url = rtrim(trim((string) config('snipeit.url', 'https://inventory.mbfdhub.com')), '/');

        return rtrim(preg_replace('#/api/v1$#i', '', $url) ?? $url, '/');
    }
}
