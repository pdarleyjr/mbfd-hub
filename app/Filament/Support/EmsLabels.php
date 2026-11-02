<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyOrder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Plain firehouse wording for the Admin -> EMS screens and exports. One place, so screens and files agree. */
final class EmsLabels
{
    public const TIMEZONE = \App\Support\HubTimezone::NAME;

    public const STATUS = [
        InventoryRequest::SUBMITTED => 'Submitted',
        InventoryRequest::REVIEWED => 'Reviewed',
        InventoryRequest::PREPARED => 'Prepared',
        InventoryRequest::PARTIALLY_ISSUED => 'Partly issued',
        InventoryRequest::ISSUED => 'Issued',
        InventoryRequest::CLOSED => 'Closed',
        InventoryRequest::CANCELLED => 'Cancelled',
        InventoryRequest::RETURNED => 'Needs correction',
        InventoryRequest::RECONCILED => 'Reconciled',
        InventoryRequest::SUPERSEDED => 'Replaced by a correction',
    ];

    public const STATUS_COLOR = [
        InventoryRequest::SUBMITTED => 'warning',
        InventoryRequest::REVIEWED => 'info',
        InventoryRequest::PREPARED => 'info',
        InventoryRequest::PARTIALLY_ISSUED => 'warning',
        InventoryRequest::ISSUED => 'success',
        InventoryRequest::CLOSED => 'gray',
        InventoryRequest::CANCELLED => 'gray',
        InventoryRequest::RETURNED => 'warning',
        InventoryRequest::RECONCILED => 'success',
        InventoryRequest::SUPERSEDED => 'gray',
    ];

    public const LINE_STATUS = [
        InventoryRequestLine::OPEN => 'Waiting',
        InventoryRequestLine::PREPARED => 'Prepared',
        InventoryRequestLine::PARTIALLY_ISSUED => 'Partly issued',
        InventoryRequestLine::ISSUED => 'Issued',
        InventoryRequestLine::NOT_ISSUED => 'Not issued',
    ];

    public const LINE_STATUS_COLOR = [
        InventoryRequestLine::OPEN => 'gray',
        InventoryRequestLine::PREPARED => 'info',
        InventoryRequestLine::PARTIALLY_ISSUED => 'warning',
        InventoryRequestLine::ISSUED => 'success',
        InventoryRequestLine::NOT_ISSUED => 'danger',
    ];

    public const TARGET_KIND = [
        InventoryRequest::TARGET_RESCUE => 'Rescue unit',
        InventoryRequest::TARGET_OTHER => 'Other unit',
        InventoryRequest::TARGET_DETAIL => 'Detail / Special Event',
    ];

    public const BAG = ['airway-bag' => 'Airway Bag', 'als-med-bag' => 'ALS Med Bag or Box', 'als-transport-vehicle' => 'ALS Transport Vehicle'];

    public const WARNING = [
        'no_previous_count' => 'No earlier count',
        'unknown_conversion' => 'Size not stated',
        'unmatched_issuances' => 'Issued with no matching item',
        'negative_result' => 'Would be below zero',
    ];

    public const MOVEMENT = [StockMovement::ISSUED => 'Issued', StockMovement::RECEIVED => 'Received', StockMovement::ADJUSTED => 'Adjusted'];

    public const VALUE_SOURCE = [
        InventorySnapshotLine::COUNTED => 'Counted',
        InventorySnapshotLine::CALCULATED => 'Calculated',
        InventorySnapshotLine::NOT_COUNTED => 'Not counted',
    ];

    public const SNAPSHOT_KIND = [
        InventorySnapshot::KIND_COUNT => 'Physical count',
        InventorySnapshot::KIND_RECONCILIATION => 'Reconciliation (calculated)',
        InventorySnapshot::KIND_BASELINE => 'Baseline',
    ];

    public const ORDER_STATUS = [SupplyOrder::PLACED => 'Order placed', SupplyOrder::RECEIVED => 'Received', SupplyOrder::CANCELLED => 'Cancelled'];

    public static function status(?string $status): string
    {
        return self::STATUS[(string) $status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    public static function warning(string $code): string
    {
        return self::WARNING[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }

    /** Eastern time, for people. */
    public static function et(CarbonInterface|string|null $value, string $format = 'M j, Y g:i A'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return CarbonImmutable::parse($value)->setTimezone(self::TIMEZONE)->format($format);
    }

    /** "Box of 24", "Case, size not stated", "Each". */
    public static function packageText(?string $packageUnit, ?int $unitsPerPackage, ?int $packagesPerCase = null): string
    {
        if ($packageUnit === null || $packageUnit === '' || $packageUnit === 'each') {
            return 'Each';
        }
        $unit = ucfirst($packageUnit);
        $text = $unitsPerPackage !== null ? "{$unit} of {$unitsPerPackage}" : "{$unit}, size not stated";
        if ($packagesPerCase !== null && $packageUnit !== 'case') {
            $text .= ", {$packagesPerCase} per case";
        }

        return $text;
    }

    /**
     * One plain sentence for a timeline entry.
     *
     * @param  array<string, mixed>  $event  a RequestPresenter history row
     */
    public static function eventSentence(array $event): string
    {
        $p = (array) ($event['payload'] ?? []);
        $label = (string) ($p['label'] ?? 'an item');
        $reason = isset($p['reason']) && $p['reason'] !== '' ? ' Reason: '.$p['reason'] : '';

        return match ((string) $event['action']) {
            'submitted' => 'Submitted the request.',
            'reviewed' => 'Reviewed the request.',
            'prepared' => 'Prepared '.($p['quantity'] ?? 'no quantity').' of '.$label.'.',
            'issued' => 'Issued '.($p['quantity'] ?? '?').' of '.$label.'.'.(! empty($p['over_issue']) ? ' More than was requested.' : '').$reason,
            'issue_corrected' => 'Corrected '.$label.' from '.($p['previous_quantity'] ?? '?').' to '.($p['new_quantity'] ?? '?').'.'.$reason,
            'not_issued' => 'Marked '.$label.' not issued.'.$reason,
            'closed' => 'Closed the request.'.$reason,
            'reopened' => 'Reopened the request.'.$reason,
            'cancelled' => 'The member cancelled the request.'.$reason,
            'status_changed' => 'Status changed to '.strtolower(self::status($event['to_status'] ?? null)).'.',
            default => ucfirst(str_replace('_', ' ', (string) $event['action'])).'.',
        };
    }

    /**
     * What an issuance did to supply room stock, in words.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function stockEffect(array $payload): ?string
    {
        return match ($payload['stock_effect'] ?? null) {
            'applied' => 'Stock updated'.(! empty($payload['supply_item_name']) ? ': '.$payload['supply_item_name'] : ''),
            'unmatched' => 'Recorded only: no matching supply room item'.(! empty($payload['stock_note']) ? ' ('.$payload['stock_note'].')' : ''),
            'none' => 'Recorded on the request only (controlled item)',
            default => null,
        };
    }
}
