<ul class="roster-summary" data-roster-summary>
    <li class="hub-panel roster-summary__card">
        <span class="roster-summary__icon"><x-heroicon-o-calendar-days aria-hidden="true" /></span>
        <div><p class="roster-summary__label">Shift</p><p class="roster-summary__value">{{ $summary['shift'] }}</p><p class="roster-summary__detail">{{ $summary['date'] }}</p></div>
    </li>
    <li class="hub-panel roster-summary__card">
        <span class="roster-summary__icon"><x-heroicon-o-user-group aria-hidden="true" /></span>
        <div><p class="roster-summary__label">Sections</p><p class="roster-summary__value">{{ $summary['sections'] }}</p><p class="roster-summary__detail">Units and assignments</p></div>
    </li>
    <li class="hub-panel roster-summary__card">
        <span class="roster-summary__icon"><x-heroicon-o-document-text aria-hidden="true" /></span>
        <div><p class="roster-summary__label">Entries</p><p class="roster-summary__value">{{ $summary['entries'] }}</p><p class="roster-summary__detail">A member can appear twice</p></div>
    </li>
    <li class="hub-panel roster-summary__card">
        <span class="roster-summary__icon"><x-heroicon-o-circle-stack aria-hidden="true" /></span>
        <div><p class="roster-summary__label">Source</p><p class="roster-summary__value">TeleStaff</p><p class="roster-summary__detail">Roster report, version {{ $summary['version'] }}</p></div>
    </li>
</ul>
