<ul class="hub-panel roster-summary" data-roster-summary>
    <li class="roster-summary__card">
        <p class="roster-summary__label">Shift</p><p class="roster-summary__value">{{ $summary['shift'] }}</p><p class="roster-summary__detail">{{ $summary['date'] }}</p>
    </li>
    <li class="roster-summary__card">
        <p class="roster-summary__label">Sections</p><p class="roster-summary__value">{{ $summary['sections'] }}</p><p class="roster-summary__detail">Units and assignments</p>
    </li>
    <li class="roster-summary__card">
        <p class="roster-summary__label">Entries</p><p class="roster-summary__value">{{ $summary['entries'] }}</p><p class="roster-summary__detail">A member can appear twice</p>
    </li>
    <li class="roster-summary__card">
        <p class="roster-summary__label">Source</p><p class="roster-summary__value">TeleStaff</p><p class="roster-summary__detail">Roster report, version {{ $summary['version'] }}</p>
    </li>
</ul>
