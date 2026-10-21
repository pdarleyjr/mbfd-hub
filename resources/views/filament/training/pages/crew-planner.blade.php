<x-filament-panels::page>
    <div class="space-y-6 crew-planner">
        <p class="text-sm text-gray-600">Build a separate attendance proposal for Battalion Chief 300. This list records no attendance and changes no course, roster or duty schedule.</p>
        <div wire:offline class="hub-callout" role="status">Requires connection. Your saved proposal remains on the server; reconnect before editing or exporting.</div>
        @error('proposal') <p role="alert" class="text-danger-600">{{ $message }}</p> @enderror
        @if ($draft['version'] !== $version)
            <div class="hub-callout" role="alert">This proposal changed in another tab. Your saved list is safe. <x-filament::button color="gray" wire:click="reloadProposal">Reload proposal</x-filament::button></div>
        @endif

        <x-filament::section heading="1. Course and training scope">
            <form wire:submit="applyScope" class="flex flex-wrap items-end gap-4">
                <label class="min-w-0 flex-1">Find course<input type="search" wire:model.live.debounce.350ms="courseSearch" class="block w-full rounded-lg border-gray-300" /></label>
                <label class="flex-1 min-w-0">Course
                    <select wire:model.live="courseId" class="block w-full rounded-lg border-gray-300">
                        <option value="">Choose an existing course</option>
                        @foreach ($courses as $course) <option value="{{ $course->id }}">{{ $course->name }}</option> @endforeach
                    </select>
                </label>
                @if ($chosen && $chosen->modules->isNotEmpty())
                    <label class="flex-1 min-w-0">Training scope
                        <select wire:model="scope" class="block w-full rounded-lg border-gray-300">
                            <option value="course">Entire course — never attended any session</option>
                            @foreach ($chosen->modules as $module) <option value="{{ $module->id }}">{{ $module->name }} — no verified session attended</option> @endforeach
                        </select>
                    </label>
                @endif
                <x-filament::button type="submit" color="gray">Use this scope</x-filament::button>
            </form>
            @error('courseId') <p role="alert">{{ $message }}</p> @enderror
            @if ($draft['course_id'])
                <p class="mt-3 text-sm">The proposal excludes members with verified attendance in {{ $draft['module_id'] ? 'the selected module' : 'any session of this course' }}. Courses without prior sessions work without creating a class.</p>
                <p class="mt-2 text-sm">Attendance is checked against current verified Hub records{{ !empty($all['as_of']) ? ' as of '.\Carbon\CarbonImmutable::parse($all['as_of'])->setTimezone('America/New_York')->format('M j, Y g:i A T') : '' }}; the uploaded roster supplies the dated staffing snapshot.</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="2. Daily TeleStaff snapshot">
            <form method="POST" action="{{ route('training.crew-planner.upload', [], false) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-4" x-data="{ reading: false }" x-on:submit="reading = true" x-on:pageshow.window="reading = false">
                @csrf
                <input type="hidden" name="draft_id" value="{{ $draftId }}" />
                <input type="hidden" name="version" value="{{ $version }}" />
                <label class="min-w-0 flex-1">Daily PDF (up to 5 MB)
                    <input aria-label="Daily TeleStaff PDF" type="file" name="roster_pdf" accept="application/pdf,.pdf" required class="block w-full" />
                </label>
                <x-filament::button type="submit" x-bind:disabled="reading"><span x-show="!reading">Read daily report</span><span x-cloak x-show="reading">Reading daily report…</span></x-filament::button>
                <x-filament::button type="reset" color="gray" x-bind:disabled="reading">Clear file selection</x-filament::button>
                <p x-cloak x-show="reading" role="status" aria-live="polite" class="w-full font-semibold">Reading daily report. Please wait while the schedule is checked.</p>
            </form>
            @if (session('crew_planner_notice'))<p role="status" class="mt-3">{{ session('crew_planner_notice') }}</p>@endif
            @error('roster_pdf') <p role="alert" class="mt-3 text-danger-600">{{ $message }}</p> @enderror
            @if (! empty($draft['metadata']))
                <div role="status" class="hub-callout mt-3">
                    @if ($errors->has('roster_pdf'))
                        <strong>The previous report remains saved.</strong>
                        <p>The latest upload was not read. The roster details below belong to your previously saved report.</p>
                    @else
                        <strong>Daily report read successfully.</strong>
                        @if (empty($draft['course_id']))
                            <p>Next, choose a course above and select Use this scope to check attendance.</p>
                        @elseif (empty($draft['slots']))
                            <p>Next, <a href="#crew-planner-classroom-times" class="font-semibold underline">save classroom times</a> to show eligible members. The displayed times are suggestions until saved.</p>
                        @else
                            <p>Confirm the roster date and shift, then review eligible members and your planning list below.</p>
                        @endif
                    @endif
                </div>
                <p class="mt-3 font-semibold">Roster date: {{ $draft['metadata']['schedule_date'] ?? 'Unconfirmed' }} · {{ $draft['metadata']['shift'] ?? 'Unconfirmed' }} Shift · {{ $draft['metadata']['page_count'] ?? '?' }} pages</p>
                <p class="text-sm">Snapshot generated: {{ $draft['metadata']['printed_at'] ?? 'Not reported' }}. Scheduled work is based on this uploaded snapshot; verify later staffing changes.</p>
                @foreach ($draft['metadata']['warnings'] ?? [] as $warning) <p role="status" class="mt-2 text-warning-700">{{ is_string($warning) ? $warning : 'The daily snapshot needs verification.' }}</p> @endforeach
                @foreach ($snapshotWarnings as $warning)<p role="status" class="mt-2 text-warning-700">{{ $warning }}</p>@endforeach
            @endif
            <p class="mt-3 text-sm text-gray-600">The source PDF is deleted after reading. The encrypted proposal expires after four hours.</p>
        </x-filament::section>

        <x-filament::section id="crew-planner-classroom-times" heading="3. Classroom times">
            <p class="mb-3 text-sm">These are proposal times for the roster date. Saving them never creates a training session.</p>
            @if (collect($draft['slots'])->contains(fn ($slot) => substr($slot['start_at'], 0, 10) !== ($draft['metadata']['schedule_date'] ?? null)))
                <p role="alert" class="mb-3 font-medium text-warning-700">The new daily report has a different date. Your proposed members remain saved for review. Save classroom times for the new roster date before adding or assigning members.</p>
            @endif
            @if ($draft['slots'])
                <p class="mb-3 text-sm">Saved times: @foreach ($draft['slots'] as $savedSlot){{ $savedSlot['label'] }} {{ \Carbon\CarbonImmutable::parse($savedSlot['start_at'])->format('m/d H:i') }}–{{ \Carbon\CarbonImmutable::parse($savedSlot['end_at'])->format('H:i') }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p>
            @endif
            <form wire:submit="saveSlots" class="space-y-3">
                @foreach ($slotEdits as $index => $slot)
                    <div wire:key="slot-{{ $slot['id'] }}" class="flex flex-wrap items-end gap-3">
                        <label class="min-w-0 flex-1">Classroom label<input wire:model="slotEdits.{{ $index }}.label" maxlength="80" class="block w-full rounded-lg border-gray-300" /></label>
                        <label>Start<input type="time" wire:model="slotEdits.{{ $index }}.start" class="block w-full rounded-lg border-gray-300" /></label>
                        <label>End<input type="time" wire:model="slotEdits.{{ $index }}.end" class="block w-full rounded-lg border-gray-300" /></label>
                        <x-filament::button color="gray" wire:click="removeSlot({{ $index }})">Remove time</x-filament::button>
                        @if ($index > 0)<x-filament::button color="gray" wire:click="reorderSlot({{ $index }}, -1)">Earlier in list</x-filament::button>@endif
                        @if ($index < count($slotEdits) - 1)<x-filament::button color="gray" wire:click="reorderSlot({{ $index }}, 1)">Later in list</x-filament::button>@endif
                    </div>
                    @error("slotEdits.$index.label") <p role="alert">{{ $message }}</p> @enderror
                    @error("slotEdits.$index.start") <p role="alert">{{ $message }}</p> @enderror
                    @error("slotEdits.$index.end") <p role="alert">{{ $message }}</p> @enderror
                @endforeach
                @error('slotEdits') <p role="alert">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-3">
                    <x-filament::button color="gray" wire:click="addSlot">Add classroom time</x-filament::button>
                    @if (! empty($all['session_suggestions'])) <x-filament::button color="gray" wire:click="useSessionSuggestions">Use existing session suggestions</x-filament::button> @endif
                    <x-filament::button type="submit">Save classroom times</x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section heading="4. Find proposed trainees">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label>Search members<input type="search" wire:model.live.debounce.350ms="filters.search" class="block w-full rounded-lg border-gray-300" /></label>
                <label>Classroom availability<select wire:model.live="candidateSlot" class="block w-full rounded-lg border-gray-300"><option value="">Any saved classroom time</option>@foreach ($draft['slots'] as $slot)<option value="{{ $slot['id'] }}">{{ $slot['label'] }}</option>@endforeach</select></label>
                <label>Rank<select wire:model.live="filters.rank" class="block w-full rounded-lg border-gray-300"><option value="">All ranks</option>@foreach (collect($all['rows'] ?? [])->pluck('rank')->filter()->unique()->sort() as $rank)<option value="{{ $rank }}">{{ $rank }}</option>@endforeach</select></label>
                <label>Today's duty station — TeleStaff<select wire:model.live="filters.day_station" class="block w-full rounded-lg border-gray-300"><option value="">All reported stations</option>@foreach (collect($all['rows'] ?? [])->pluck('observed_unit_context')->flatten(1)->pluck('station')->filter()->unique()->sort() as $station)<option value="{{ $station }}">{{ $station }}</option>@endforeach</select></label>
                <label>Today's duty apparatus — TeleStaff<select wire:model.live="filters.day_apparatus" class="block w-full rounded-lg border-gray-300"><option value="">All reported apparatus</option>@foreach (collect($all['rows'] ?? [])->pluck('observed_unit_context')->flatten(1)->pluck('apparatus')->filter()->unique()->sort() as $apparatus)<option value="{{ $apparatus }}">{{ $apparatus }}</option>@endforeach</select></label>
            </div>
            <details class="mt-4">
                <summary class="cursor-pointer font-medium">Published bid preferences and review results</summary>
                <div class="mt-3 flex flex-wrap items-end gap-4">
                    <label>Published bid assignment<select wire:model.live="filters.published_assignment" class="block w-full rounded-lg border-gray-300"><option value="">All published assignments</option>@foreach (collect($all['rows'] ?? [])->pluck('assignment')->filter()->unique()->sort() as $assignment)<option value="{{ $assignment }}">{{ $assignment }}</option>@endforeach</select></label>
                    <label>Published bid category<select wire:model.live="filters.assignment_category" class="block w-full rounded-lg border-gray-300"><option value="">All published categories</option>@foreach (collect($all['rows'] ?? [])->pluck('assignment_category')->filter()->unique()->sort() as $assignment)<option value="{{ $assignment }}">{{ $assignment }}</option>@endforeach</select></label>
                    <label class="flex items-center gap-3"><input type="checkbox" wire:model.live="filters.de_bid" /> Published Driver Engineer bid seat</label>
                    <label class="flex items-center gap-3"><input type="checkbox" wire:model.live="showReview" /> Show members requiring review / already attended</label>
                </div>
            </details>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-filament::button wire:click="addSelected" wire:loading.attr="disabled">Add selected to planning list</x-filament::button>
                <x-filament::button color="gray" wire:click="addSelected(true)" wire:loading.attr="disabled">Add all eligible in these results</x-filament::button>
                <span class="text-sm">{{ count($visible) }} results · changing filters keeps your planning list</span>
            </div>
            <ul class="mt-4 divide-y divide-gray-200">
                @forelse (array_slice($visible, 0, 250, true) as $id => $person)
                    @php($eligible = ! empty($person['eligible_slots']) && ($candidateSlot === '' || in_array($candidateSlot, $person['eligible_slots'], true)))
                    @php($candidateContext = app(\App\Services\Training\CrewPlanner\TrainingCrewPlannerDutyContext::class)->project($person['observed_unit_context'] ?? [], collect($draft['slots'])->firstWhere('id', $candidateSlot)))
                    @php($attendanceLabel = match ($person['attendance_status'] ?? '') { 'VERIFIED_ATTENDED' => 'Already attended this training scope', 'UNVERIFIED_OR_PROVISIONAL' => 'Attendance evidence needs verification', 'NOT_APPLICABLE' => "Outside this module's training audience", 'IDENTITY_OR_EVIDENCE_REVIEW' => 'Identity or attendance evidence needs verification', default => 'Needs training · staffing requires verification' })
                    @php($reviewReasons = $candidateSlot !== '' ? ($person['slot_review_reasons'][$candidateSlot] ?? $person['needs_review'] ?? []) : ($person['needs_review'] ?? []))
                    <li wire:key="candidate-{{ $id }}" class="py-3">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" wire:model="candidateSelection" value="{{ $id }}" @disabled(! $eligible) aria-label="Propose {{ $person['name'] }}" />
                            <span class="min-w-0"><strong>{{ $person['name'] }}</strong> · {{ $person['rank'] ?? '' }} · {{ $person['employee_id'] }}<br />
                                <span class="text-sm">{{ $eligible ? 'Needs training · scheduled for a complete classroom time' : $attendanceLabel }}</span><br />
                                @if (!$eligible)
                                    @forelse ($reviewReasons as $reason)
                                        @if (is_string($reason))<span class="block text-sm">{{ $reason }}</span>@endif
                                    @empty
                                        @if (($person['attendance_status'] ?? '') === 'NO_PRIOR_ATTENDANCE')<span class="block text-sm">Not scheduled for a complete saved classroom time.</span>@endif
                                    @endforelse
                                @endif
                                <span class="text-sm">Published bid: {{ $person['assignment'] ?? 'Not verified' }}{{ !empty($person['is_de_bid']) ? ' · DE seat' : '' }}. Today's duty: {{ $candidateContext['label'] }}.</span>
                            </span>
                        </label>
                    </li>
                @empty
                    <li class="py-4">
                        @if (!empty($draft['metadata']) && !empty($draft['course_id']) && empty($draft['slots']))
                            <a href="#crew-planner-classroom-times" class="font-semibold underline">Save classroom times above to show eligible members.</a> The displayed times have not been saved yet.
                        @else
                            {{ empty($draft['staff']) || empty($draft['slots']) ? 'Choose a course, read a daily report and save classroom times to find candidates.' : 'No eligible members match these preferences. Broaden the filters or inspect review results.' }}
                        @endif
                    </li>
                @endforelse
            </ul>
            @if (count($visible) > 250)<p class="mt-3">Showing the first 250 members. Narrow your search to find a specific person. Add all eligible uses the full result set.</p>@endif
            @if ($showReview && !empty($all['review']))
                <h3 class="mt-4 font-semibold">Unmatched schedule identities — verify before proposing</h3>
                <p class="mt-2 text-sm">These schedule identities have no unique active Hub match. They cannot be selected or included in the proposal automatically.</p>
                <ul class="mt-2 divide-y divide-gray-200">
                    @foreach ($all['review'] as $identity)
                        <li class="py-3"><strong>{{ $identity['raw_name'] ?: 'Name not reported' }}</strong> · Employee ID {{ $identity['raw_employee_id'] ?: 'not reported' }}
                            @foreach ($identity['needs_review'] ?? [] as $reason)@if (is_string($reason))<span class="block text-sm">{{ $reason }}</span>@endif @endforeach
                        </li>
                    @endforeach
                </ul>
            @endif
            @if (!empty($all['review']) || !empty($all['exceptions']))<p class="mt-3 text-warning-700">Some identities or staffing facts require verification and are excluded from automatic selection.</p>@endif
        </x-filament::section>

        <x-filament::section :heading="'Planning list · '.count($draft['selections']).' proposed'">
            <div class="flex flex-wrap items-end gap-3">
                <label class="min-w-0 flex-1">Search proposed members<input type="search" wire:model.live.debounce.350ms="planSearch" class="block w-full rounded-lg border-gray-300" /></label>
                <label>Move selected to<select wire:model="targetSlot" class="block w-full rounded-lg border-gray-300"><option value="">Unassigned</option>@foreach ($draft['slots'] as $slot)<option value="{{ $slot['id'] }}">{{ $slot['label'] }}</option>@endforeach</select></label>
                <x-filament::button color="gray" wire:click="moveSelected">Move selected</x-filament::button>
                <x-filament::button color="gray" wire:click="removeSelected">Remove selected</x-filament::button>
                @if ($draft['removed'])<x-filament::button color="gray" wire:click="undoRemove">Undo remove</x-filament::button>@endif
            </div>
            @error('targetSlot')<p role="alert" class="mt-3">{{ $message }}</p>@enderror
            <p class="my-3 text-sm">@foreach ($draft['slots'] as $slot){{ $slot['label'] }}: {{ count(array_filter($draft['selections'], fn ($row) => $row['slot_id'] === $slot['id'])) }} · @endforeach Unassigned: {{ count(array_filter($draft['selections'], fn ($row) => $row['slot_id'] === null || !in_array($row['slot_id'], array_column($draft['slots'], 'id'), true))) }}</p>
            <ul class="divide-y divide-gray-200">
                @forelse ($draft['selections'] as $id => $selection)
                    @php($person = $all['rows'][$id] ?? null)
                    @continue($planSearch !== '' && !str_contains(mb_strtolower(($person['name'] ?? '').' '.($person['employee_id'] ?? '')), mb_strtolower($planSearch)))
                    @php($requiresReview = !$person || empty($person['eligible_slots']) || ($selection['slot_id'] !== null && !in_array($selection['slot_id'], $person['eligible_slots'] ?? [], true)))
                    @php($plannedContext = app(\App\Services\Training\CrewPlanner\TrainingCrewPlannerDutyContext::class)->project($person['observed_unit_context'] ?? [], collect($draft['slots'])->firstWhere('id', $selection['slot_id'])))
                    <li class="py-3" wire:key="planned-{{ $id }}"><label class="flex items-start gap-3"><input type="checkbox" wire:model="planSelection" value="{{ $id }}" aria-label="Select proposed member {{ $person['name'] ?? $id }}" /><span class="min-w-0"><strong>{{ $person['name'] ?? 'Previously selected member — identity needs review' }}</strong> · {{ $person['rank'] ?? '' }} · {{ $person['employee_id'] ?? '' }}<br /><span>{{ collect($draft['slots'])->firstWhere('id', $selection['slot_id'])['label'] ?? 'Unassigned' }}</span>
                        @if ($requiresReview)<span class="block font-medium text-warning-700">Requires verification — timing, scope or source evidence changed. Kept for your review.</span>@endif
                        @if($person)<span class="block text-sm">Today's duty: {{ $plannedContext['label'] }}. Duty coverage: {{ collect($person['working_intervals'] ?? [])->map(fn ($interval) => \Carbon\CarbonImmutable::parse($interval['start_at'])->format('m/d H:i').'–'.\Carbon\CarbonImmutable::parse($interval['end_at'])->format('m/d H:i'))->implode(', ') }}.</span>@endif
                    </span></label><x-filament::button class="mt-2" color="gray" wire:click="removeMember({{ $id }})">Remove from proposal</x-filament::button></li>
                @empty <li class="py-4">Add eligible members above. You can change filters and add another group without losing earlier selections.</li> @endforelse
            </ul>
            <label class="my-4 flex items-center gap-3"><input type="checkbox" wire:model="confirmReview" /> I reviewed any verification flags; include them separately for Battalion 300 review.</label>
            @error('confirmReview')<p role="alert" class="mb-3">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-3">
                <x-filament::button wire:click="download('pdf')" wire:loading.attr="disabled">Download proposal PDF</x-filament::button>
                <x-filament::button color="gray" wire:click="download('xlsx')" wire:loading.attr="disabled">Download proposal Excel</x-filament::button>
                <x-filament::button color="gray" wire:click="reloadProposal">Refresh attendance and staffing check</x-filament::button>
                <x-filament::button color="danger" wire:click="discardProposal" wire:confirm="Discard this independent proposal? Courses and attendance records will remain unchanged.">Discard proposal</x-filament::button>
            </div>
            <p class="mt-3 text-sm text-gray-600">For Battalion Chief 300 review — proposal only. No automatic notifications or roster submission.</p>
        </x-filament::section>
    </div>
        <style>
            .crew-planner input:not([type=checkbox]), .crew-planner select, .crew-planner button, .crew-planner summary { min-height: 44px !important; }
            .crew-planner input[type=checkbox] { width: 24px; height: 24px; flex-shrink: 0; }
            .crew-planner label:has(input[type=checkbox]) { min-height: 44px; padding-block: 10px; }
            .crew-planner label.flex-1 { flex-basis: 12rem; min-width: min(12rem, 100%) !important; }
            @media (max-width: 640px) { .crew-planner label.flex-1 { flex-basis: 100%; } }
            .crew-planner { overflow-wrap: anywhere; }
        </style>
</x-filament-panels::page>
