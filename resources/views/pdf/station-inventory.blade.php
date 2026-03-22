<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Station Inventory Order - {{ $station->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #1e40af;
            padding: 15px 0;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 20px;
            color: #1e40af;
            margin-bottom: 5px;
        }
        .header .station-name {
            font-size: 14px;
            font-weight: bold;
            color: #333;
        }
        .header .meta {
            font-size: 10px;
            color: #666;
            margin-top: 5px;
        }
        .section {
            margin-bottom: 15px;
        }
        .section-title {
            background: #e0e7ff;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: bold;
            color: #1e40af;
            border-left: 3px solid #1e40af;
            margin-bottom: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #f3f4f6;
            padding: 5px 8px;
            text-align: left;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            font-size: 10px;
        }
        td {
            padding: 4px 8px;
            border-bottom: 1px solid #eee;
        }
        .item-name {
            font-weight: 500;
        }
        .quantity {
            text-align: center;
            font-weight: bold;
        }
        .quantity.zero {
            color: #999;
        }
        .quantity.positive {
            color: #166534;
        }
        .summary {
            margin-top: 20px;
            padding: 10px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 4px;
        }
        .summary-title {
            font-weight: bold;
            color: #166534;
            margin-bottom: 5px;
        }
        .footer {
            margin-top: 30px;
            border-top: 1px solid #ddd;
            padding-top: 10px;
            font-size: 9px;
            color: #666;
            text-align: center;
        }
        .signature-section {
            margin-top: 25px;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            width: 45%;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 30px;
            padding-top: 5px;
            font-size: 10px;
        }
        .empty-message {
            color: #666;
            font-style: italic;
            padding: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📋 MBFD Station Supply Order</h1>
        <div class="station-name">{{ $station->name }}</div>
        <div class="meta">Generated: {{ $generated_at }} | Submitted by: {{ $generated_by }}</div>
    </div>

    @foreach($categories as $category)
        @php
            $categoryItems = array_filter($items, function($item) use ($category) {
                $categoryItem = collect($categories)
                    ->where('id', $category['id'])
                    ->first();
                if (!$categoryItem) return false;
                $categoryItemIds = collect($categoryItem['items'])->pluck('id')->toArray();
                return in_array($item['itemId'], $categoryItemIds);
            });
        @endphp
        
        @if(count($categoryItems) > 0)
            <div class="section">
                <div class="section-title">{{ $category['name'] }}</div>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 65%;">Item</th>
                            <th style="width: 15%;">Max Qty</th>
                            <th style="width: 15%;">Order Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($category['items'] as $index => $categoryItem)
                            @php
                                $orderItem = collect($items)->firstWhere('itemId', $categoryItem['id']);
                                $quantity = $orderItem['quantity'] ?? 0;
                            @endphp
                            @if($quantity > 0)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td class="item-name">{{ $categoryItem['name'] }}</td>
                                    <td>{{ $categoryItem['max'] }}</td>
                                    <td class="quantity positive">{{ $quantity }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach

    @if(count($items) === 0)
        <div class="empty-message">No items were ordered.</div>
    @else
        <div class="summary">
            <div class="summary-title">Order Summary</div>
            <div>Total items ordered: {{ count($items) }}</div>
        </div>

        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">Submitted By</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Approved By</div>
            </div>
        </div>
    @endif

    <div class="footer">
        MBFD Support Services | Station Inventory Management System
    </div>
</body>
</html>