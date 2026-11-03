<?php

declare(strict_types=1);

namespace App\Notifications\Ems;

use App\Notifications\Channels\BudgetedMailChannel;
use App\Notifications\Concerns\EvaluatesDelivery;
use App\Services\Notifications\NotificationCategoryRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

/**
 * "These supply room items just went low or out, and this is how many to order." One message per check,
 * listing every item that dropped into red at once (docs/ems/captain5-medical-inventory-workflow.md section 12).
 * Who gets it, and how, is decided by the notification evaluator for the `ems_low_stock` category.
 * Supply names and amounts are not personal, so the lock screen shows them.
 */
class EmsLowStockNotification extends Notification implements ShouldQueue
{
    use EvaluatesDelivery;
    use Queueable;

    public const URL = '/daily/supply-room';

    /**
     * @param  list<array{name: string, shelf: ?string, on_hand: int|float, expected: int|float, unit: string, to_order: int, order_unit: string, order_status: ?string, estimated: bool}>  $items
     */
    public function __construct(
        public readonly array $items,
        private readonly string $eventKey,
    ) {}

    protected function deliveryCategory(): ?string
    {
        return NotificationCategoryRegistry::EMS_LOW_STOCK;
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

    public function title(): string
    {
        $count = count($this->items);

        return $count === 1
            ? "EMS supply low: {$this->items[0]['name']}"
            : "EMS supplies low: {$count} items";
    }

    /**
     * One line per item: what is left and what to order.
     *
     * @param  array{name: string, shelf: ?string, on_hand: int|float, expected: int|float, unit: string, to_order: int, order_unit: string, order_status: ?string, estimated: bool}  $item
     */
    public static function line(array $item): string
    {
        $line = "{$item['name']}: {$item['on_hand']} of {$item['expected']} {$item['unit']} on hand";
        if ($item['order_status'] !== null) {
            return "{$line}. Already {$item['order_status']}.";
        }

        return "{$line}. Order {$item['to_order']} {$item['order_unit']}.".($item['estimated'] ? ' (Estimated count.)' : '');
    }

    public function body(): string
    {
        return implode("\n", array_map(self::line(...), $this->items));
    }

    /** @return array{subject: string, text: string, html: string} */
    public function toBudgetedEmail(object $notifiable): array
    {
        $url = url($this->deliveryUrl($notifiable, self::URL));
        $cell = 'padding:4px 8px;border-bottom:1px solid #ddd';
        $rows = '';
        foreach ($this->items as $item) {
            $action = $item['order_status'] !== null ? 'Already '.$item['order_status'] : 'Order '.$item['to_order'].' '.$item['order_unit'];
            $rows .= '<tr><td style="'.$cell.'">'.e($item['name']).($item['estimated'] ? ' <em>(estimated count)</em>' : '').'</td>'
                .'<td style="'.$cell.'">'.e((string) ($item['shelf'] ?? '')).'</td>'
                .'<td style="'.$cell.';text-align:right">'.e($item['on_hand'].' of '.$item['expected'].' '.$item['unit']).'</td>'
                .'<td style="'.$cell.'"><strong>'.e($action).'</strong></td></tr>';
        }
        $intro = 'These supply room items are low or out. Each line says how many to order to get back to the expected amount.';

        return [
            'subject' => $this->title(),
            'text' => $intro."\n\n".$this->body()."\n\nOpen the Master Supply Room Inventory: {$url}",
            'html' => '<h1>'.e($this->title()).'</h1><p>'.e($intro).'</p>'
                .'<table style="border-collapse:collapse"><thead><tr><th align="left">Item</th><th align="left">Shelf</th><th align="right">On hand</th><th align="left">To do</th></tr></thead><tbody>'
                .$rows.'</tbody></table><p><a href="'.e($url).'">Open the Master Supply Room Inventory</a></p>',
        ];
    }

    /** Filament-compatible in-app notification. */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'icon' => 'heroicon-o-exclamation-triangle',
            'iconColor' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'view', 'label' => 'Open supply room', 'url' => $this->deliveryUrl($notifiable, self::URL),
                'color' => 'primary', 'isOutlined' => false,
            ]],
            'provenance' => $this->deliveryProvenance($notifiable),
        ];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $first = array_slice($this->items, 0, 3);
        $more = count($this->items) - count($first);
        $body = implode("\n", array_map(self::line(...), $first)).($more > 0 ? "\nAnd {$more} more." : '');

        return (new WebPushMessage)
            ->title($this->title())
            ->body($body)
            ->icon('/images/mbfd-logo.png')
            ->badge('/images/mbfd-logo.png')
            ->tag('mbfd-ems-low-stock-'.substr(sha1($this->eventKey), 0, 12))
            ->data(['url' => $this->deliveryUrl($notifiable, self::URL)]);
    }

    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            WebPushChannel::class => 'notifications',
            BudgetedMailChannel::class => 'notifications',
        ];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('EMS low-stock notification delivery failed', [
            'event_key' => $this->eventKey, 'items' => count($this->items), 'exception' => $exception->getMessage(),
        ]);
    }
}
