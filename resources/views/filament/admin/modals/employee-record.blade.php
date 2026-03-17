<div class="ep-modal">
    {{-- Employee Header --}}
    <div style="background:#292524;border-radius:0.75rem;padding:1rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.75rem;">
        <div style="width:2.75rem;height:2.75rem;border-radius:50%;background:rgba(220,38,38,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg style="width:1.375rem;height:1.375rem;color:#fca5a5;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
            </svg>
        </div>
        <div style="flex:1;">
            <div style="color:#fff;font-weight:700;font-size:1rem;">{{ $employee->name }}</div>
            <div style="color:#fca5a5;font-size:0.8125rem;">{{ $employee->rank }}</div>
        </div>
        <div style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);border-radius:0.375rem;padding:0.25rem 0.625rem;color:#d4d4d4;font-size:0.8125rem;font-variant-numeric:tabular-nums;">
            ID: {{ $employee->employee_id }}
        </div>
    </div>

    {{-- Assigned Equipment --}}
    <div style="margin-bottom:1rem;">
        <div style="font-size:0.6875rem;font-weight:700;color:#78716c;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.5rem;">
            Assigned Equipment ({{ $equipment->count() }} items)
        </div>
        @if($equipment->isEmpty())
            <div style="padding:1.25rem;text-align:center;color:#a8a29e;font-size:0.8125rem;background:#fafaf8;border:1px dashed #e8e5e0;border-radius:0.625rem;">
                No equipment assigned yet
            </div>
        @else
            <div style="background:#fff;border:1px solid #e8e5e0;border-radius:0.75rem;overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="background:#f8f6f2;border-bottom:1px solid #e8e5e0;">
                            <th style="padding:0.5rem 0.875rem;text-align:left;font-size:0.6875rem;font-weight:700;color:#78716c;text-transform:uppercase;letter-spacing:0.05em;">Category</th>
                            <th style="padding:0.5rem 0.875rem;text-align:left;">Item</th>
                            <th style="padding:0.5rem 0.875rem;text-align:right;font-variant-numeric:tabular-nums;">Qty</th>
                            <th style="padding:0.5rem 0.875rem;text-align:right;">Issued</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($equipment as $item)
                            <tr style="border-bottom:1px solid #f8f6f2;">
                                <td style="padding:0.625rem 0.875rem;color:#78716c;font-size:0.75rem;">{{ $item->category }}</td>
                                <td style="padding:0.625rem 0.875rem;color:#292524;font-weight:500;">{{ $item->item_description }}</td>
                                <td style="padding:0.625rem 0.875rem;text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">{{ $item->quantity }}</td>
                                <td style="padding:0.625rem 0.875rem;text-align:right;color:#78716c;">{{ $item->issued_at?->format('M j, Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Request History --}}
    <div>
        <div style="font-size:0.6875rem;font-weight:700;color:#78716c;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.5rem;">
            Request History ({{ $requests->count() }} total)
        </div>
        @if($requests->isEmpty())
            <div style="padding:1.25rem;text-align:center;color:#a8a29e;font-size:0.8125rem;background:#fafaf8;border:1px dashed #e8e5e0;border-radius:0.625rem;">
                No equipment requests submitted
            </div>
        @else
            <div style="background:#fff;border:1px solid #e8e5e0;border-radius:0.75rem;overflow:hidden;">
                @foreach($requests as $req)
                    @php
                        $statusColors = [
                            'Completed' => ['bg' => '#f0fdf4', 'text' => '#166534'],
                            'Ready for Pickup' => ['bg' => '#eff6ff', 'text' => '#1e40af'],
                            'Ordered' => ['bg' => '#f0f9ff', 'text' => '#0369a1'],
                            'Pending' => ['bg' => '#fffbeb', 'text' => '#92400e'],
                            'Declined' => ['bg' => '#fef2f2', 'text' => '#991b1b'],
                        ];
                        $sc = $statusColors[$req->status] ?? ['bg' => '#f8f6f2', 'text' => '#44403c'];
                    @endphp
                    <div style="padding:0.75rem 0.875rem;border-bottom:1px solid #f8f6f2;{{ $req->is_archived ? 'opacity:0.6;' : '' }}">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.75rem;">
                            <div style="flex:1;min-width:0;">
                                <p style="font-size:0.8125rem;color:#292524;line-height:1.5;">{{ $req->requested_items }}</p>
                                <div style="font-size:0.6875rem;color:#a8a29e;margin-top:0.25rem;">
                                    {{ $req->created_at->format('M j, Y g:i A') }}
                                    @if($req->reason) · <span style="color:#78716c;">{{ $req->reason }}</span> @endif
                                    @if($req->admin_notes) · <em>{{ $req->admin_notes }}</em> @endif
                                    @if($req->is_archived) · <span style="color:#a8a29e;">Archived</span> @endif
                                </div>
                            </div>
                            <span style="background:{{ $sc['bg'] }};color:{{ $sc['text'] }};padding:0.125rem 0.5rem;border-radius:9999px;font-size:0.625rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;flex-shrink:0;">{{ $req->status }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
