@php
    use App\Models\TakeHomeVehicleInspection as Inspection;
    $yesNo = fn (?bool $value): ?string => $value === null ? null : ($value ? '1' : '0');
    $checksByKey = collect($inspection->checks ?? [])->keyBy('key');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <title>Edit {{ $inspection->reference }} · Take Home Vehicle Inspection · MBFD Hub</title>
    @vite(['resources/css/app.css', 'resources/css/take-home-inspection.css'])
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header :back-href="route('take-home-inspections.show', $inspection)" back-label="Inspection report" />
    <main id="main" class="hub-page__main thvi-main mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="Edit your inspection" :description="$inspection->reference.' · '.$inspection->vehicleLabel().' · Submitted '.$inspection->submittedAtLocal()" />

        <div class="hub-callout" role="note">
            <p class="text-sm">Correct anything that was wrong and save. Your changes are added to the report with your name and the time, and the original you signed is kept. The vehicle, photos and signature stay as submitted.</p>
        </div>

        @if($errors->any())
            <div class="hub-callout hub-callout--warning" role="alert">
                <p class="font-bold">Check the highlighted answers.</p>
                <ul class="mt-1 list-disc pl-5 text-sm">
                    @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('take-home-inspections.update', $inspection) }}" class="space-y-6" data-thvi-edit-form>
            @csrf
            @method('PUT')
            <input type="hidden" name="client_revision_id" value="{{ old('client_revision_id', $clientRevisionId) }}">

            <section class="hub-panel" aria-labelledby="driver-heading">
                <div class="hub-panel__header"><h2 id="driver-heading" class="text-lg font-bold">Driver and odometer</h2></div>
                <div class="thvi-step__body">
                    <div class="thvi-grid">
                        <div class="thvi-field thvi-field--half">
                            <label class="thvi-label" for="edit-driver-name">Assigned driver (optional)</label>
                            <input id="edit-driver-name" name="driver_name" class="thvi-input" maxlength="120" autocomplete="off" value="{{ old('driver_name', $inspection->driver_name) }}">
                        </div>
                        <div class="thvi-field">
                            <label class="thvi-label" for="edit-driver-number">Employee ID (if known)</label>
                            <input id="edit-driver-number" name="driver_employee_number" class="thvi-input" maxlength="32" inputmode="numeric" autocomplete="off" value="{{ old('driver_employee_number', $inspection->driver_employee_number) }}">
                        </div>
                        <p class="thvi-hint thvi-field--wide">A changed driver name is recorded as entered, not verified.</p>
                    </div>
                    <div class="thvi-grid">
                        <div class="thvi-field thvi-field--half">
                            <label class="thvi-label" for="edit-odometer">Odometer in miles (optional)</label>
                            <input id="edit-odometer" name="odometer" class="thvi-input" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" value="{{ old('odometer', $inspection->odometer) }}" @error('odometer') aria-invalid="true" @enderror>
                            @error('odometer')<p class="thvi-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="thvi-field thvi-field--half">
                            <label class="thvi-label" for="edit-odometer-exception">If there is no reading</label>
                            <select id="edit-odometer-exception" name="odometer_exception" class="thvi-select">
                                <option value="">Not applicable</option>
                                @foreach(Inspection::ODOMETER_EXCEPTIONS as $value => $label)
                                    <option value="{{ $value }}" @selected(old('odometer_exception', $inspection->odometer_exception) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            <section class="hub-panel" aria-labelledby="checks-heading">
                <div class="hub-panel__header"><h2 id="checks-heading" class="text-lg font-bold">Condition</h2></div>
                <div class="thvi-step__body">
                    @foreach(Inspection::CHECKS as $key => [$label, $hint])
                        @php
                            $check = $checksByKey->get($key, []);
                            $status = old("checks.{$key}.status", $check['status'] ?? null);
                        @endphp
                        <fieldset class="thvi-check" data-edit-check="{{ $key }}">
                            <legend class="sr-only">{{ $label }}</legend>
                            <div class="thvi-check__head">
                                <span class="thvi-label" aria-hidden="true">{{ $label }}</span>
                                <span class="thvi-hint">{{ $hint }}</span>
                            </div>
                            <div class="thvi-choices">
                                @foreach(Inspection::STATUS_LABELS as $value => $statusLabel)
                                    <label class="thvi-choice" data-value="{{ $value }}">
                                        <input type="radio" name="checks[{{ $key }}][status]" value="{{ $value }}" @checked($status === $value)>
                                        <span>{{ $statusLabel }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="thvi-check__detail">
                                <div class="thvi-field">
                                    <label class="thvi-label" for="edit-{{ $key }}-issue">Describe the issue (if Issue found)</label>
                                    <textarea id="edit-{{ $key }}-issue" name="checks[{{ $key }}][issue_description]" class="thvi-textarea" maxlength="1000">{{ old("checks.{$key}.issue_description", $check['issue_description'] ?? null) }}</textarea>
                                </div>
                                <div class="thvi-field">
                                    <label class="thvi-label" for="edit-{{ $key }}-notes">Notes (optional)</label>
                                    <textarea id="edit-{{ $key }}-notes" name="checks[{{ $key }}][notes]" class="thvi-textarea" maxlength="1000">{{ old("checks.{$key}.notes", $check['notes'] ?? null) }}</textarea>
                                </div>
                            </div>
                        </fieldset>
                    @endforeach
                </div>
            </section>

            <section class="hub-panel" aria-labelledby="final-heading">
                <div class="hub-panel__header"><h2 id="final-heading" class="text-lg font-bold">Final answers</h2></div>
                <div class="thvi-step__body">
                    @foreach(['keys_present' => 'Are the vehicle keys present?', 'acceptable_for_use' => 'Is the vehicle acceptable for use?'] as $name => $question)
                        @php($answer = old($name, $yesNo($inspection->{$name})))
                        <fieldset class="thvi-field">
                            <legend class="thvi-label">{{ $question }} (optional)</legend>
                            <div class="thvi-choices thvi-choices--two">
                                <label class="thvi-choice" data-value="yes"><input type="radio" name="{{ $name }}" value="1" @checked((string) $answer === '1')><span>Yes</span></label>
                                <label class="thvi-choice" data-value="no"><input type="radio" name="{{ $name }}" value="0" @checked((string) $answer === '0')><span>No</span></label>
                            </div>
                        </fieldset>
                    @endforeach
                    <div class="thvi-field">
                        <label class="thvi-label" for="edit-comments">Additional comments (optional)</label>
                        <textarea id="edit-comments" name="comments" class="thvi-textarea" maxlength="3000">{{ old('comments', $inspection->comments) }}</textarea>
                    </div>
                    <div class="thvi-field">
                        <label class="thvi-label" for="edit-reason">What did you correct? (optional)</label>
                        <input id="edit-reason" name="reason" class="thvi-input" maxlength="1000" autocomplete="off" value="{{ old('reason') }}" placeholder="For example: picked the wrong tire result">
                    </div>
                </div>
            </section>

            <div class="thvi-nav">
                <a href="{{ route('take-home-inspections.show', $inspection) }}" class="hub-btn hub-btn--secondary hub-btn--field">Cancel</a>
                <button type="submit" class="hub-btn hub-btn--primary hub-btn--field thvi-next">Save changes</button>
            </div>
        </form>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
