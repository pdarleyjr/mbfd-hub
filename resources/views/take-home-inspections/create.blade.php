<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>New Take Home Vehicle Inspection · MBFD Hub</title>
    @vite(['resources/css/app.css', 'resources/css/take-home-inspection.css', 'resources/js/take-home-inspection.js'])
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header :back-href="route('take-home-inspections.index')" back-label="Take Home Inspections" />
    <main id="main" class="hub-page__main thvi-main mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="Take Home Vehicle Inspection" eyebrow="New inspection">
            <span class="thvi-type">Inspection type: Take Home Vehicle Inspection</span>
        </x-hub.page-header>

        <noscript><div class="hub-callout hub-callout--warning mb-4">This form needs JavaScript to take photos and capture your signature.</div></noscript>
        <div class="hub-callout mb-4" data-thvi-restored hidden role="status">
            <p class="text-sm">Your unfinished inspection was restored. <button type="button" class="hub-link" data-thvi-reset>Start over</button></p>
        </div>

        <form id="thvi-form" novalidate data-mbfd-unsaved-work-guard data-config='@json($config)'>
            <ol class="thvi-progress" aria-label="Inspection steps">
                <li><button type="button" data-goto="0"><span class="thvi-progress__num">Step 1</span>Vehicle &amp; driver</button></li>
                <li><button type="button" data-goto="1"><span class="thvi-progress__num">Step 2</span>Condition</button></li>
                <li><button type="button" data-goto="2"><span class="thvi-progress__num">Step 3</span>Photos</button></li>
                <li><button type="button" data-goto="3"><span class="thvi-progress__num">Step 4</span>Sign &amp; submit</button></li>
            </ol>

            {{-- Step 1: vehicle and driver --}}
            <section class="thvi-step hub-panel" data-step="0" aria-labelledby="step1-title">
                <div class="hub-panel__header"><h2 id="step1-title" class="text-lg font-bold" tabindex="-1">1. Vehicle and driver</h2></div>
                <div class="thvi-step__body">
                    <div class="thvi-field" data-vehicle-picker>
                        <label class="thvi-label" for="thvi-vehicle-search">Vehicle (required)</label>
                        <p class="thvi-hint" id="thvi-vehicle-hint">Type the vehicle number and press Go, or search by make, model or plate.</p>
                        <div class="thvi-search">
                            <input id="thvi-vehicle-search" class="thvi-input" type="search" inputmode="search" enterkeyhint="go" autocomplete="off" aria-describedby="thvi-vehicle-hint" placeholder="Vehicle number, e.g. 22514">
                            <button type="button" class="hub-btn hub-btn--primary" data-vehicle-go>Go</button>
                        </div>
                        <div class="thvi-chips" data-recent-vehicles hidden></div>
                        <p class="thvi-hint" data-vehicle-message role="status" aria-live="polite"></p>
                        <ul class="thvi-results" data-vehicle-results></ul>
                        <p class="thvi-error" data-error="vehicle_id"></p>
                    </div>

                    <div class="hub-well grid gap-3 p-4" data-new-vehicle hidden>
                        <h3 class="font-bold">Add a vehicle that is not on the roster</h3>
                        <p class="thvi-hint">Fleet will verify it. You can continue the inspection right away.</p>
                        <div class="thvi-grid">
                            <div class="thvi-field"><label class="thvi-label" for="nv-veh">Vehicle #</label><input id="nv-veh" class="thvi-input" data-nv="veh_number" autocomplete="off"></div>
                            <div class="thvi-field"><label class="thvi-label" for="nv-cat">Type</label><select id="nv-cat" class="thvi-select" data-nv="asset_category"></select></div>
                            <div class="thvi-field"><label class="thvi-label" for="nv-make">Make</label><input id="nv-make" class="thvi-input" data-nv="make" autocomplete="off"></div>
                            <div class="thvi-field"><label class="thvi-label" for="nv-model">Model</label><input id="nv-model" class="thvi-input" data-nv="model" autocomplete="off"></div>
                            <div class="thvi-field"><label class="thvi-label" for="nv-year">Year</label><input id="nv-year" class="thvi-input" data-nv="year" inputmode="numeric" maxlength="4" autocomplete="off"></div>
                            <div class="thvi-field"><label class="thvi-label" for="nv-color">Color</label><input id="nv-color" class="thvi-input" data-nv="color" autocomplete="off"></div>
                            <div class="thvi-field"><label class="thvi-label" for="nv-tag">License plate</label><input id="nv-tag" class="thvi-input" data-nv="tag_number" autocomplete="off" autocapitalize="characters"></div>
                            <div class="thvi-field"><label class="thvi-label" for="nv-vin">VIN / Serial</label><input id="nv-vin" class="thvi-input" data-nv="serial_number" autocomplete="off" autocapitalize="characters"></div>
                        </div>
                        <p class="thvi-error" data-nv-error role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="hub-btn hub-btn--primary" data-nv-save>Add vehicle and continue</button>
                            <button type="button" class="hub-btn hub-btn--secondary" data-nv-cancel>Cancel</button>
                        </div>
                    </div>

                    <div data-vehicle-chosen hidden class="grid gap-4">
                        <div class="thvi-chosen">
                            <div>
                                <p class="thvi-chosen__title" data-chosen-title></p>
                                <p class="thvi-hint" data-chosen-meta></p>
                            </div>
                            <button type="button" class="hub-btn hub-btn--secondary" data-vehicle-change>Change vehicle</button>
                        </div>
                        <div>
                            <p class="thvi-label">Vehicle details</p>
                            <p class="thvi-hint">Check these and correct anything wrong. Blank details you fill in are saved to the roster; changes to existing details go to Fleet to confirm.</p>
                        </div>
                        <div class="thvi-grid">
                            <div class="thvi-field"><label class="thvi-label" for="vf-make">Make</label><input id="vf-make" class="thvi-input" data-vf="make" autocomplete="off"></div>
                            <div class="thvi-field"><label class="thvi-label" for="vf-model">Model</label><input id="vf-model" class="thvi-input" data-vf="model" autocomplete="off"></div>
                            <div class="thvi-field"><label class="thvi-label" for="vf-year">Year</label><input id="vf-year" class="thvi-input" data-vf="year" inputmode="numeric" maxlength="4" autocomplete="off"><p class="thvi-error" data-error="vehicle.year"></p></div>
                            <div class="thvi-field"><label class="thvi-label" for="vf-color">Color</label><input id="vf-color" class="thvi-input" data-vf="color" autocomplete="off"></div>
                            <div class="thvi-field"><label class="thvi-label" for="vf-tag">License plate / Tag</label><input id="vf-tag" class="thvi-input" data-vf="tag_number" autocomplete="off" autocapitalize="characters"></div>
                            <div class="thvi-field thvi-field--half"><label class="thvi-label" for="vf-vin">VIN / Serial</label><input id="vf-vin" class="thvi-input" data-vf="serial_number" autocomplete="off" autocapitalize="characters"></div>
                            <div class="thvi-field"><label class="thvi-label" for="vf-dept">Department</label><input id="vf-dept" class="thvi-input" data-vf="dept_code" autocomplete="off"></div>
                        </div>
                    </div>

                    <div class="thvi-field" data-driver-picker>
                        <label class="thvi-label" for="thvi-driver-search">Driver assigned to this take-home vehicle (optional)</label>
                        <p class="thvi-hint" id="thvi-driver-hint">Search by name or employee ID. The driver can be someone other than you.</p>
                        <div data-driver-chosen hidden class="thvi-chosen">
                            <div><p class="font-bold" data-driver-name></p><p class="thvi-hint" data-driver-meta></p></div>
                            <button type="button" class="hub-btn hub-btn--secondary" data-driver-change>Change driver</button>
                        </div>
                        <div data-driver-search-wrap>
                            <input id="thvi-driver-search" class="thvi-input" type="search" enterkeyhint="search" autocomplete="off" aria-describedby="thvi-driver-hint" placeholder="Name or employee ID">
                            <ul class="thvi-results mt-2" data-driver-results></ul>
                            <button type="button" class="hub-link mt-2" data-driver-unlisted>Driver not listed? Enter their name</button>
                        </div>
                        <div class="thvi-grid" data-driver-manual hidden>
                            <div class="thvi-field thvi-field--half"><label class="thvi-label" for="dm-name">Driver full name</label><input id="dm-name" class="thvi-input" data-dm="name" autocomplete="off"></div>
                            <div class="thvi-field"><label class="thvi-label" for="dm-id">Employee ID (if known)</label><input id="dm-id" class="thvi-input" data-dm="number" inputmode="numeric" autocomplete="off"></div>
                            <div class="thvi-field"><span class="thvi-label">&nbsp;</span><button type="button" class="hub-btn hub-btn--secondary" data-driver-search-again>Search the directory instead</button></div>
                            <p class="thvi-hint thvi-field--wide">Entered names are recorded as not verified.</p>
                        </div>
                        <p class="thvi-error" data-error="driver_name"></p>
                    </div>

                    <div class="thvi-grid">
                        <div class="thvi-field thvi-field--half"><span class="thvi-label">Inspector</span><input class="thvi-input" readonly data-inspector-name aria-label="Inspector (you)"></div>
                        <div class="thvi-field thvi-field--half"><span class="thvi-label">Date and time</span><input class="thvi-input" readonly data-now aria-label="Inspection date and time, recorded automatically"></div>
                    </div>

                    <div class="thvi-field">
                        <label class="thvi-label" for="thvi-odometer">Odometer in miles (optional)</label>
                        <input id="thvi-odometer" class="thvi-input" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" placeholder="Current reading" data-odometer>
                        <p class="thvi-hint" data-odometer-hint></p>
                        <label class="thvi-check-row"><input type="checkbox" data-odometer-none> <span data-odometer-none-label>This vehicle has no working odometer</span></label>
                        <p class="thvi-error" data-error="odometer"></p>
                    </div>
                </div>
            </section>

            {{-- Step 2: six condition checks --}}
            <section class="thvi-step hub-panel" data-step="1" aria-labelledby="step2-title" hidden>
                <div class="hub-panel__header"><h2 id="step2-title" class="text-lg font-bold" tabindex="-1">2. Condition</h2></div>
                <div class="thvi-step__body" data-checks><p class="thvi-hint">Optional. Answer what you checked; anything left blank is reported as Not answered.</p></div>
            </section>

            {{-- Step 3: six required photos --}}
            <section class="thvi-step hub-panel" data-step="2" aria-labelledby="step3-title" hidden>
                <div class="hub-panel__header"><h2 id="step3-title" class="text-lg font-bold" tabindex="-1">3. Photos</h2><span class="hub-tag" data-photo-count>0 of 6</span></div>
                <div class="thvi-step__body">
                    <p class="thvi-hint">Optional, but photos help Fleet. Use Take photo for the camera, or Choose existing to pick from your photos. A dashboard photo should show the odometer and any warning lights.</p>
                    <div class="thvi-photos" data-photos></div>
                </div>
            </section>

            {{-- Step 4: final answers and signature --}}
            <section class="thvi-step hub-panel" data-step="3" aria-labelledby="step4-title" hidden>
                <div class="hub-panel__header"><h2 id="step4-title" class="text-lg font-bold" tabindex="-1">4. Sign and submit</h2></div>
                <div class="thvi-step__body">
                    <div class="thvi-field">
                        <span class="thvi-label" id="keys-label">Are the vehicle keys present? (optional)</span>
                        <div class="thvi-segment thvi-segment--two" role="radiogroup" aria-labelledby="keys-label" data-final="keys_present">
                            <button type="button" role="radio" aria-checked="false" data-value="yes">Yes</button>
                            <button type="button" role="radio" aria-checked="false" data-value="no">No</button>
                        </div>
                        <p class="thvi-error" data-error="keys_present"></p>
                    </div>
                    <div class="thvi-field">
                        <span class="thvi-label" id="acceptable-label">Is the vehicle acceptable for use? (optional)</span>
                        <div class="thvi-segment thvi-segment--two" role="radiogroup" aria-labelledby="acceptable-label" data-final="acceptable_for_use">
                            <button type="button" role="radio" aria-checked="false" data-value="yes">Yes</button>
                            <button type="button" role="radio" aria-checked="false" data-value="no">No</button>
                        </div>
                        <p class="thvi-error" data-error="acceptable_for_use"></p>
                    </div>
                    <div class="thvi-field">
                        <label class="thvi-label" for="thvi-comments">Additional comments (optional)</label>
                        <textarea id="thvi-comments" class="thvi-textarea" maxlength="3000" data-comments></textarea>
                    </div>
                    <label class="thvi-ack">
                        <input type="checkbox" data-ack>
                        <span><strong>Required:</strong> I inspected this vehicle and this report is accurate to the best of my knowledge. I understand this is not a mechanical safety certification.</span>
                    </label>
                    <p class="thvi-error" data-error="acknowledged"></p>
                    <div class="thvi-field">
                        <span class="thvi-label" id="sig-label">Signature (required)</span>
                        <p class="thvi-hint">Sign with your finger, stylus or mouse.</p>
                        <div class="thvi-signature" data-signature-wrap>
                            <canvas data-signature aria-labelledby="sig-label" role="img"></canvas>
                            <div class="thvi-signature__line" aria-hidden="true"></div>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="thvi-hint" data-signature-status>Not signed</span>
                            <button type="button" class="hub-btn hub-btn--secondary hub-btn--compact" data-signature-clear>Clear signature</button>
                        </div>
                        <p class="thvi-error" data-error="signature"></p>
                    </div>
                </div>
            </section>

            <div class="thvi-nav">
                <button type="button" class="hub-btn hub-btn--secondary hub-btn--field" data-back hidden>Back</button>
                <button type="button" class="hub-btn hub-btn--primary hub-btn--field thvi-next" data-next>Next</button>
                <button type="button" class="hub-btn hub-btn--primary hub-btn--field thvi-next" data-submit hidden>Submit inspection</button>
            </div>
            <p class="thvi-status-line mt-3" data-form-status role="status" aria-live="polite"></p>
        </form>
    </main>
    @include('components.hub-support-widget')
</body>
</html>
