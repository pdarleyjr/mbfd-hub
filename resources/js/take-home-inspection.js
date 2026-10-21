/*
 * Take Home Vehicle Inspection form (vanilla JS, phone first).
 *
 * - Four steps with per-step validation; the server stays authoritative.
 * - Photos are resized on the device (JPEG, max 2400px) and uploaded one at a
 *   time with progress and Retry. If the browser cannot decode the file (for
 *   example HEIC in Chrome) the original is sent and the server converts it.
 * - Answers, chosen vehicle/driver and uploaded photo IDs are saved on this
 *   device so an interruption or refresh never loses work. The signature is
 *   never stored locally.
 * - Submission is idempotent: one client_submission_id per inspection.
 */

const form = document.getElementById('thvi-form');

if (form) {
    initTakeHomeInspection(form);
}

function initTakeHomeInspection(form) {
    const config = JSON.parse(form.dataset.config || '{}');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const DRAFT_KEY = `thvi-draft:${config.userKey}`;
    const RECENT_KEY = `thvi-recent:${config.userKey}`;
    const DRAFT_MAX_AGE_MS = 72 * 3600 * 1000;
    const MAX_EDGE = 2400;
    const STEP_COUNT = 4;

    const $ = (selector, root = form) => root.querySelector(selector);
    const $$ = (selector, root = form) => Array.from(root.querySelectorAll(selector));

    const state = freshState();
    const photoSlots = {};
    const pendingFiles = new Map();
    let submitting = false;
    let saveTimer = null;

    function freshState() {
        const checks = {};
        config.checks.forEach((check) => { checks[check.key] = { status: null, issue_description: '', notes: '' }; });
        const issuePhotos = {};
        config.checks.forEach((check) => { issuePhotos[check.key] = []; });

        return {
            savedAt: Date.now(),
            clientSubmissionId: uuid(),
            startedAt: new Date().toISOString(),
            step: 0,
            vehicle: null,
            vehicleFields: {},
            driver: null,
            driverManual: false,
            driverName: '',
            driverNumber: '',
            odometer: '',
            odometerNone: false,
            checks,
            photos: {},
            issuePhotos,
            keys: null,
            acceptable: null,
            comments: '',
            ack: false,
        };
    }

    // ---------- storage ----------
    function storageGet(key) {
        try { return JSON.parse(window.localStorage.getItem(key) || 'null'); } catch { return null; }
    }
    function storageSet(key, value) {
        try { window.localStorage.setItem(key, JSON.stringify(value)); } catch { /* private mode: keep working without drafts */ }
    }
    function storageRemove(key) {
        try { window.localStorage.removeItem(key); } catch { /* ignore */ }
    }
    function saveDraft() {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(() => {
            state.savedAt = Date.now();
            storageSet(DRAFT_KEY, draftSnapshot());
        }, 250);
    }
    /** Personnel details (driver, current driver) are never written to device storage. */
    function draftSnapshot() {
        return {
            savedAt: state.savedAt,
            clientSubmissionId: state.clientSubmissionId,
            startedAt: state.startedAt,
            step: state.step,
            vehicle: state.vehicle ? { ...state.vehicle, current_driver: null, assigned: null } : null,
            vehicleFields: state.vehicleFields,
            odometer: state.odometer,
            odometerNone: state.odometerNone,
            checks: state.checks,
            photos: Object.fromEntries(Object.entries(state.photos).map(([key, photo]) => [key, { uuid: photo.uuid }])),
            issuePhotos: Object.fromEntries(Object.entries(state.issuePhotos).map(([key, photos]) => [key, photos.map((photo) => ({ uuid: photo.uuid }))])),
            keys: state.keys,
            acceptable: state.acceptable,
            comments: state.comments,
            ack: state.ack,
        };
    }

    const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    /** Photo URLs are always rebuilt from the server route template and a validated id. */
    function photoSrc(photo) {
        return photo && UUID_PATTERN.test(String(photo.uuid)) ? config.urls.photo.replace('__UUID__', photo.uuid) : '';
    }

    function restoreDraft() {
        const draft = storageGet(DRAFT_KEY);
        if (!draft || typeof draft !== 'object' || !draft.clientSubmissionId) return false;
        if (Date.now() - (draft.savedAt || 0) > DRAFT_MAX_AGE_MS) { storageRemove(DRAFT_KEY); return false; }
        const hasContent = draft.vehicle || Object.keys(draft.photos || {}).length > 0;
        if (!hasContent) return false;
        Object.assign(state, draft, { driver: null, driverManual: false, driverName: '', driverNumber: '' });
        config.checks.forEach((check) => {
            state.checks[check.key] = Object.assign({ status: null, issue_description: '', notes: '' }, state.checks?.[check.key] || {});
            state.issuePhotos[check.key] = Array.isArray(state.issuePhotos?.[check.key]) ? state.issuePhotos[check.key] : [];
        });
        return true;
    }

    // ---------- network ----------
    async function getJson(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (response.status === 401 || response.status === 419) throw new Error('Your session expired. Reload the page and sign in; your answers are saved on this device.');
        if (!response.ok) throw new Error('The Hub could not be reached. Check your connection and try again.');
        return response.json();
    }
    async function sendJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body),
        });
        let json = {};
        try { json = await response.json(); } catch { json = {}; }
        return { status: response.status, ok: response.ok, json };
    }

    // ---------- step navigation ----------
    function showStep(index, { focus = true } = {}) {
        state.step = Math.max(0, Math.min(STEP_COUNT - 1, index));
        $$('.thvi-step').forEach((section) => { section.hidden = Number(section.dataset.step) !== state.step; });
        $$('.thvi-progress button').forEach((button) => {
            const step = Number(button.dataset.goto);
            if (step === state.step) button.setAttribute('aria-current', 'step'); else button.removeAttribute('aria-current');
            button.classList.toggle('is-done', step < state.step);
        });
        $('[data-back]').hidden = state.step === 0;
        $('[data-next]').hidden = state.step === STEP_COUNT - 1;
        $('[data-submit]').hidden = state.step !== STEP_COUNT - 1;
        if (state.step === STEP_COUNT - 1) signature.resize();
        if (focus) {
            const heading = $(`[data-step="${state.step}"] h2`);
            heading?.focus({ preventScroll: true });
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        saveDraft();
    }

    function goTo(target) {
        if (target > state.step) {
            for (let step = state.step; step < target; step++) {
                const errors = validateStep(step);
                if (Object.keys(errors).length > 0) {
                    showErrors(errors);
                    showStep(step);
                    setStatus('Finish the highlighted items before continuing.');
                    return;
                }
            }
        }
        clearErrors();
        setStatus('');
        showStep(target);
    }

    $$('.thvi-progress button').forEach((button) => button.addEventListener('click', () => goTo(Number(button.dataset.goto))));
    $('[data-next]').addEventListener('click', () => goTo(state.step + 1));
    $('[data-back]').addEventListener('click', () => goTo(state.step - 1));

    // ---------- validation ----------
    function validateStep(step) {
        const errors = {};
        if (step === 0) {
            if (!state.vehicle) errors.vehicle_id = 'Choose the vehicle you are inspecting.';
            // Only the vehicle is required here; other entries are format-checked when filled in.
            if (state.vehicleFields.year && !/^\d{4}$/.test(state.vehicleFields.year)) errors['vehicle.year'] = 'Enter a four-digit model year, or leave it blank.';
            if (!state.odometerNone && state.odometer && Number(state.odometer) > 2000000) errors.odometer = 'Check the odometer reading; it cannot be more than 2,000,000 miles.';
        }
        if (step === 1) {
            config.checks.forEach((check) => {
                if (photosBusy(check.key)) errors[`checks.${check.key}.status`] = 'Wait for the issue photo to finish uploading.';
            });
        }
        if (step === 2) {
            config.angles.forEach((angle) => {
                if (!state.photos[angle.key] && photoSlots[angle.key]?.busy) errors[`photos.${angle.key}`] = 'Still uploading. Please wait, or remove it.';
            });
        }
        if (step === 3) {
            // Required: acknowledgment and signature. Everything else is optional.
            if (!state.ack) errors.acknowledged = 'Check the acknowledgment box.';
            if (!signature.hasInk()) errors.signature = 'Sign in the box above.';
        }
        return errors;
    }

    function photosBusy(checkKey) {
        return Object.values(photoSlots).some((slot) => slot.busy && slot.checkKey === checkKey);
    }

    function clearErrors() {
        $$('[data-error]').forEach((node) => { node.textContent = ''; });
        $$('[aria-invalid="true"]').forEach((node) => node.removeAttribute('aria-invalid'));
        $$('.has-error').forEach((node) => node.classList.remove('has-error'));
    }

    function showErrors(errors) {
        clearErrors();
        let first = null;
        Object.entries(errors).forEach(([field, message]) => {
            const text = Array.isArray(message) ? message[0] : message;
            let target = $(`[data-error="${cssEscape(field)}"]`);
            if (!target) {
                const checkMatch = field.match(/^(?:checks|issue_photos)\.([a-z_]+)/);
                const photoMatch = field.match(/^photos\.([a-z_]+)/);
                if (checkMatch) target = $(`[data-error="checks.${checkMatch[1]}"]`);
                else if (photoMatch) target = $(`[data-error="photos.${photoMatch[1]}"]`);
                else if (field.startsWith('vehicle')) target = $('[data-error="vehicle_id"]');
                else if (field.startsWith('driver')) target = $('[data-error="driver_name"]');
            }
            if (target) {
                target.textContent = text;
                target.closest('.thvi-check, .thvi-photo, .thvi-signature')?.classList.add('has-error');
                if (!first) first = target;
            }
        });
        $$('.thvi-progress button').forEach((button) => button.classList.remove('has-error'));
        Object.keys(errors).forEach((field) => {
            $(`.thvi-progress button[data-goto="${stepForField(field)}"]`)?.classList.add('has-error');
        });
        return first;
    }

    function stepForField(field) {
        if (/^(vehicle|driver|odometer)/.test(field)) return 0;
        if (/^(checks|issue_photos)/.test(field)) return 1;
        if (/^photos/.test(field)) return 2;
        return 3;
    }

    function setStatus(message) {
        $('[data-form-status]').textContent = message;
    }

    // ---------- step 1: vehicle ----------
    const vehicleInput = $('#thvi-vehicle-search');
    const vehicleResults = $('[data-vehicle-results]');
    const vehicleMessage = $('[data-vehicle-message]');
    let vehicleTimer = null;
    let vehicleRequest = 0;

    vehicleInput.addEventListener('input', () => {
        window.clearTimeout(vehicleTimer);
        vehicleTimer = window.setTimeout(() => searchVehicles(vehicleInput.value, false), 250);
    });
    vehicleInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            window.clearTimeout(vehicleTimer);
            searchVehicles(vehicleInput.value, true);
        }
    });
    $('[data-vehicle-go]').addEventListener('click', () => searchVehicles(vehicleInput.value, true));
    $('[data-vehicle-change]').addEventListener('click', () => {
        state.vehicle = null;
        state.vehicleFields = {};
        renderVehicle();
        saveDraft();
        vehicleInput.focus();
    });

    async function searchVehicles(raw, resolveExact) {
        const query = raw.trim();
        const requestId = ++vehicleRequest;
        vehicleResults.innerHTML = '';
        if (!query) { vehicleMessage.textContent = ''; return; }
        vehicleMessage.textContent = 'Searching…';
        let data;
        try {
            data = await getJson(`${config.urls.vehicles}?q=${encodeURIComponent(query)}`);
        } catch (error) {
            if (requestId === vehicleRequest) vehicleMessage.textContent = error.message;
            return;
        }
        if (requestId !== vehicleRequest) return;

        if (resolveExact && data.exact.length === 1) {
            vehicleMessage.textContent = '';
            await selectVehicle(data.exact[0].id);
            return;
        }
        if (data.exact.length > 1) {
            vehicleMessage.textContent = `More than one roster entry uses #${query}. Choose the right vehicle.`;
            renderVehicleResults(data.exact, query, false);
            return;
        }
        if (data.results.length === 0) {
            vehicleMessage.textContent = `No vehicle matches "${query}".`;
            renderVehicleResults([], query, true);
            return;
        }
        vehicleMessage.textContent = `${data.results.length} match${data.results.length === 1 ? '' : 'es'}. Choose the vehicle.`;
        renderVehicleResults(data.results, query, data.exact.length === 0);
    }

    function renderVehicleResults(items, query, offerAdd) {
        vehicleResults.innerHTML = '';
        items.forEach((item) => {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'thvi-result';
            const left = document.createElement('span');
            const id = document.createElement('span');
            id.className = 'thvi-result__id';
            id.textContent = `#${item.veh_number || '?'}`;
            const meta = document.createElement('span');
            meta.className = 'thvi-result__meta';
            meta.textContent = [item.label, item.tag_number ? `Plate ${item.tag_number}` : null, item.assigned].filter(Boolean).join(' · ');
            left.append(id, meta);
            const kind = document.createElement('span');
            kind.className = item.nonroad ? 'hub-tag hub-tag--info' : 'hub-tag';
            kind.textContent = item.nonroad ? `Non-road: ${item.category_label}` : item.category_label;
            button.append(left, kind);
            button.addEventListener('click', () => selectVehicle(item.id));
            li.append(button);
            vehicleResults.append(li);
        });
        if (offerAdd && /^[A-Za-z0-9][A-Za-z0-9\- ]*$/.test(query)) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'hub-btn hub-btn--secondary';
            button.textContent = `Add new vehicle #${query}`;
            button.addEventListener('click', () => openNewVehicle(query));
            li.append(button);
            vehicleResults.append(li);
        }
    }

    async function selectVehicle(id) {
        vehicleMessage.textContent = 'Loading vehicle…';
        try {
            const data = await getJson(config.urls.vehicle.replace('__ID__', encodeURIComponent(id)));
            useVehicle(data.vehicle);
            vehicleMessage.textContent = '';
        } catch (error) {
            vehicleMessage.textContent = error.message;
        }
    }

    function useVehicle(vehicle) {
        state.vehicle = vehicle;
        state.vehicleFields = Object.assign({}, vehicle.fields);
        Object.keys(state.vehicleFields).forEach((key) => { if (state.vehicleFields[key] === null) state.vehicleFields[key] = ''; });
        if (!state.driver && !state.driverManual && vehicle.current_driver) state.driver = vehicle.current_driver;
        if (vehicle.nonroad && state.odometer === '') state.odometerNone = true;
        rememberVehicle(vehicle);
        vehicleResults.innerHTML = '';
        vehicleInput.value = '';
        $('[data-new-vehicle]').hidden = true;
        renderVehicle();
        renderDriver();
        renderOdometer();
        $('[data-error="vehicle_id"]').textContent = '';
        saveDraft();
    }

    function renderVehicle() {
        const chosen = $('[data-vehicle-chosen]');
        const picker = $('[data-vehicle-picker]');
        if (!state.vehicle) {
            chosen.hidden = true;
            picker.hidden = false;
            renderRecent();
            return;
        }
        picker.hidden = true;
        chosen.hidden = false;
        const vehicle = state.vehicle;
        $('[data-chosen-title]').textContent = `#${vehicle.veh_number} · ${vehicle.label}`;
        $('[data-chosen-meta]').textContent = [
            vehicle.nonroad ? `Non-road asset: ${vehicle.category_label}` : vehicle.category_label,
            vehicle.assigned ? `Roster: ${vehicle.assigned}` : null,
            vehicle.needs_fleet_review ? 'New record awaiting Fleet verification' : null,
        ].filter(Boolean).join(' · ');
        $$('[data-vf]').forEach((input) => { input.value = state.vehicleFields[input.dataset.vf] ?? ''; });
    }

    $$('[data-vf]').forEach((input) => input.addEventListener('input', () => {
        state.vehicleFields[input.dataset.vf] = input.value;
        saveDraft();
    }));

    function rememberVehicle(vehicle) {
        const recent = (storageGet(RECENT_KEY) || []).filter((item) => item.id !== vehicle.id);
        recent.unshift({ id: vehicle.id, veh_number: vehicle.veh_number, label: vehicle.label });
        storageSet(RECENT_KEY, recent.slice(0, 3));
    }

    function renderRecent() {
        const holder = $('[data-recent-vehicles]');
        const recent = storageGet(RECENT_KEY) || [];
        holder.innerHTML = '';
        holder.hidden = recent.length === 0;
        recent.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'thvi-chip';
            button.textContent = `#${item.veh_number}`;
            button.title = item.label;
            button.setAttribute('aria-label', `Recent vehicle #${item.veh_number}, ${item.label}`);
            button.addEventListener('click', () => selectVehicle(item.id));
            holder.append(button);
        });
    }

    // New vehicle (not on the roster)
    const newVehiclePanel = $('[data-new-vehicle]');
    const categorySelect = $('[data-nv="asset_category"]');
    Object.entries(config.categories || {}).forEach(([value, label]) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        categorySelect.append(option);
    });

    function openNewVehicle(vehNumber) {
        newVehiclePanel.hidden = false;
        $$('[data-nv]').forEach((input) => { if (input.tagName !== 'SELECT') input.value = ''; });
        $('[data-nv="veh_number"]').value = vehNumber;
        $('[data-nv-error]').textContent = '';
        $('[data-nv="make"]').focus();
    }
    $('[data-nv-cancel]').addEventListener('click', () => { newVehiclePanel.hidden = true; });
    $('[data-nv-save]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const body = {};
        $$('[data-nv]').forEach((input) => { body[input.dataset.nv] = input.value.trim(); });
        const errorBox = $('[data-nv-error]');
        if (!body.veh_number || !body.make || !body.model) {
            errorBox.textContent = 'Enter at least the vehicle number, make and model.';
            return;
        }
        button.disabled = true;
        errorBox.textContent = '';
        try {
            const result = await sendJson(config.urls.storeVehicle, body);
            if (result.status === 201) {
                useVehicle(result.json.vehicle);
                setStatus(`Vehicle #${result.json.vehicle.veh_number} added. Fleet will verify it.`);
            } else if (result.status === 409) {
                errorBox.textContent = result.json.message;
                const useExisting = document.createElement('button');
                useExisting.type = 'button';
                useExisting.className = 'hub-btn hub-btn--secondary mt-2';
                useExisting.textContent = `Use vehicle #${result.json.vehicle.veh_number}`;
                useExisting.addEventListener('click', () => useVehicle(result.json.vehicle));
                errorBox.append(document.createElement('br'), useExisting);
            } else if (result.status === 422) {
                errorBox.textContent = firstError(result.json) || 'Check the vehicle details.';
            } else if (result.status === 429) {
                errorBox.textContent = 'Too many new vehicles were added from this account. Ask Fleet to add it, or try again later.';
            } else {
                errorBox.textContent = 'The vehicle could not be added. Check your connection and try again.';
            }
        } catch {
            errorBox.textContent = 'No connection. Your entries are still here; try again.';
        } finally {
            button.disabled = false;
        }
    });

    // ---------- step 1: driver ----------
    const driverInput = $('#thvi-driver-search');
    const driverResults = $('[data-driver-results]');
    let driverTimer = null;
    let driverRequest = 0;

    driverInput.addEventListener('input', () => {
        window.clearTimeout(driverTimer);
        driverTimer = window.setTimeout(() => searchDrivers(driverInput.value), 250);
    });
    driverInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { event.preventDefault(); window.clearTimeout(driverTimer); searchDrivers(driverInput.value); }
    });

    async function searchDrivers(raw) {
        const query = raw.trim();
        const requestId = ++driverRequest;
        driverResults.innerHTML = '';
        if (query.length < 2) return;
        let data;
        try {
            data = await getJson(`${config.urls.employees}?q=${encodeURIComponent(query)}`);
        } catch (error) {
            if (requestId === driverRequest) driverResults.innerHTML = `<li class="thvi-hint">${escapeHtml(error.message)}</li>`;
            return;
        }
        if (requestId !== driverRequest) return;
        if (data.results.length === 0) {
            const li = document.createElement('li');
            li.className = 'thvi-hint';
            li.textContent = 'No one in the personnel directory matches. Use "Driver not listed" to enter the name.';
            driverResults.append(li);
            return;
        }
        data.results.forEach((employee) => {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'thvi-result';
            const name = document.createElement('span');
            name.className = 'font-bold';
            name.textContent = employee.name;
            const meta = document.createElement('span');
            meta.className = 'thvi-result__meta';
            meta.textContent = employee.employee_id ? `Employee ID ${employee.employee_id}` : 'No employee ID on file';
            button.append(name, meta);
            button.addEventListener('click', () => {
                state.driver = employee;
                state.driverManual = false;
                driverResults.innerHTML = '';
                driverInput.value = '';
                renderDriver();
                saveDraft();
            });
            li.append(button);
            driverResults.append(li);
        });
    }

    $('[data-driver-change]').addEventListener('click', () => {
        state.driver = null;
        renderDriver();
        saveDraft();
        driverInput.focus();
    });
    $('[data-driver-unlisted]').addEventListener('click', () => {
        state.driver = null;
        state.driverManual = true;
        renderDriver();
        $('[data-dm="name"]').focus();
        saveDraft();
    });
    $('[data-driver-search-again]').addEventListener('click', () => {
        state.driverManual = false;
        renderDriver();
        driverInput.focus();
        saveDraft();
    });
    $$('[data-dm]').forEach((input) => input.addEventListener('input', () => {
        if (input.dataset.dm === 'name') state.driverName = input.value; else state.driverNumber = input.value;
        saveDraft();
    }));

    function renderDriver() {
        const chosen = $('[data-driver-chosen]');
        const searchWrap = $('[data-driver-search-wrap]');
        const manual = $('[data-driver-manual]');
        chosen.hidden = !state.driver;
        searchWrap.hidden = Boolean(state.driver) || state.driverManual;
        manual.hidden = !state.driverManual || Boolean(state.driver);
        if (state.driver) {
            $('[data-driver-name]').textContent = state.driver.name;
            $('[data-driver-meta]').textContent = `${state.driver.employee_id ? `Employee ID ${state.driver.employee_id} · ` : ''}Verified personnel record`;
        }
        $('[data-dm="name"]').value = state.driverName;
        $('[data-dm="number"]').value = state.driverNumber;
    }

    // ---------- step 1: inspector, time, odometer ----------
    $('[data-inspector-name]').value = config.inspectorName;
    const clockFormat = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', dateStyle: 'medium', timeStyle: 'short' });
    const tick = () => { $('[data-now]').value = `${clockFormat.format(new Date())} ET (saved when you submit)`; };
    tick();
    window.setInterval(tick, 30000);

    const odometerInput = $('[data-odometer]');
    const odometerNone = $('[data-odometer-none]');
    odometerInput.addEventListener('input', () => {
        const digits = odometerInput.value.replace(/\D/g, '').slice(0, 7);
        if (digits !== odometerInput.value) odometerInput.value = digits;
        state.odometer = digits;
        if (digits) { state.odometerNone = false; odometerNone.checked = false; }
        renderOdometerHint();
        saveDraft();
    });
    odometerNone.addEventListener('change', () => {
        state.odometerNone = odometerNone.checked;
        if (state.odometerNone) { state.odometer = ''; odometerInput.value = ''; }
        renderOdometer();
        saveDraft();
    });

    function renderOdometer() {
        odometerInput.value = state.odometer;
        odometerNone.checked = state.odometerNone;
        odometerInput.disabled = state.odometerNone;
        $('[data-odometer-none-label]').textContent = state.vehicle?.nonroad
            ? 'Non-road asset (trailer, boat, cart): no odometer'
            : 'This vehicle has no working odometer';
        renderOdometerHint();
    }

    function renderOdometerHint() {
        const hint = $('[data-odometer-hint]');
        const last = state.vehicle?.last_odometer;
        let text = last ? `Last recorded: ${last.toLocaleString('en-US')} mi.` : '';
        if (last && state.odometer && Number(state.odometer) < last) text += ' This is lower than the last reading; Fleet will see it flagged.';
        hint.textContent = text;
    }

    // ---------- step 2: checks ----------
    const checksHolder = $('[data-checks]');
    config.checks.forEach((check) => {
        const card = document.createElement('div');
        card.className = 'thvi-check';
        card.dataset.check = check.key;
        card.innerHTML = `
            <div class="thvi-check__head">
                <span class="thvi-label" id="check-${check.key}">${escapeHtml(check.label)}</span>
                <span class="thvi-hint">${escapeHtml(check.hint)}</span>
            </div>
            <div class="thvi-segment" role="radiogroup" aria-labelledby="check-${check.key}">
                <button type="button" role="radio" aria-checked="false" data-value="good">Good</button>
                <button type="button" role="radio" aria-checked="false" data-value="issue">Issue found</button>
                <button type="button" role="radio" aria-checked="false" data-value="not_checked">Not checked</button>
            </div>
            <p class="thvi-error mt-2" data-error="checks.${check.key}"></p>
            <div class="thvi-check__detail" hidden>
                <div class="thvi-field">
                    <label class="thvi-label" for="issue-${check.key}">What is wrong?</label>
                    <textarea id="issue-${check.key}" class="thvi-textarea" maxlength="1000" data-issue-text></textarea>
                </div>
                <div class="thvi-field">
                    <label class="thvi-label" for="notes-${check.key}">Notes (optional)</label>
                    <textarea id="notes-${check.key}" class="thvi-textarea" maxlength="1000" data-issue-notes></textarea>
                </div>
                <div class="thvi-field">
                    <span class="thvi-label">Issue photos (optional)</span>
                    <div class="thvi-thumbs" data-issue-thumbs></div>
                    <div class="thvi-photo__status" data-issue-status role="status" aria-live="polite"></div>
                    <div class="thvi-photo__actions">
                        <label class="hub-btn hub-btn--secondary">Take photo<input class="thvi-visually-hidden-input" type="file" accept="image/*" capture="environment" data-issue-input></label>
                        <label class="hub-btn hub-btn--secondary">Choose existing<input class="thvi-visually-hidden-input" type="file" accept="image/*" data-issue-input></label>
                    </div>
                </div>
            </div>`;
        checksHolder.append(card);

        $$('.thvi-segment button', card).forEach((button) => button.addEventListener('click', () => {
            // Tapping the selected answer again clears it (every check is optional).
            const current = state.checks[check.key].status;
            state.checks[check.key].status = current === button.dataset.value ? null : button.dataset.value;
            renderCheck(check.key);
            const error = $(`[data-error="checks.${check.key}"]`);
            if (error) error.textContent = '';
            card.classList.remove('has-error');
            saveDraft();
            if (state.checks[check.key].status === 'issue') $('[data-issue-text]', card).focus();
        }));
        $('[data-issue-text]', card).addEventListener('input', (event) => { state.checks[check.key].issue_description = event.target.value; saveDraft(); });
        $('[data-issue-notes]', card).addEventListener('input', (event) => { state.checks[check.key].notes = event.target.value; saveDraft(); });
        $$('[data-issue-input]', card).forEach((input) => input.addEventListener('change', () => {
            const file = input.files?.[0];
            input.value = '';
            if (file) uploadIssuePhoto(check.key, file);
        }));
        renderCheck(check.key);
    });

    function renderCheck(key) {
        const card = $(`.thvi-check[data-check="${key}"]`);
        const answer = state.checks[key];
        $$('.thvi-segment button', card).forEach((button) => button.setAttribute('aria-checked', String(button.dataset.value === answer.status)));
        $('.thvi-check__detail', card).hidden = answer.status !== 'issue';
        $('[data-issue-text]', card).value = answer.issue_description;
        $('[data-issue-notes]', card).value = answer.notes;
        const thumbs = $('[data-issue-thumbs]', card);
        thumbs.innerHTML = '';
        state.issuePhotos[key].forEach((photo, index) => {
            const holder = document.createElement('div');
            holder.className = 'thvi-thumb';
            const img = document.createElement('img');
            img.src = photoSrc(photo);
            img.alt = `Issue photo ${index + 1}`;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'hub-link text-sm';
            remove.textContent = 'Remove';
            remove.addEventListener('click', () => {
                state.issuePhotos[key].splice(index, 1);
                renderCheck(key);
                saveDraft();
            });
            holder.append(img, remove);
            thumbs.append(holder);
        });
    }

    async function uploadIssuePhoto(key, file) {
        const card = $(`.thvi-check[data-check="${key}"]`);
        const status = $('[data-issue-status]', card);
        const slotId = `issue:${key}:${Date.now()}`;
        photoSlots[slotId] = { busy: true, checkKey: key };
        status.className = 'thvi-photo__status';
        status.textContent = 'Preparing photo…';
        try {
            const prepared = await preparePhoto(file);
            const result = await uploadPhoto(prepared, 'issue', key, (percent) => { status.textContent = `Uploading ${percent}%`; });
            state.issuePhotos[key].push({ uuid: result.uuid });
            status.className = 'thvi-photo__status is-ok';
            status.textContent = 'Issue photo uploaded.';
            renderCheck(key);
            saveDraft();
        } catch (error) {
            status.className = 'thvi-photo__status is-error';
            status.textContent = `${error.message} Choose the photo again to retry.`;
        } finally {
            delete photoSlots[slotId];
        }
    }

    // ---------- step 3: standard photos ----------
    const photoHolder = $('[data-photos]');
    config.angles.forEach((angle) => {
        const card = document.createElement('div');
        card.className = 'thvi-photo';
        card.dataset.angle = angle.key;
        card.innerHTML = `
            <span class="thvi-label">${escapeHtml(angle.label)}</span>
            <div class="thvi-photo__frame" data-frame><span>No photo yet</span></div>
            <div class="thvi-bar" data-bar hidden><span></span></div>
            <div class="thvi-photo__status" data-status role="status" aria-live="polite">Optional</div>
            <p class="thvi-error" data-error="photos.${angle.key}"></p>
            <div class="thvi-photo__actions">
                <label class="hub-btn hub-btn--secondary"><span data-take-label>Take photo</span><input class="thvi-visually-hidden-input" type="file" accept="image/*" capture="environment" data-photo-input aria-label="Take ${escapeHtml(angle.label)} photo with the camera"></label>
                <label class="hub-btn hub-btn--secondary"><span>Choose existing</span><input class="thvi-visually-hidden-input" type="file" accept="image/*" data-photo-input aria-label="Choose an existing ${escapeHtml(angle.label)} photo"></label>
            </div>
            <div class="flex gap-2">
                <button type="button" class="hub-btn hub-btn--primary hub-btn--compact" data-retry hidden>Retry upload</button>
                <button type="button" class="hub-btn hub-btn--tertiary hub-btn--compact" data-remove hidden>Remove</button>
            </div>`;
        photoHolder.append(card);
        photoSlots[angle.key] = { busy: false, checkKey: null };

        $$('[data-photo-input]', card).forEach((input) => input.addEventListener('change', () => {
            const file = input.files?.[0];
            input.value = '';
            if (file) uploadStandardPhoto(angle, file);
        }));
        $('[data-retry]', card).addEventListener('click', () => {
            const pending = pendingFiles.get(angle.key);
            if (pending) uploadStandardPhoto(angle, pending, true);
        });
        $('[data-remove]', card).addEventListener('click', () => {
            delete state.photos[angle.key];
            pendingFiles.delete(angle.key);
            renderPhoto(angle.key);
            saveDraft();
        });
        renderPhoto(angle.key);
    });

    function renderPhoto(key, uploading = false) {
        const card = $(`.thvi-photo[data-angle="${key}"]`);
        const photo = state.photos[key];
        const frame = $('[data-frame]', card);
        const status = $('[data-status]', card);
        frame.innerHTML = '';
        if (photo) {
            const img = document.createElement('img');
            img.alt = `${config.angles.find((angle) => angle.key === key)?.label} photo`;
            img.src = photoSrc(photo);
            frame.append(img);
        } else {
            const empty = document.createElement('span');
            empty.textContent = uploading ? 'Uploading photo…' : 'No photo yet';
            frame.append(empty);
        }
        if (photo) {
            status.className = 'thvi-photo__status is-ok';
            status.textContent = '✓ Uploaded';
            $('[data-take-label]', card).textContent = 'Retake';
        } else if (!photoSlots[key]?.busy && !pendingFiles.has(key)) {
            status.className = 'thvi-photo__status';
            status.textContent = 'Optional';
            $('[data-take-label]', card).textContent = 'Take photo';
        }
        $('[data-remove]', card).hidden = !photo;
        $('[data-retry]', card).hidden = !(pendingFiles.has(key) && !photo && !photoSlots[key]?.busy);
        const count = config.angles.filter((angle) => state.photos[angle.key]).length;
        $('[data-photo-count]').textContent = `${count} of ${config.angles.length}`;
    }

    async function uploadStandardPhoto(angle, file, isRetry = false) {
        const key = angle.key;
        const card = $(`.thvi-photo[data-angle="${key}"]`);
        const status = $('[data-status]', card);
        const bar = $('[data-bar]', card);
        const errorNode = $(`[data-error="photos.${key}"]`);
        errorNode.textContent = '';
        card.classList.remove('has-error');
        photoSlots[key].busy = true;
        $('[data-retry]', card).hidden = true;
        status.className = 'thvi-photo__status';
        status.textContent = isRetry ? 'Retrying…' : 'Preparing photo…';
        const previous = state.photos[key];
        delete state.photos[key];
        try {
            const prepared = isRetry && file.prepared ? file : await preparePhoto(file);
            prepared.prepared = true;
            pendingFiles.set(key, prepared);
            renderPhoto(key, true);
            bar.hidden = false;
            bar.firstElementChild.style.width = '0%';
            const result = await uploadPhoto(prepared, key, null, (percent) => {
                status.textContent = `Uploading ${percent}%`;
                bar.firstElementChild.style.width = `${percent}%`;
            });
            state.photos[key] = { uuid: result.uuid };
            pendingFiles.delete(key);
            photoSlots[key].busy = false;
            renderPhoto(key);
            saveDraft();
        } catch (error) {
            photoSlots[key].busy = false;
            if (previous && !pendingFiles.has(key)) state.photos[key] = previous;
            renderPhoto(key);
            status.className = 'thvi-photo__status is-error';
            status.textContent = error.message;
            card.classList.add('has-error');
            $('[data-retry]', card).hidden = !pendingFiles.has(key);
        } finally {
            bar.hidden = true;
        }
    }

    /** Resize on the device; fall back to the original file when it cannot be decoded here. */
    async function preparePhoto(file) {
        const name = (file.name || 'photo').replace(/\.[^.]+$/, '');
        try {
            const image = await loadImage(file);
            const width = image.naturalWidth;
            const height = image.naturalHeight;
            if (!width || !height) throw new Error('decode');
            const scale = Math.min(1, MAX_EDGE / Math.max(width, height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(width * scale);
            canvas.height = Math.round(height * scale);
            const context = canvas.getContext('2d');
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, 0, 0, canvas.width, canvas.height);
            URL.revokeObjectURL(image.src);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
            if (!blob) throw new Error('encode');
            return { blob, filename: `${name}.jpg` };
        } catch {
            return { blob: file, filename: file.name || 'photo' };
        }
    }

    function loadImage(file) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.decoding = 'async';
            image.onload = () => resolve(image);
            image.onerror = () => { URL.revokeObjectURL(image.src); reject(new Error('decode')); };
            image.src = URL.createObjectURL(file);
        });
    }

    function uploadPhoto(prepared, angle, checkKey, onProgress) {
        return new Promise((resolve, reject) => {
            const data = new FormData();
            data.append('photo', prepared.blob, prepared.filename);
            data.append('angle', angle === 'issue' ? 'issue' : angle);
            if (checkKey) data.append('check_key', checkKey);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', config.urls.photos);
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.timeout = 180000;
            xhr.upload.onprogress = (event) => {
                if (event.lengthComputable) onProgress(Math.min(99, Math.round((event.loaded / event.total) * 100)));
            };
            xhr.onload = () => {
                let json = {};
                try { json = JSON.parse(xhr.responseText || '{}'); } catch { json = {}; }
                if (xhr.status === 201) { onProgress(100); resolve(json); return; }
                if (xhr.status === 422) { reject(new Error(firstError(json) || 'That photo could not be used. Take it again.')); return; }
                if (xhr.status === 413) { reject(new Error('That photo is too large. Use Take photo instead.')); return; }
                if (xhr.status === 401 || xhr.status === 419) { reject(new Error('Your session expired. Reload the page; your answers are saved.')); return; }
                if (xhr.status === 429) { reject(new Error('Too many uploads at once. Wait a moment, then tap Retry.')); return; }
                reject(new Error('Upload failed. Tap Retry.'));
            };
            xhr.onerror = () => reject(new Error('Upload failed: no connection. Tap Retry when you have signal.'));
            xhr.ontimeout = () => reject(new Error('Upload timed out. Tap Retry.'));
            xhr.send(data);
        });
    }

    // ---------- step 4: final answers ----------
    $$('[data-final]').forEach((group) => {
        $$('button', group).forEach((button) => button.addEventListener('click', () => {
            const value = button.dataset.value === 'yes';
            // Optional answers: tapping the selected one again clears it.
            if (group.dataset.final === 'keys_present') state.keys = state.keys === value ? null : value;
            else state.acceptable = state.acceptable === value ? null : value;
            renderFinal();
            const error = $(`[data-error="${group.dataset.final}"]`);
            if (error) error.textContent = '';
            saveDraft();
        }));
    });
    $('[data-comments]').addEventListener('input', (event) => { state.comments = event.target.value; saveDraft(); });
    $('[data-ack]').addEventListener('change', (event) => { state.ack = event.target.checked; saveDraft(); });

    function renderFinal() {
        const answer = { keys_present: state.keys, acceptable_for_use: state.acceptable };
        $$('[data-final]').forEach((group) => {
            const value = answer[group.dataset.final];
            $$('button', group).forEach((button) => {
                button.setAttribute('aria-checked', String(value !== null && (button.dataset.value === 'yes') === value));
            });
        });
        $('[data-comments]').value = state.comments;
        $('[data-ack]').checked = state.ack;
    }

    // ---------- signature ----------
    const signature = createSignaturePad($('[data-signature]'), $('[data-signature-status]'));
    $('[data-signature-clear]').addEventListener('click', () => signature.clear());

    function createSignaturePad(canvas, statusNode) {
        const strokes = [];
        let current = null;
        const context = canvas.getContext('2d');

        function resize() {
            const ratio = Math.max(1, Math.min(3, window.devicePixelRatio || 1));
            const rect = canvas.getBoundingClientRect();
            if (!rect.width) return;
            canvas.width = Math.round(rect.width * ratio);
            canvas.height = Math.round(rect.height * ratio);
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            redraw();
        }
        function point(event) {
            const rect = canvas.getBoundingClientRect();
            return { x: (event.clientX - rect.left) / rect.width, y: (event.clientY - rect.top) / rect.height };
        }
        function drawStroke(ctx, stroke, width, height, lineWidth = 2.5) {
            ctx.lineWidth = lineWidth;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0f172a';
            ctx.beginPath();
            stroke.forEach((p, index) => {
                if (index === 0) ctx.moveTo(p.x * width, p.y * height); else ctx.lineTo(p.x * width, p.y * height);
            });
            if (stroke.length === 1) ctx.lineTo(stroke[0].x * width + 0.1, stroke[0].y * height + 0.1);
            ctx.stroke();
        }
        function redraw() {
            const rect = canvas.getBoundingClientRect();
            context.clearRect(0, 0, rect.width, rect.height);
            strokes.forEach((stroke) => drawStroke(context, stroke, rect.width, rect.height));
        }
        function update() {
            statusNode.textContent = hasInk() ? 'Signed' : 'Not signed';
            if (hasInk()) $('[data-error="signature"]').textContent = '';
        }
        function hasInk() {
            return strokes.reduce((total, stroke) => total + stroke.length, 0) >= 8;
        }
        canvas.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            canvas.setPointerCapture(event.pointerId);
            current = [point(event)];
            strokes.push(current);
            redraw();
        });
        canvas.addEventListener('pointermove', (event) => {
            if (!current) return;
            event.preventDefault();
            current.push(point(event));
            redraw();
        });
        const end = () => { current = null; update(); };
        canvas.addEventListener('pointerup', end);
        canvas.addEventListener('pointercancel', end);
        window.addEventListener('resize', () => resize());

        return {
            resize: () => window.requestAnimationFrame(resize),
            hasInk,
            clear() { strokes.length = 0; redraw(); update(); },
            toDataUrl() {
                const rect = canvas.getBoundingClientRect();
                const width = 1200;
                const height = Math.min(800, Math.max(240, Math.round(width * (rect.height / Math.max(1, rect.width)))));
                const out = document.createElement('canvas');
                out.width = width;
                out.height = height;
                const ctx = out.getContext('2d');
                strokes.forEach((stroke) => drawStroke(ctx, stroke, width, height, 7));
                return out.toDataURL('image/png');
            },
        };
    }

    // ---------- submit ----------
    $('[data-submit]').addEventListener('click', submit);

    async function submit() {
        if (submitting) return;
        clearErrors();
        const allErrors = {};
        for (let step = 0; step < STEP_COUNT; step++) Object.assign(allErrors, validateStep(step));
        if (Object.keys(allErrors).length > 0) {
            const firstField = Object.keys(allErrors)[0];
            showStep(stepForField(firstField));
            showErrors(allErrors);
            setStatus('Some answers need attention. They are highlighted.');
            return;
        }

        submitting = true;
        const button = $('[data-submit]');
        button.disabled = true;
        button.textContent = 'Submitting…';
        setStatus('Submitting your inspection…');

        const issuePhotos = {};
        Object.entries(state.issuePhotos).forEach(([key, photos]) => {
            if (state.checks[key].status === 'issue') issuePhotos[key] = photos.map((photo) => photo.uuid);
        });
        const photos = {};
        Object.entries(state.photos).forEach(([key, photo]) => { photos[key] = photo.uuid; });
        const vehicle = {};
        Object.entries(state.vehicleFields).forEach(([key, value]) => { vehicle[key] = typeof value === 'string' ? value.trim() : value; });

        const payload = {
            client_submission_id: state.clientSubmissionId,
            started_at: state.startedAt,
            vehicle_id: state.vehicle.id,
            vehicle,
            driver_employee_id: state.driver ? state.driver.id : null,
            driver_name: state.driver ? null : (state.driverName.trim() || null),
            driver_employee_number: state.driver ? null : (state.driverNumber.trim() || null),
            odometer: state.odometerNone || !state.odometer ? null : Number(state.odometer),
            odometer_exception: state.odometerNone ? (state.vehicle.nonroad ? 'nonroad' : 'not_equipped') : null,
            checks: state.checks,
            photos,
            issue_photos: issuePhotos,
            keys_present: state.keys,
            acceptable_for_use: state.acceptable,
            comments: state.comments.trim(),
            acknowledged: state.ack,
            signature: signature.toDataUrl(),
        };

        let succeeded = false;
        try {
            const result = await sendJson(config.urls.submit, payload);
            if (result.ok) {
                succeeded = true;
                storageRemove(DRAFT_KEY);
                setStatus(`Submitted · ${result.json.reference}. Opening your inspections…`);
                button.textContent = 'Submitted';
                window.location.assign(result.json.redirect || config.urls.index);
                return;
            }
            if (result.status === 422) {
                const errors = result.json.errors || {};
                const fields = Object.keys(errors);
                if (fields.some((field) => field.startsWith('photos') || field.startsWith('issue_photos'))) {
                    fields.filter((field) => field.startsWith('photos.')).forEach((field) => { delete state.photos[field.slice(7)]; renderPhoto(field.slice(7)); });
                    fields.filter((field) => field.startsWith('issue_photos.')).forEach((field) => {
                        const key = field.split('.')[1];
                        if (state.issuePhotos[key]) { state.issuePhotos[key] = []; renderCheck(key); }
                    });
                    saveDraft();
                }
                showStep(stepForField(fields[0] || 'signature'));
                showErrors(errors);
                setStatus(result.json.message && fields.length === 0 ? result.json.message : 'Some answers need attention. They are highlighted.');
            } else if (result.status === 401 || result.status === 419) {
                setStatus('Your session expired, so nothing was sent. Reload this page and sign in; your answers are saved on this device (sign again).');
            } else if (result.status === 429) {
                setStatus('Too many attempts. Wait a minute, then tap Submit inspection again.');
            } else {
                setStatus('Not sent: the Hub had a problem. Your inspection is still here. Tap Submit inspection again.');
            }
        } catch {
            setStatus('Not sent: no connection. Your inspection is still here. Tap Submit inspection again when you have signal.');
        } finally {
            if (!succeeded) {
                submitting = false;
                button.disabled = false;
                button.textContent = 'Submit inspection';
            }
        }
    }

    // ---------- helpers ----------
    function firstError(json) {
        const errors = json?.errors || {};
        const first = Object.values(errors)[0];
        return Array.isArray(first) ? first[0] : (json?.message || null);
    }
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    }
    function cssEscape(value) {
        return window.CSS?.escape ? window.CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
    }
    function uuid() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const bytes = window.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    // ---------- start ----------
    $('[data-thvi-reset]')?.addEventListener('click', () => {
        storageRemove(DRAFT_KEY);
        window.location.reload();
    });

    const restored = restoreDraft();
    if (restored) $('[data-thvi-restored]').hidden = false;
    renderVehicle();
    renderDriver();
    renderOdometer();
    config.checks.forEach((check) => renderCheck(check.key));
    config.angles.forEach((angle) => renderPhoto(angle.key));
    renderFinal();
    showStep(restored ? state.step : 0, { focus: false });
}
