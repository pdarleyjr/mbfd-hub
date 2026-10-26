<x-filament-panels::page>
    @php
        $metadata = $draft['metadata'];
        $hasRoster = ! empty($metadata);
        $hasCourse = ! empty($draft['course_id']);
        $slotCount = count($draft['slots']);
        $selectedCount = count($draft['selections']);
        $shiftText = ! empty($metadata['shift']) ? (preg_match('/shift$/i', (string) $metadata['shift']) ? $metadata['shift'] : $metadata['shift'].' Shift') : 'Shift unconfirmed';
        $staleSlots = collect($draft['slots'])->contains(fn ($slot) => substr($slot['start_at'], 0, 10) !== ($metadata['schedule_date'] ?? null));
        $shownCount = count($candidates);
        $searching = trim((string) ($filters['search'] ?? '')) !== '';
        $filtersActive = collect(['rank', 'day_station', 'day_apparatus', 'assignment_category', 'published_assignment'])->contains(fn ($key) => trim((string) ($filters[$key] ?? '')) !== '') || ($filters['de_bid'] ?? false);
        $moreFiltersActive = collect(['rank', 'assignment_category', 'published_assignment'])->contains(fn ($key) => trim((string) ($filters[$key] ?? '')) !== '') || ($filters['de_bid'] ?? false);
    @endphp
    <div class="space-y-6 crew-planner">
        <p class="text-sm text-gray-600">Choose the training, read the daily roster, set the class time, then pick who attends and export the request. Nothing here records attendance or changes any course, roster or duty schedule.</p>
        <div wire:offline class="hub-callout" role="status">Requires connection. Your saved proposal remains on the server; reconnect before editing or exporting.</div>
        @error('proposal') <p role="alert" class="text-danger-600">{{ $message }}</p> @enderror
        @if ($draft['version'] !== $version)
            <div class="hub-callout" role="alert">This proposal changed in another tab. Your saved list is safe. <x-filament::button color="gray" wire:click="reloadProposal">Reload proposal</x-filament::button></div>
        @endif

        <x-filament::section>
            <x-slot name="heading">1. Training</x-slot>
            <x-slot name="headerEnd">@if ($hasCourse)<x-filament::badge color="success">Chosen</x-filament::badge>@endif</x-slot>
            <div class="flex flex-wrap items-end gap-4">
                @if ($showCourseSearch)
                    <label class="min-w-0 flex-1">Find a course<input type="search" wire:model.live.debounce.350ms="courseSearch" class="block w-full rounded-lg border-gray-300" /></label>
                @endif
                <label class="flex-1 min-w-0">Course
                    <select wire:model.live="courseId" class="block w-full rounded-lg border-gray-300">
                        <option value="">Choose a course</option>
                        @foreach ($courses as $course) <option value="{{ $course->id }}">{{ $course->name }}</option> @endforeach
                    </select>
                </label>
                @if ($chosen && $chosen->modules->isNotEmpty())
                    <label class="flex-1 min-w-0">Module
                        <select wire:model.live="scope" class="block w-full rounded-lg border-gray-300">
                            <option value="course">Entire course</option>
                            @foreach ($chosen->modules as $module) <option value="{{ $module->id }}">{{ $module->name }}</option> @endforeach
                        </select>
                    </label>
                @endif
            </div>
            @error('courseId') <p role="alert" class="mt-2 text-danger-600">{{ $message }}</p> @enderror
            @if ($hasCourse)
                <p class="mt-3 text-sm" role="status">Leaving out members who already attended {{ $draft['module_id'] ? 'this module' : 'any session of this course' }} (verified records only). Courses with no earlier sessions work without creating a class.</p>
                <p class="mt-1 text-sm">
                    @if ($cutoffIsClassStart)
                        Attendance is checked as it stood when class begins ({{ $cutoff->setTimezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A T') }}).
                    @else
                        Attendance is checked against current verified Hub records as of {{ $cutoff->setTimezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A T') }}.
                    @endif
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">2. Daily roster</x-slot>
            <x-slot name="headerEnd">@if ($hasRoster)<x-filament::badge color="success">Read</x-filament::badge>@endif</x-slot>
            <form method="POST" action="{{ route('training.crew-planner.upload', [], false) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-4" x-data="{ reading: false }" x-on:submit="reading = true" x-on:pageshow.window="reading = false">
                @csrf
                <input type="hidden" name="draft_id" value="{{ $draftId }}" />
                <input type="hidden" name="version" value="{{ $version }}" />
                <label class="min-w-0 flex-1">Daily TeleStaff PDF (up to 5 MB)
                    <input aria-label="Daily TeleStaff PDF" type="file" name="roster_pdf" accept="application/pdf,.pdf" required class="block w-full" />
                </label>
                <x-filament::button type="submit" x-bind:disabled="reading"><span x-show="!reading">Read daily report</span><span x-cloak x-show="reading">Reading daily report…</span></x-filament::button>
                <x-filament::button type="reset" color="gray" x-bind:disabled="reading">Clear file selection</x-filament::button>
                <p x-cloak x-show="reading" role="status" aria-live="polite" class="w-full font-semibold">Reading daily report. Please wait while the schedule is checked.</p>
            </form>
            @error('roster_pdf') <p role="alert" class="mt-3 text-danger-600">{{ $message }}</p> @enderror
            @if ($hasRoster)
                <div role="status" class="hub-callout mt-3">
                    @if ($errors->has('roster_pdf'))
                        <strong>The previous report remains saved.</strong>
                        <p>The latest upload was not read. The roster details below belong to your previously saved report.</p>
                    @else
                        <strong>Daily report read successfully.</strong>
                        <p>
                            @if (! $hasCourse)
                                Next, choose the training in step 1.
                            @elseif ($slotCount === 0)
                                Next, enter the class time in step 3.
                            @else
                                Next, pick members in step 4.
                            @endif
                        </p>
                    @endif
                </div>
                <p class="mt-3 font-semibold">Roster date: {{ $metadata['schedule_date'] ?? 'Unconfirmed' }}{{ $rosterDay ? ' ('.$rosterDay.')' : '' }} · {{ $shiftText }} · {{ $metadata['page_count'] ?? '?' }} {{ ($metadata['page_count'] ?? 0) === 1 ? 'page' : 'pages' }}</p>
                <p class="text-sm">Snapshot generated: {{ $metadata['printed_at'] ?? 'Not reported' }}. Scheduled work is based on this uploaded snapshot; verify later staffing changes.</p>
                @foreach ($metadata['warnings'] ?? [] as $warning) <p role="status" class="mt-2 text-warning-700">{{ is_string($warning) ? $warning : 'The daily snapshot needs verification.' }}</p> @endforeach
                @foreach ($snapshotWarnings as $warning)<p role="status" class="mt-2 text-warning-700">{{ $warning }}</p>@endforeach
            @else
                <p class="mt-3 text-sm">Upload the daily TeleStaff report for the class day. You can do this before or after choosing the training.</p>
            @endif
            <p class="mt-3 text-sm text-gray-600">The source PDF is deleted after reading. The encrypted proposal expires after four hours.</p>
        </x-filament::section>

        <x-filament::section id="crew-planner-classroom-times">
            <x-slot name="heading">3. Class sessions</x-slot>
            <x-slot name="headerEnd">@if ($slotCount > 0 && ! $staleSlots)<x-filament::badge color="success">Saved</x-filament::badge>@endif</x-slot>
            <p class="mb-3 text-sm">Enter the actual class time. Sessions save automatically once both times are set. Saving them never creates a training session.</p>
            @if (! $hasRoster)
                <p class="mb-3 text-sm text-warning-700" role="status">Read the daily report first (step 2). Session times are saved against its date.</p>
            @endif
            @if ($staleSlots)
                <div role="alert" class="hub-callout mb-3">
                    <p class="font-medium text-warning-700">The new daily report has a different date. Your selected members are safe.</p>
                    <x-filament::button class="mt-2" wire:click="saveSlots">Apply these times to {{ $metadata['schedule_date'] ?? 'the new date' }}</x-filament::button>
                </div>
            @endif
            <div class="space-y-3">
                @foreach ($slotEdits as $index => $slot)
                    <div wire:key="slot-{{ $slot['id'] }}" class="flex flex-wrap items-end gap-3">
                        <label class="flex-1 min-w-0">Name (optional)<input wire:model.blur="slotEdits.{{ $index }}.label" maxlength="80" placeholder="Session {{ $index + 1 }}" aria-label="Session {{ $index + 1 }} name" class="block w-full rounded-lg border-gray-300" /></label>
                        <label>Start<input type="time" wire:model.live.debounce.600ms="slotEdits.{{ $index }}.start" aria-label="Session {{ $index + 1 }} start time" class="block w-full rounded-lg border-gray-300" /></label>
                        <label>End<input type="time" wire:model.live.debounce.600ms="slotEdits.{{ $index }}.end" aria-label="Session {{ $index + 1 }} end time" class="block w-full rounded-lg border-gray-300" /></label>
                        @if (count($slotEdits) > 1)<x-filament::button color="gray" wire:click="removeSlot({{ $index }})" aria-label="Remove session {{ $index + 1 }}">Remove</x-filament::button>@endif
                    </div>
                    @error("slotEdits.$index.label") <p role="alert" class="text-danger-600">{{ $message }}</p> @enderror
                    @error("slotEdits.$index.start") <p role="alert" class="text-danger-600">{{ $message }}</p> @enderror
                    @error("slotEdits.$index.end") <p role="alert" class="text-danger-600">{{ $message }}</p> @enderror
                @endforeach
                @error('slotEdits') <p role="alert" class="text-danger-600">{{ $message }}</p> @enderror
                @if (count($slotEdits) < 12)
                    <x-filament::button color="gray" wire:click="addSlot">Add another session</x-filament::button>
                @endif
            </div>
            <p class="mt-3 text-sm" role="status" aria-live="polite">
                @if ($slotCount > 0)
                    Saved: @foreach ($slotLabels as $savedSlot){{ $savedSlot['label'] }} {{ $savedSlot['time'] }}{{ ! $loop->last ? ' · ' : '' }}@endforeach
                @else
                    Not saved yet. Enter a start and an end time.
                @endif
            </p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">4. Eligible members</x-slot>
            <x-slot name="headerEnd"><x-filament::badge>{{ $selectedCount }} selected</x-filament::badge></x-slot>
            @if (! $hasRoster || ! $hasCourse || ! $sessionsReady)
                <p>
                    @if (! $hasRoster) Read the daily report in step 2 to see who is on duty.
                    @elseif (! $hasCourse) Choose the training in step 1 to check attendance.
                    @elseif ($staleSlots) Apply the session times to the new roster date in step 3.
                    @else Enter the class start and end time in step 3 to see eligible members.
                    @endif
                </p>
            @else
                <p class="font-semibold" role="status" aria-live="polite">{{ $eligibleTotal }} eligible · {{ $selectedCount }} selected{{ ($filtersActive || $searching) ? ' · '.$shownCount.' shown' : '' }}</p>
                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label>Find a member<input type="search" wire:model.live.debounce.350ms="filters.search" placeholder="Name or employee ID" class="block w-full rounded-lg border-gray-300" /></label>
                    <label>Station<select wire:model.live="filters.day_station" class="block w-full rounded-lg border-gray-300"><option value="">All stations</option>@foreach ($stations as $station)<option value="{{ $station }}">{{ $station }}</option>@endforeach</select></label>
                    <label>Unit<select wire:model.live="filters.day_apparatus" class="block w-full rounded-lg border-gray-300"><option value="">All units</option>@foreach ($units as $unit)<option value="{{ $unit }}">{{ $unit }}</option>@endforeach</select></label>
                </div>
                <details class="mt-3" @if ($moreFiltersActive) open @endif>
                    <summary class="cursor-pointer font-medium">More filters</summary>
                    <div class="mt-3 flex flex-wrap items-end gap-4">
                        <label>Rank<select wire:model.live="filters.rank" class="block w-full rounded-lg border-gray-300"><option value="">All ranks</option>@foreach ($ranks as $rank)<option value="{{ $rank }}">{{ $rank }}</option>@endforeach</select></label>
                        <label>Published bid assignment<select wire:model.live="filters.published_assignment" class="block w-full rounded-lg border-gray-300"><option value="">All published assignments</option>@foreach ($assignments as $assignment)<option value="{{ $assignment }}">{{ $assignment }}</option>@endforeach</select></label>
                        <label>Published bid category<select wire:model.live="filters.assignment_category" class="block w-full rounded-lg border-gray-300"><option value="">All published categories</option>@foreach ($categories as $category)<option value="{{ $category }}">{{ $category }}</option>@endforeach</select></label>
                        <label class="flex items-center gap-3"><input type="checkbox" wire:model.live="filters.de_bid" /> Published Driver Engineer bid seat</label>
                    </div>
                </details>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    @if ($shownCount > 0)<x-filament::button color="gray" wire:click="selectAllShown" wire:loading.attr="disabled">Select all {{ $shownCount }} shown</x-filament::button>@endif
                    @if ($selectedCount > 0)<x-filament::button color="gray" wire:click="clearSelection" wire:loading.attr="disabled">Clear selection</x-filament::button>@endif
                    @if ($draft['removed'])<x-filament::button color="gray" wire:click="undoRemove">Undo remove</x-filament::button>@endif
                </div>
                @error('selection') <p role="alert" class="mt-3 text-danger-600">{{ $message }}</p> @enderror
                <ul class="mt-4 divide-y divide-gray-200">
                    @forelse (array_slice($candidates, 0, 250, true) as $id => $person)
                        <li wire:key="candidate-{{ $id }}" class="cp-row py-3">
                            <label class="cp-pick">
                                <input type="checkbox" wire:click="toggleMember({{ $id }})" @checked($person['selected']) aria-label="Select {{ $person['name'] }}" />
                                <span class="min-w-0"><strong>{{ $person['name'] }}</strong><br /><span class="text-sm">{{ $person['rank'] }} · ID {{ $person['employee_id'] }}</span></span>
                            </label>
                            <span class="cp-duty text-sm">{{ $person['station'] }} · {{ $person['unit'] }}</span>
                            <span class="cp-time text-sm">On duty {{ $person['duty'] }}</span>
                            @if ($person['partial'])
                                <span class="cp-note text-sm">Eligible for {{ collect($person['slot_options'])->pluck('label')->implode(', ') }} only</span>
                            @endif
                            @if ($person['selected'] && count($slotLabels) > 1)
                                <label class="cp-session text-sm">Session
                                    <select wire:change="setMemberSlot({{ $id }}, $event.target.value)" aria-label="Session for {{ $person['name'] }}" class="block w-full rounded-lg border-gray-300">
                                        <option value="" @selected($person['slot_id'] === null)>Choose session</option>
                                        @foreach ($person['slot_options'] as $option)<option value="{{ $option['id'] }}" @selected($person['slot_id'] === $option['id'])>{{ $option['label'] }} {{ $option['time'] }}</option>@endforeach
                                    </select>
                                </label>
                            @endif
                        </li>
                    @empty
                        <li class="py-4">{{ ($filtersActive || $searching) ? 'No eligible members match. Clear a filter, or open "Why isn\'t someone listed?" below.' : 'No one on this report is eligible for the whole session. Check the times, or open "Why isn\'t someone listed?" below.' }}</li>
                    @endforelse
                </ul>
                @if ($shownCount > 250)<p class="mt-3 text-sm">Showing the first 250 members. Search to find a specific person. Select all applies to every member shown.</p>@endif
            @endif
            @if ($hasRoster && $hasCourse)
                <details class="mt-4" @if ($searching && ($notListed !== [] || $unmatched !== [])) open @endif>
                    <summary class="cursor-pointer font-medium">Why isn't someone listed?</summary>
                    <ul class="mt-2 divide-y divide-gray-200">
                        @foreach ($notListed as $other)
                            <li class="py-3 text-sm"><strong>{{ $other['name'] }}</strong> · {{ $other['rank'] }} · ID {{ $other['employee_id'] }}<span class="block">{{ $other['reason'] }}</span></li>
                        @endforeach
                        @foreach ($unmatched as $other)
                            <li class="py-3 text-sm"><strong>{{ $other['name'] }}</strong> · ID {{ $other['employee_id'] !== '' ? $other['employee_id'] : 'not reported' }}<span class="block">{{ $other['reason'] }}</span></li>
                        @endforeach
                        @if ($notListed === [] && $unmatched === [])
                            <li class="py-3 text-sm">{{ $searching ? 'No one on this report matches that search.' : 'Everyone on the report is listed above, or needs a saved session time to be checked.' }}</li>
                        @endif
                    </ul>
                </details>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">5. Review and export</x-slot>
            <x-slot name="headerEnd"><x-filament::badge>{{ $selectedCount }} selected</x-filament::badge></x-slot>
            @if ($selectedCount === 0)
                <p>Select members in step 4. They appear here grouped by session.</p>
            @endif
            @foreach ($review['groups'] as $groupId => $group)
                @if ($group['members'] !== [])
                    <section class="mt-3" aria-labelledby="review-{{ $groupId }}">
                        <h3 id="review-{{ $groupId }}" class="font-semibold">{{ $group['label'] }} — {{ $group['time'] }} <span class="font-normal">({{ count($group['members']) }})</span></h3>
                        <ul class="divide-y divide-gray-200">
                            @foreach ($group['members'] as $member)
                                <li wire:key="review-{{ $groupId }}-{{ $member['id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <span class="min-w-0"><strong>{{ $member['name'] }}</strong> · {{ $member['rank'] }} · ID {{ $member['employee_id'] }}<span class="block">{{ $member['place'] }}</span></span>
                                    <x-filament::button color="gray" size="sm" wire:click="removeMember({{ $member['id'] }})" aria-label="Remove {{ $member['name'] }}">Remove</x-filament::button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach
            @if ($review['unassigned'] !== [])
                <section class="mt-3" aria-labelledby="review-unassigned">
                    <h3 id="review-unassigned" class="font-semibold">No session chosen yet <span class="font-normal">({{ count($review['unassigned']) }})</span></h3>
                    <p class="text-sm">Choose a session for each person in step 4. Until then they are listed as unassigned.</p>
                    <ul class="divide-y divide-gray-200">
                        @foreach ($review['unassigned'] as $member)
                            <li wire:key="review-unassigned-{{ $member['id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span class="min-w-0"><strong>{{ $member['name'] }}</strong> · {{ $member['rank'] }} · ID {{ $member['employee_id'] }}</span>
                                <x-filament::button color="gray" size="sm" wire:click="removeMember({{ $member['id'] }})" aria-label="Remove {{ $member['name'] }}">Remove</x-filament::button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @if ($review['verify'] !== [])
                <section class="mt-3 hub-callout" aria-labelledby="review-verify">
                    <h3 id="review-verify" class="font-semibold text-warning-700">Needs verification <span class="font-normal">({{ count($review['verify']) }})</span></h3>
                    <ul class="divide-y divide-gray-200">
                        @foreach ($review['verify'] as $member)
                            <li wire:key="review-verify-{{ $member['id'] }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span class="min-w-0"><strong>{{ $member['name'] }}</strong> · {{ $member['rank'] }} · ID {{ $member['employee_id'] }}<span class="block">{{ $member['reason'] }}</span></span>
                                <x-filament::button color="gray" size="sm" wire:click="removeMember({{ $member['id'] }})" aria-label="Remove {{ $member['name'] }}">Remove</x-filament::button>
                            </li>
                        @endforeach
                    </ul>
                    <label class="mt-3 flex items-center gap-3"><input type="checkbox" wire:model="confirmReview" /> I will recheck these members; include them, marked as needing verification, in the export.</label>
                </section>
            @endif
            @error('confirmReview')<p role="alert" class="mt-3 text-danger-600">{{ $message }}</p>@enderror
            @error('slotEdits')<p role="alert" class="mt-3 text-danger-600">{{ $message }}</p>@enderror
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-filament::button wire:click="download('pdf')" wire:loading.attr="disabled" :disabled="$selectedCount === 0">Export Training Personnel PDF</x-filament::button>
                <x-filament::button color="gray" wire:click="download('xlsx')" wire:loading.attr="disabled" :disabled="$selectedCount === 0">Download Excel</x-filament::button>
                <x-filament::button color="gray" wire:click="reloadProposal">Refresh check</x-filament::button>
                <x-filament::button color="danger" outlined wire:click="discardProposal" wire:confirm="Start over? This clears your selections and roster. Courses and attendance records stay unchanged.">Start over</x-filament::button>
            </div>
            <p class="mt-3 text-sm text-gray-600">Proposal only. Nothing is sent, recorded or changed automatically.</p>
        </x-filament::section>
    </div>
        <style>
            .crew-planner input:not([type=checkbox]), .crew-planner select, .crew-planner button, .crew-planner summary { min-height: 44px !important; }
            .crew-planner input[type=checkbox] { width: 24px; height: 24px; flex-shrink: 0; }
            .crew-planner label:has(input[type=checkbox]) { min-height: 44px; padding-block: 10px; }
            .crew-planner label.flex-1 { flex-basis: 12rem; min-width: min(12rem, 100%) !important; }
            @media (max-width: 640px) { .crew-planner label.flex-1 { flex-basis: 100%; } }
            .crew-planner { overflow-wrap: anywhere; }
            .crew-planner .cp-row { display: grid; gap: 0.5rem 1rem; grid-template-columns: minmax(0, 1fr); align-items: center; }
            .crew-planner .cp-pick { display: flex; align-items: center; gap: 0.75rem; cursor: pointer; }
            .crew-planner .cp-note { color: rgb(180 83 9); }
            @media (min-width: 900px) {
                .crew-planner .cp-row { grid-template-columns: minmax(14rem, 1.4fr) minmax(10rem, 1fr) minmax(8rem, 0.8fr); }
                .crew-planner .cp-note, .crew-planner .cp-session { grid-column: 1 / -1; }
            }
        </style>
</x-filament-panels::page>
