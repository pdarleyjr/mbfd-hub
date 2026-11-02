<x-filament-panels::page>
    @php
        $slotCount = count($draft['slots']);
        $selectedCount = count($draft['selections']);
        $shownCount = count($candidates);
        $searching = trim((string) ($filters['search'] ?? '')) !== '';
        $filtersActive = $ranks !== [];
        $tz = \App\Support\HubTimezone::NAME;
        $dateLabel = \Carbon\CarbonImmutable::parse($duty->dutyDate, $tz)->format('l, F j, Y');
        $dateShort = \Carbon\CarbonImmutable::parse($duty->dutyDate, $tz)->format('l, M j');
        $classLabel = $chosen ? ($draft['module_id'] ? ($chosen->modules->firstWhere('id', $draft['module_id'])?->name ?? 'this module') : $chosen->name) : trim((string) ($draft['class_title'] ?? ''));
        $sessionsOpen = $slotCount > 0 || collect($slotEdits)->contains(fn ($row) => collect(['label', 'start', 'end', 'location_choice', 'venue'])->contains(fn ($field) => ($row[$field] ?? '') !== ''));
        $latestPublished = $publications->first()?->fingerprint;
        $latest = $publications->where('fingerprint', $latestPublished);
        $hasFailed = $latest->contains(fn ($row) => $row->needsRetry());
        $destinationLabels = collect($targets)->pluck('label', 'id');
        $failureText = ['not_permitted' => 'Your account cannot post there.', 'rejected' => 'It was not accepted.', 'send_failed' => 'It could not be sent.'];
    @endphp
    <div class="space-y-6 crew-planner" wire:poll.120s.visible="refreshRoster">
        <p class="text-sm text-gray-600">Pick a day and a class. The list shows who is on the TeleStaff schedule that day and has not completed it.</p>
        <div wire:offline class="hub-callout" role="status">Requires connection. Your saved proposal remains on the server; reconnect before editing or sending.</div>
        @error('proposal') <p role="alert" class="text-danger-600">{{ $message }}</p> @enderror
        @if ($draft['version'] !== $version)
            <div class="hub-callout" role="alert">This proposal changed in another tab. Your saved list is safe. <x-filament::button color="gray" wire:click="reloadProposal">Reload proposal</x-filament::button></div>
        @endif
        @if ($rosterChange['material'])
            <div class="hub-callout hub-callout--warning" role="alert" data-roster-changed>
                <strong>The roster changed for members on this plan.</strong>
                <p>A newer TeleStaff roster{{ $duty->asOf ? ' (as of '.$duty->asOf->setTimezone($tz)->format('M j g:i A').')' : '' }} changes the duty of: {{ implode(', ', $rosterChange['members']) ?: 'members on this plan' }}. Your list is safe. Check them below, then continue.</p>
                <x-filament::button class="mt-2" wire:click="acknowledgeRoster">I've reviewed the changes</x-filament::button>
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">1. Day and class</x-slot>
            <div class="flex flex-wrap items-end gap-4">
                <label class="flex-1 min-w-0">Day
                    <select wire:model.live="scheduleDate" class="block w-full rounded-lg border-gray-300" data-crew-date>
                        @unless (collect($dates)->contains('date', $scheduleDate))<option value="{{ $scheduleDate }}" disabled>Choose a day</option>@endunless
                        @foreach ($dates as $choice) <option value="{{ $choice['date'] }}">{{ $choice['label'] }}</option> @endforeach
                    </select>
                </label>
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
            @error('scheduleDate') <p role="alert" class="mt-2 text-danger-600">{{ $message }}</p> @enderror

            @if ($dates === [])
                <div class="hub-callout mt-3" role="status" data-roster-source="unavailable">
                    <strong>No TeleStaff roster on file yet.</strong>
                    <p>{{ $duty->unavailableReason }} Days appear here as soon as a roster arrives.</p>
                </div>
            @elseif ($duty->isVerified())
                <p class="mt-3 font-semibold" role="status" data-roster-source="verified">{{ $dateLabel }} · {{ $duty->shiftLabel() }}</p>
                @if ($duty->warning)<p role="status" class="mt-2 text-warning-700">{{ $duty->warning }}</p>@endif
            @else
                <div class="hub-callout mt-3" role="status" data-roster-source="unavailable">
                    <strong>{{ $dateLabel }}</strong>
                    <p>{{ $duty->unavailableReason }}</p>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">2. Who still needs it</x-slot>
            <x-slot name="headerEnd"><x-filament::badge>{{ $selectedCount }} selected</x-filament::badge></x-slot>
            @if (! $hasClass)
                <p>Choose a course in step 1.</p>
            @else
                <p class="font-semibold" role="status" aria-live="polite" data-crew-summary>{{ $filtersActive || $searching ? $shownCount.' of '.$eligibleTotal : $eligibleTotal }} on duty {{ $dateShort }} {{ $eligibleTotal === 1 && ! ($filtersActive || $searching) ? 'has' : 'have' }} not completed {{ $classLabel }}</p>
                @if ($rankChoices !== [])
                    <fieldset class="mt-3">
                        <legend class="font-medium">Rank <span class="font-normal text-sm">(tick any; none ticked shows everyone)</span></legend>
                        <div class="mt-1 cp-ranks">
                            @foreach ($rankChoices as $choice)
                                <label class="flex items-center gap-2" wire:key="rank-{{ md5($choice['value']) }}"><input type="checkbox" wire:click="toggleRank(@js($choice['value']))" @checked(in_array($choice['value'], $ranks, true)) /> {{ $choice['label'] }} ({{ $choice['count'] }})</label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                <label class="mt-3 block cp-search">Find a name<input type="search" wire:model.live.debounce.350ms="filters.search" placeholder="Name or employee ID" class="block w-full rounded-lg border-gray-300" /></label>
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
                            @unless ($person['provisional'])
                                <span class="cp-duty text-sm">{{ $person['station'] }} · {{ $person['unit'] }}</span>
                            @endunless
                            @if ($person['partial'] && $slotCount > 0)
                                <span class="cp-note text-sm">Eligible for {{ collect($person['slot_options'])->pluck('label')->implode(', ') }} only</span>
                            @endif
                            @if ($person['selected'] && $slotCount > 1)
                                <label class="cp-session text-sm">Session
                                    <select wire:change="setMemberSlot({{ $id }}, $event.target.value)" aria-label="Session for {{ $person['name'] }}" class="block w-full rounded-lg border-gray-300">
                                        <option value="" @selected($person['slot_id'] === null)>Choose session</option>
                                        @foreach ($person['slot_options'] as $option)<option value="{{ $option['id'] }}" @selected($person['slot_id'] === $option['id'])>{{ $option['label'] }} {{ $option['time'] }}</option>@endforeach
                                    </select>
                                </label>
                            @endif
                        </li>
                    @empty
                        <li class="py-4">{{ ($filtersActive || $searching) ? 'No one matches. Untick a rank or clear the name.' : 'Everyone on duty that day has completed it.' }}</li>
                    @endforelse
                </ul>
                @if ($shownCount > 250)<p class="mt-3 text-sm">Showing the first 250. Tick a rank or search for a name to narrow it.</p>@endif
            @endif
        </x-filament::section>

        <x-filament::section id="crew-planner-classroom-times">
            <x-slot name="heading">3. Sessions (optional)</x-slot>
            <x-slot name="headerEnd">@if ($slotCount > 0)<x-filament::badge color="success">{{ $slotCount }} saved</x-filament::badge>@endif</x-slot>
            <p class="text-sm">Skip this to send one simple list. Add sessions for separate times or places; each saves automatically once both times are set. Saving never creates a training session.</p>
            <details class="mt-3" wire:ignore.self @if ($sessionsOpen) open @endif>
                <summary class="cursor-pointer font-medium">{{ $slotCount > 0 ? 'Edit sessions' : 'Add sessions' }}</summary>
                <div class="mt-3 space-y-4">
                    @foreach ($slotEdits as $index => $slot)
                        <div wire:key="slot-{{ $slot['id'] }}" class="cp-session-row">
                            <label class="flex-1 min-w-0">Name (optional)<input wire:model.blur="slotEdits.{{ $index }}.label" maxlength="80" placeholder="Session {{ $index + 1 }}" aria-label="Session {{ $index + 1 }} name" class="block w-full rounded-lg border-gray-300" /></label>
                            <label>Start<input type="time" wire:model.live.debounce.600ms="slotEdits.{{ $index }}.start" aria-label="Session {{ $index + 1 }} start time" class="block w-full rounded-lg border-gray-300" /></label>
                            <label>End<input type="time" wire:model.live.debounce.600ms="slotEdits.{{ $index }}.end" aria-label="Session {{ $index + 1 }} end time" class="block w-full rounded-lg border-gray-300" /></label>
                            <label class="flex-1 min-w-0">Location
                                <select wire:model.live="slotEdits.{{ $index }}.location_choice" aria-label="Session {{ $index + 1 }} location" class="block w-full rounded-lg border-gray-300">
                                    <option value="">Not set</option>
                                    @foreach ($stationsList as $station)<option value="station:{{ $station->id }}">{{ $station->name }}</option>@endforeach
                                    <option value="other">Other location</option>
                                </select>
                            </label>
                            @if (($slot['location_choice'] ?? '') === 'other')
                                <label class="flex-1 min-w-0">Where<input wire:model.blur="slotEdits.{{ $index }}.venue" maxlength="160" placeholder="Venue, room or address" aria-label="Session {{ $index + 1 }} venue" class="block w-full rounded-lg border-gray-300" /></label>
                            @endif
                            <div class="flex flex-wrap items-end gap-2">
                                @if (count($slotEdits) > 1 && ($slot['location_choice'] ?? '') !== '')<x-filament::button color="gray" wire:click="applyLocationToAll({{ $index }})" aria-label="Use session {{ $index + 1 }} location for all sessions">Use for all</x-filament::button>@endif
                                @if (count($slotEdits) > 1)<x-filament::button color="gray" wire:click="removeSlot({{ $index }})" aria-label="Remove session {{ $index + 1 }}">Remove</x-filament::button>@endif
                            </div>
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
                        Saved: @foreach ($slotLabels as $savedSlot){{ $savedSlot['label'] }} {{ $savedSlot['time'] }}{{ $savedSlot['location'] !== '' ? ' at '.$savedSlot['location'] : '' }}{{ ! $loop->last ? ' · ' : '' }}@endforeach
                    @else
                        No sessions yet. This plan goes out as one list.
                    @endif
                </p>
                @foreach ($locationConflicts as $conflict)<p role="status" class="mt-2 text-sm text-warning-700">{{ $conflict }}</p>@endforeach
            </details>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">4. Review</x-slot>
            <x-slot name="headerEnd"><x-filament::badge>{{ $selectedCount }} selected</x-filament::badge></x-slot>
            @if ($selectedCount === 0)
                <p>Select members in step 2. They appear here{{ $slotCount > 0 ? ', grouped by session' : '' }}.</p>
            @elseif ($slotCount > 0)
                <div class="flex flex-wrap items-end gap-3">
                    <label class="min-w-0 flex-1">Move checked members to
                        <select wire:model="targetSlot" class="block w-full rounded-lg border-gray-300">
                            <option value="">No session</option>
                            @foreach ($slotLabels as $slotId => $label)<option value="{{ $slotId }}">{{ $label['label'] }} {{ $label['time'] }}</option>@endforeach
                        </select>
                    </label>
                    <x-filament::button color="gray" wire:click="moveSelected">Move</x-filament::button>
                    <x-filament::button color="gray" wire:click="removeSelected">Remove checked</x-filament::button>
                </div>
                @error('targetSlot') <p role="alert" class="mt-2 text-danger-600">{{ $message }}</p> @enderror
            @endif
            @foreach ($review['groups'] as $groupId => $group)
                @if ($group['members'] !== [])
                    <section class="mt-3" aria-labelledby="review-{{ $groupId }}">
                        <h3 id="review-{{ $groupId }}" class="font-semibold">{{ $group['label'] }} — {{ $group['time'] }}{{ $group['location'] !== '' ? ' · '.$group['location'] : '' }} <span class="font-normal">({{ count($group['members']) }})</span></h3>
                        <ul class="divide-y divide-gray-200">
                            @foreach ($group['members'] as $member)
                                @include('filament.training.pages.partials.crew-planner-member', ['member' => $member, 'key' => $groupId, 'detail' => $member['place'], 'pickable' => true])
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach
            @if ($review['unassigned'] !== [])
                <section class="mt-3" aria-labelledby="review-unassigned">
                    <h3 id="review-unassigned" class="font-semibold">{{ $slotCount > 0 ? 'No session chosen yet' : 'Class list' }} <span class="font-normal">({{ count($review['unassigned']) }})</span></h3>
                    @if ($slotCount > 0)
                        <div class="my-2 flex flex-wrap gap-2">
                            @foreach ($slotLabels as $slotId => $label)
                                <x-filament::button color="gray" size="sm" wire:click="assignUnassignedTo('{{ $slotId }}')">Assign to {{ $label['label'] }}</x-filament::button>
                            @endforeach
                        </div>
                    @endif
                    <ul class="divide-y divide-gray-200">
                        @foreach ($review['unassigned'] as $member)
                            @include('filament.training.pages.partials.crew-planner-member', ['member' => $member, 'key' => 'unassigned', 'detail' => $duty->isVerified() ? $member['place'] : null, 'pickable' => $slotCount > 0])
                        @endforeach
                    </ul>
                </section>
            @endif
            @if ($review['verify'] !== [])
                <section class="mt-3 hub-callout" aria-labelledby="review-verify">
                    <h3 id="review-verify" class="font-semibold text-warning-700">Needs verification <span class="font-normal">({{ count($review['verify']) }})</span></h3>
                    <ul class="divide-y divide-gray-200">
                        @foreach ($review['verify'] as $member)
                            @include('filament.training.pages.partials.crew-planner-member', ['member' => $member, 'key' => 'verify', 'detail' => $member['reason'], 'pickable' => false])
                        @endforeach
                    </ul>
                    <label class="mt-3 flex items-center gap-3"><input type="checkbox" wire:model.live="confirmReview" /> I will recheck these members; include them, marked as needing verification.</label>
                </section>
            @endif
            @error('confirmReview')<p role="alert" class="mt-3 text-danger-600">{{ $message }}</p>@enderror
            @error('slotEdits')<p role="alert" class="mt-3 text-danger-600">{{ $message }}</p>@enderror
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">5. Publish</x-slot>
            <fieldset>
                <legend class="font-medium">Send this plan to</legend>
                <div class="mt-2 space-y-1">
                    @foreach ($targets as $target)
                        <label class="flex items-center gap-3 {{ $target['allowed'] ? '' : 'opacity-70' }}">
                            <input type="checkbox" wire:model.live="publishTargets" value="{{ $target['id'] }}" @disabled(! $target['allowed']) />
                            <span>{{ $target['label'] }}@unless ($target['allowed'])<span class="block text-sm">Your account cannot post there.</span>@endunless</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @error('publishTargets')<p role="alert" class="mt-2 text-danger-600">{{ $message }}</p>@enderror
            @error('plan')<p role="alert" class="mt-2 text-danger-600">{{ $message }}</p>@enderror
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-filament::button wire:click="publish" wire:loading.attr="disabled" wire:target="publish,retryFailed" :disabled="$selectedCount === 0 || $publishTargets === []"
                    wire:confirm="Send this class roster now? People will see it as a proposed roster, not attendance.">Send plan</x-filament::button>
                <x-filament::button color="gray" wire:click="download('pdf')" wire:loading.attr="disabled" :disabled="$selectedCount === 0">Download PDF</x-filament::button>
                <x-filament::button color="gray" wire:click="download('xlsx')" wire:loading.attr="disabled" :disabled="$selectedCount === 0">Download Excel</x-filament::button>
                <x-filament::button color="gray" wire:click="reloadProposal">Refresh check</x-filament::button>
                <x-filament::button color="danger" outlined wire:click="discardProposal" wire:confirm="Start over? This clears your selections. Courses, attendance and anything already sent stay unchanged.">Start over</x-filament::button>
            </div>
            <p class="mt-3 text-sm text-gray-600" role="status">Saved {{ \Carbon\CarbonImmutable::createFromTimestamp($draft['saved_at'] ?? time())->setTimezone($tz)->format('g:i A') }}. A plan is a proposal. Nothing is recorded as attendance. It stays here for a week after your last edit.</p>

            @if ($publications->isNotEmpty())
                <section class="mt-4" aria-labelledby="crew-plan-sent" data-crew-plan-status>
                    <h3 id="crew-plan-sent" class="font-semibold">Where this plan went</h3>
                    <ul class="divide-y divide-gray-200">
                        @foreach ($publications as $publication)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="publication-{{ $publication->id }}">
                                <span class="min-w-0">
                                    {{ $destinationLabels[$publication->destination] ?? $publication->destination }}
                                    <span class="block">{{ $publication->member_count }} members{{ $publication->fingerprint === $latestPublished ? '' : ' · earlier version' }}{{ $publication->sent_at ? ' · '.$publication->sent_at->setTimezone($tz)->format('M j g:i A') : '' }}@if ($publication->needsRetry()) · {{ $failureText[$publication->error_code] ?? 'It could not be confirmed as sent.' }}@endif</span>
                                </span>
                                <span class="flex items-center gap-2">
                                    @if ($publication->department_update_id)<a class="hub-link" href="{{ route('updates.show', $publication->department_update_id) }}">View</a>@endif
                                    <x-filament::badge :color="$publication->status === 'sent' ? 'success' : ($publication->needsRetry() ? 'danger' : 'gray')">{{ $publication->status === 'sent' ? 'Sent' : ($publication->needsRetry() ? 'Failed' : 'Sending') }}</x-filament::badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($hasFailed)
                        <x-filament::button class="mt-2" wire:click="retryFailed" wire:loading.attr="disabled" wire:target="publish,retryFailed">Retry what failed</x-filament::button>
                    @endif
                </section>
            @endif
        </x-filament::section>
    </div>
        <style>
            .crew-planner input:not([type=checkbox]), .crew-planner select, .crew-planner button, .crew-planner summary { min-height: 44px !important; }
            .crew-planner input[type=checkbox] { width: 24px; height: 24px; flex-shrink: 0; }
            .crew-planner label:has(input[type=checkbox]) { min-height: 44px; padding-block: 10px; }
            .crew-planner label.flex-1 { flex-basis: 12rem; min-width: min(12rem, 100%) !important; }
            @media (max-width: 640px) { .crew-planner label.flex-1 { flex-basis: 100%; } }
            .crew-planner { overflow-wrap: anywhere; }
            .crew-planner .cp-session-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.75rem; }
            .crew-planner .cp-row { display: grid; gap: 0.5rem 1rem; grid-template-columns: minmax(0, 1fr); align-items: center; }
            .crew-planner .cp-pick { display: flex; align-items: center; gap: 0.75rem; cursor: pointer; }
            .crew-planner .cp-note { color: rgb(var(--warning-700)); }
            .crew-planner .cp-ranks { display: flex; flex-wrap: wrap; column-gap: 1.5rem; }
            .crew-planner .cp-ranks label { display: flex; align-items: center; gap: 0.5rem; }
            .crew-planner .cp-search { max-width: 28rem; }
            @media (min-width: 900px) {
                .crew-planner .cp-row { grid-template-columns: minmax(14rem, 1.4fr) minmax(10rem, 1fr); }
                .crew-planner .cp-note, .crew-planner .cp-session { grid-column: 1 / -1; }
            }
        </style>
</x-filament-panels::page>
