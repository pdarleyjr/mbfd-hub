<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee"
    x-init="window.matchMedia('(max-width: 1023px)').matches && $store.sidebar.close()">
    @php
        $requestsHref = \App\Filament\Employee\Pages\MyRequestsPage::getUrl(panel: 'employee');
        $equipmentHref = \App\Filament\Employee\Pages\MyEquipmentPage::getUrl(panel: 'employee');
        $uniformsHref = \App\Filament\Employee\Pages\RequestEquipmentPage::getUrl(panel: 'employee');
        $chevron = 'm8.25 4.5 7.5 7.5-7.5 7.5';
    @endphp
    {{-- Activity, then the member's destinations, then records. From 1280px the destinations become a rail beside
         the activity and records, like Quick Access on Home. Counts sit on the row they belong to, not in a stat band. --}}
    <div class="ep-dash">
        @include('filament.employee.partials.my-activity-card', ['items' => $activity])

        <nav class="ep-dash__rail" aria-labelledby="ep-workspace-heading">
            <h2 id="ep-workspace-heading" class="hub-portal-section-title">Your workspace</h2>
            {{-- The same tile rows as Quick Access on Home, so both pages read as one product. --}}
            <ul class="hub-tiles hub-tiles--rail ep-tiles">
                <li>
                    <a href="{{ $equipmentHref }}" class="hub-tile hub-tile--personnel">
                        <x-heroicon-o-shield-check class="hub-tile__glyph" aria-hidden="true" />
                        <span class="hub-tile__body">
                            <span class="hub-tile__title">My Equipment</span>
                            <span class="hub-tile__description">{{ $equipmentCount > 0 ? $equipmentCount.' '.\Illuminate\Support\Str::plural('item', $equipmentCount).' assigned to you' : 'Gear assigned to you' }}</span>
                        </span>
                        @if($equipmentCount > 0)
                            <span class="hub-tag tabular-nums" aria-hidden="true">{{ $equipmentCount }}</span>
                        @endif
                        <svg class="hub-tile__affordance" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg>
                    </a>
                </li>
                <li>
                    <a href="{{ $requestsHref }}" class="hub-tile hub-tile--personnel">
                        <x-heroicon-o-inbox-stack class="hub-tile__glyph" aria-hidden="true" />
                        <span class="hub-tile__body">
                            <span class="hub-tile__title">My Requests</span>
                            <span class="hub-tile__description">{{ $pendingRequests > 0 ? $pendingRequests.' '.\Illuminate\Support\Str::plural('request', $pendingRequests).' in progress' : 'Request history and status' }}</span>
                        </span>
                        @if($pendingRequests > 0)
                            <span class="hub-tag hub-tag--warning tabular-nums" aria-hidden="true">{{ $pendingRequests }}</span>
                        @endif
                        <svg class="hub-tile__affordance" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg>
                    </a>
                </li>
                <li>
                    <a href="{{ $uniformsHref }}" class="hub-tile hub-tile--logistics">
                        <x-heroicon-o-shopping-bag class="hub-tile__glyph" aria-hidden="true" />
                        <span class="hub-tile__body">
                            <span class="hub-tile__title">Request Uniforms</span>
                            <span class="hub-tile__description">Approved department workwear</span>
                        </span>
                        <svg class="hub-tile__affordance" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg>
                    </a>
                </li>
                @if ($canMessageLogistics)
                    <li>
                        <a href="{{ \App\Filament\Employee\Pages\MyMessages::getUrl($messageUnread > 0 ? [] : ['compose' => 1], panel: 'employee') }}" class="hub-tile hub-tile--personnel emsg-dashboard-card" data-employee-messages-shortcut>
                            <x-heroicon-o-chat-bubble-bottom-center-text class="hub-tile__glyph" aria-hidden="true" />
                            <span class="hub-tile__body">
                                <span class="hub-tile__title">Message Support Services</span>
                                <span class="hub-tile__description">
                                    @if ($messageUnread > 0)
                                        {{ $messageUnread }} new {{ \Illuminate\Support\Str::plural('reply', $messageUnread) }} in My Messages
                                    @else
                                        Supplies, uniforms, bunker gear cleaning
                                    @endif
                                </span>
                            </span>
                            @if ($messageUnread > 0)
                                <span class="emsg-unread-dot">{{ $messageUnread }}<span class="sr-only"> unread</span></span>
                            @endif
                            <svg class="hub-tile__affordance" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg>
                        </a>
                    </li>
                @endif
                <li>
                    <a href="{{ \App\Filament\Employee\Pages\OperationalForms::getUrl(panel: 'employee') }}" class="hub-tile hub-tile--operations">
                        <x-heroicon-o-clipboard-document-check class="hub-tile__glyph" aria-hidden="true" />
                        <span class="hub-tile__body">
                            <span class="hub-tile__title">Forms</span>
                            <span class="hub-tile__description">Operational forms and inspections</span>
                        </span>
                        <svg class="hub-tile__affordance" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg>
                    </a>
                </li>
                <li>
                    <a href="{{ \App\Filament\Employee\Pages\ApparatusServiceRequestPage::getUrl(panel: 'employee') }}" class="hub-tile hub-tile--operations">
                        <x-heroicon-o-wrench-screwdriver class="hub-tile__glyph" aria-hidden="true" />
                        <span class="hub-tile__body">
                            <span class="hub-tile__title">Apparatus Service</span>
                            <span class="hub-tile__description">Report a repair or service need to Fleet</span>
                        </span>
                        <svg class="hub-tile__affordance" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg>
                    </a>
                </li>
                <li>
                    <a href="{{ \App\Filament\Employee\Pages\VideoConferencing::getUrl(panel: 'employee') }}" class="hub-tile hub-tile--training">
                        <x-heroicon-o-video-camera class="hub-tile__glyph" aria-hidden="true" />
                        <span class="hub-tile__body">
                            <span class="hub-tile__title">Video Conferencing</span>
                            <span class="hub-tile__description">Department video meetings</span>
                        </span>
                        <svg class="hub-tile__affordance" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chevron }}"/></svg>
                    </a>
                </li>
            </ul>
        </nav>

        <div class="ep-dash__records">
            @if($bidAssignment)
                <section class="ep-bid-assignment ep-panel" aria-labelledby="bid-assignment-heading" data-bid-assignment>
                    <div class="ep-panel-header">
                        <h2 id="bid-assignment-heading" class="ep-panel-title">{{ $bidAssignment->term_label }} {{ $bidAssignment->assignment_source === 'retained_nonbiddable' ? 'Assignment' : 'Bid Selection' }}</h2>
                        <span class="hub-tag">{{ $bidAssignment->assignment_source === 'retained_nonbiddable' ? 'Retained assignment' : 'Bid award' }}</span>
                    </div>
                    <dl class="ep-bid-details">
                        <div class="ep-bid-selection">
                            <dt>{{ $bidAssignment->assignment_source === 'retained_nonbiddable' ? 'Assignment' : 'Bid Selection' }}</dt>
                            <dd>{{ $bidAssignment->bid_selection_label }}</dd>
                        </div>
                        <div><dt>{{ $bidAssignment->assignment_source === 'retained_nonbiddable' ? 'Rank' : 'Rank at bid' }}</dt><dd>{{ $bidAssignment->rank_label }}</dd></div>
                        @if($bidAssignment->position_label && $bidAssignment->position_label !== $bidAssignment->bid_selection_label && $bidAssignment->position_label !== $bidAssignment->rank_label)
                            <div><dt>Seat / Position</dt><dd>{{ $bidAssignment->position_label }}</dd></div>
                        @endif
                        <div><dt>Shift</dt><dd>{{ $bidAssignment->shift_label }}</dd></div>
                        <div><dt>Station / Assignment Area</dt><dd>{{ $bidAssignment->station_label }}</dd></div>
                        @if($bidAssignment->division_label)
                            <div><dt>Division</dt><dd>{{ $bidAssignment->division_label }}</dd></div>
                        @endif
                        <div><dt>A-Day</dt><dd>{{ $bidAssignment->a_day_label }}</dd></div>
                        <div><dt>Fuel PIN</dt><dd @class(['ep-fuel-pin' => $fuelPin !== null]) translate="no">{{ $fuelPin ?? 'Not on file' }}</dd></div>
                    </dl>
                </section>
            @else
                <section class="ep-panel" aria-labelledby="my-details-heading">
                    <div class="ep-panel-header">
                        <h2 id="my-details-heading" class="ep-panel-title">My details</h2>
                    </div>
                    <dl class="ep-bid-details">
                        <div><dt>Fuel PIN</dt><dd @class(['ep-fuel-pin' => $fuelPin !== null]) translate="no">{{ $fuelPin ?? 'Not on file' }}</dd></div>
                    </dl>
                </section>
            @endif

            <div class="ep-two-col">
                <section class="ep-panel ep-card" aria-labelledby="recent-equipment-heading">
                    <div class="ep-panel-header">
                        <h2 id="recent-equipment-heading" class="ep-panel-title">Recently assigned</h2>
                        <a href="{{ $equipmentHref }}" class="ep-panel-link">View all<span class="sr-only"> assigned equipment</span><x-heroicon-m-chevron-right class="ep-link-chevron" aria-hidden="true" /></a>
                    </div>
                    @if($recentEquipment->isEmpty())
                        <div class="ep-empty ep-empty--row">
                            <x-heroicon-o-cube class="ep-empty-icon" aria-hidden="true" />
                            <p>Nothing issued to you yet. Gear from Support Services is listed here once it is issued.</p>
                        </div>
                    @else
                        <ul class="ep-rows">
                            @foreach($recentEquipment as $item)
                                <li class="ep-list-item">
                                    <div class="ep-list-body">
                                        <span class="ep-list-primary">{{ $item->item_description }}</span>
                                        <span class="ep-list-secondary">{{ $item->category }}</span>
                                    </div>
                                    <span class="ep-list-meta tabular-nums">{{ $item->issued_at?->format('M j') ?? '—' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="ep-panel ep-card" aria-labelledby="recent-requests-heading">
                    <div class="ep-panel-header">
                        <h2 id="recent-requests-heading" class="ep-panel-title">My requests</h2>
                        <a href="{{ $requestsHref }}" class="ep-panel-link">View all<span class="sr-only"> requests</span><x-heroicon-m-chevron-right class="ep-link-chevron" aria-hidden="true" /></a>
                    </div>
                    @if($recentRequests->isEmpty())
                        <div class="ep-empty ep-empty--row">
                            <x-heroicon-o-clipboard-document-list class="ep-empty-icon" aria-hidden="true" />
                            <p>No requests yet. Uniform requests you send are tracked here.</p>
                            <a href="{{ $uniformsHref }}" class="ep-empty-link">Request uniforms</a>
                        </div>
                    @else
                        <ul class="ep-rows">
                            @foreach($recentRequests as $req)
                                <li class="ep-list-item">
                                    <div class="ep-list-body">
                                        <span class="ep-list-primary line-clamp-1">{{ Str::limit($req->items->pluck('item_name')->join(', '), 45) }}</span>
                                        <span class="ep-list-secondary tabular-nums">{{ $req->created_at->copy()->timezone(config('hub.timezone'))->format('M j, Y') }}</span>
                                    </div>
                                    <span class="{{ $req->status->tagClass() }}">{{ $req->status->label() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-filament-panels::page>