{{-- The member's recent activity: needs-a-reply first, then new updates, then open items. $items: list<MemberActivityItem> --}}
<section class="ep-panel act-card" aria-labelledby="my-activity-heading" data-my-activity>
    <div class="ep-panel-header">
        <h2 id="my-activity-heading" class="ep-panel-title">My activity</h2>
        <a href="{{ \App\Filament\Employee\Pages\MyActivity::getUrl(panel: 'employee') }}" class="ep-panel-link">View all activity →</a>
    </div>
    @if($items === [])
        <div class="ep-empty">
            <p>Nothing yet. Inspections, requests and messages you send show up here.</p>
        </div>
    @else
        <ul class="act-list">
            @foreach($items as $item)
                @include('filament.employee.partials.activity-row', ['item' => $item])
            @endforeach
        </ul>
    @endif
</section>
