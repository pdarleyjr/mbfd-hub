<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .header { background: #1e40af; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f3f4f6; font-weight: bold; }
        .footer { background: #f9fafb; padding: 15px; text-align: center; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MBFD Supply Order</h1>
    </div>
    <div class="content">
        <p><strong>Order Date:</strong> {{ $orderDate }}</p>
        
        <p>Please process the following supply order for Miami Beach Fire Department:</p>
        
        <table>
            <thead>
                <tr>
                    <th>Station</th>
                    <th>Item</th>
                    <th>SKU</th>
                    <th>Quantity</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orderItems as $item)
                <tr>
                    <td>{{ $item['station'] }}</td>
                    <td>{{ $item['item'] }}</td>
                    <td>{{ $item['sku'] }}</td>
                    <td>{{ $item['qty'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @if($notes)
        <p><strong>Additional Notes:</strong></p>
        <p>{{ $notes }}</p>
        @endif

        <p>Thank you for your service.</p>
        <p><strong>Miami Beach Fire Department</strong><br>
        2300 Pine Tree Drive<br>
        Miami Beach, FL 33140</p>
    </div>
    <div class="footer">
        <p>This is an automated message from the MBFD Support Hub system</p>
    </div>
</body>
</html>
