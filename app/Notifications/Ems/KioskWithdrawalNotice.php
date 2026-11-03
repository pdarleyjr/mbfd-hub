<?php

declare(strict_types=1);

namespace App\Notifications\Ems;

use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationCategoryRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "A member took supplies at the supply room screen." In-app only, one per withdrawal, to the EMS supply alert list;
 * the email copy is the daily summary (docs/ems/supply-room-kiosk.md section 7).
 */
class KioskWithdrawalNotice extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public const URL = '/daily/supply-room';

    /** @param array<string, mixed> $summary see KioskNotifier::summary() */
    public function __construct(
        public readonly array $summary,
        private readonly string $eventKey,
    ) {}

    protected function deliveryCategory(): ?string
    {
        return NotificationCategoryRegistry::EMS_SUPPLY_WITHDRAWALS;
    }

    /** @return list<string> */
    protected function deliveryChannelMask(): ?array
    {
        return [NotificationCategory::CHANNEL_DATABASE];
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

    /** @param array<string, mixed> $summary */
    public static function lines(array $summary): string
    {
        $lines = array_map(
            fn (array $line): string => "{$line['name']}: {$line['quantity']} {$line['unit_label']}".($line['count_due'] ? ' (shelf showed empty; count due)' : ''),
            $summary['lines'],
        );
        if ($summary['note']) {
            $lines[] = 'Note: '.$summary['note'];
        }

        return implode("\n", $lines);
    }

    /**
     * An email table of withdrawals, one row per item.
     *
     * @param  list<array<string, mixed>>  $summaries
     */
    public static function table(array $summaries, bool $withMember): string
    {
        $cell = 'padding:4px 8px;border-bottom:1px solid #ddd';
        $head = ($withMember ? '<th align="left">Time</th><th align="left">Member</th><th align="left">Unit</th>' : '').'<th align="left">Item</th><th align="right">Quantity</th>';
        $rows = '';
        foreach ($summaries as $summary) {
            $who = $withMember ? '<td style="'.$cell.'">'.e($summary['taken_at']).'</td><td style="'.$cell.'">'.e($summary['employee_name'])
                .($summary['status'] === 'undone' ? ' <em>(undone)</em>' : '').'</td><td style="'.$cell.'">'.e((string) ($summary['unit_label'] ?? '')).'</td>' : '';
            foreach ($summary['lines'] as $line) {
                $rows .= '<tr>'.$who.'<td style="'.$cell.'">'.e($line['name']).($line['count_due'] ? ' <em>(shelf showed empty; count due)</em>' : '').'</td>'
                    .'<td style="'.$cell.';text-align:right">'.e($line['quantity'].' '.$line['unit_label']).'</td></tr>';
            }
            if ($summary['note']) {
                $rows .= '<tr>'.$who.'<td style="'.$cell.'" colspan="2"><em>Note: '.e($summary['note']).'</em></td></tr>';
            }
        }

        return '<table style="border-collapse:collapse"><thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    public function title(): string
    {
        return $this->summary['employee_name'].' took supplies'.($this->summary['unit_label'] ? ' for '.$this->summary['unit_label'] : '');
    }

    /** Filament-compatible in-app notification. */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => self::lines($this->summary)."\n".$this->summary['number'].', '.$this->summary['taken_at'],
            'icon' => 'heroicon-o-shopping-bag',
            'iconColor' => 'info',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'view', 'label' => 'Open supply room', 'url' => $this->deliveryUrl($notifiable, self::URL),
                'color' => 'primary', 'isOutlined' => false,
            ]],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    public function viaQueues(): array
    {
        return ['database' => 'notifications'];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Supply room screen withdrawal notice delivery failed', ['event_key' => $this->eventKey, 'exception' => $exception->getMessage()]);
    }
}
