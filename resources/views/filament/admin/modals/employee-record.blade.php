<div class="ep-modal mbfd-admin-employee-record er-root">
    {{-- Employee Header --}}
    <div class="er-header">
        <div class="er-avatar">
            <svg class="er-avatar__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
            </svg>
        </div>
        <div class="er-identity">
            <div class="er-name">{{ $employee->name }}</div>
            <div class="er-rank">{{ $employee->rank }}</div>
        </div>
        <div class="er-id">
            ID: {{ $employee->employee_id }}
        </div>
    </div>

    {{-- Assigned Equipment --}}
    <div class="er-section">
        <div class="er-section__title">
            Assigned Equipment ({{ $equipment->count() }} items)
        </div>
        @if($equipment->isEmpty())
            <div class="er-empty">
                No equipment assigned yet
            </div>
        @else
            <div class="er-well" tabindex="0" role="region" aria-label="Assigned equipment">
                <table data-hub-table class="er-table">
                    <thead>
                        <tr>
                            <th scope="col" class="er-th">Category</th>
                            <th scope="col" class="er-th">Item</th>
                            <th scope="col" class="er-th er-th--num">Qty</th>
                            <th scope="col" class="er-th er-th--num">Issued</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($equipment as $item)
                            <tr>
                                <td class="er-td er-td--category">{{ $item->category }}</td>
                                <td class="er-td er-td--item">{{ $item->item_description }}</td>
                                <td class="er-td er-td--qty">{{ $item->quantity }}</td>
                                <td class="er-td er-td--issued">{{ $item->issued_at?->format('M j, Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Request History --}}
    <div class="er-section">
        <div class="er-section__title">
            Request History ({{ $requests->count() }} total)
        </div>
        @if($requests->isEmpty())
            <div class="er-empty">
                No equipment requests submitted
            </div>
        @else
            <div class="er-well">
                @foreach($requests as $req)
                    @php
                        $statusTones = [
                            'Completed' => 'success',
                            'Ready for Pickup' => 'info',
                            'Ordered' => 'info',
                            'Pending' => 'warning',
                            'Declined' => 'danger',
                        ];
                        $statusTone = $statusTones[$req->status] ?? 'neutral';
                    @endphp
                    <div class="er-request">
                        <div class="er-request__row">
                            <div class="er-request__body">
                                <p class="er-request__items">{{ $req->requested_items }}</p>
                                <div class="er-request__meta">
                                    {{ $req->created_at->copy()->timezone(config('hub.timezone'))->format('M j, Y g:i A') }}
                                    @if($req->reason) · <span class="er-request__reason">{{ $req->reason }}</span> @endif
                                    @if($req->admin_notes) · <em>{{ $req->admin_notes }}</em> @endif
                                    @if($req->is_archived) · <span>Archived</span> @endif
                                </div>
                            </div>
                            <span class="er-tag er-tag--{{ $statusTone }}">{{ $req->status }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
