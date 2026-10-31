{{--
    Email Center behaviour. Render once, unconditionally, on the page: the composer and the reader appear later
    through Livewire updates and rely on these functions already existing. Inline on purpose, like the Support
    Services composer (components/employee-messages/assets.blade.php): no lazily loaded chunk can go missing
    across a release. No message content is ever written into this script.
--}}
@once
<script>
    (function () {
        'use strict';

        const SHORTCUT_KEY = 'mbfd.admin.singleKeyShortcuts';
        const EMAIL = /^[^\s@<>(),;:"\[\]\\]+@[^\s@<>(),;:"\[\]\\]+\.[^\s@<>(),;:"\[\]\\]+$/;
        const addressOf = (raw) => {
            const angle = /<([^>]+)>/.exec(String(raw));
            return (angle ? angle[1] : String(raw)).trim().replace(/^mailto:/i, '');
        };

        // ---------------------------------------------------------------- busy system (PWA refresh guard)
        const busyRegistry = () => {
            if (!(window.__mbfdBusy instanceof Set)) window.__mbfdBusy = new Set();
            return window.__mbfdBusy;
        };
        const announceBusy = () => window.dispatchEvent(new CustomEvent('mbfd:busy-change', { detail: { reasons: Array.from(busyRegistry()) } }));

        // Every open composer, so a click that would leave the page can save first.
        window.__mbfdMailComposers = window.__mbfdMailComposers || new Set();

        document.addEventListener('click', (event) => {
            if (window.__mbfdMailBypass || window.__mbfdMailComposers.size === 0) return;
            const target = event.target instanceof Element ? event.target.closest('[data-mail-row], [data-mail-nav], [data-mail-back]') : null;
            if (!target || target.closest('[data-mail-compose]')) return;
            const pending = Array.from(window.__mbfdMailComposers).filter((composer) => composer.dirty);
            if (pending.length === 0) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            Promise.allSettled(pending.map((composer) => composer.flush())).then(() => {
                window.__mbfdMailBypass = true;
                try { target.click(); } finally { window.__mbfdMailBypass = false; }
            });
        }, true);

        // ---------------------------------------------------------------- message body frame
        // The body is shown in a sandboxed iframe with no scripts and an opaque origin, so the page cannot read
        // its height. A hidden twin that runs no scripts is laid out at the same width to measure it.
        window.mbfdMailBody = window.mbfdMailBody || function (config) {
            return {
                height: 160,
                measuring: false,
                init() {
                    this.$nextTick(() => this.measure());
                },
                async measure() {
                    if (this.measuring) return;
                    this.measuring = true;
                    let probe = null;
                    try {
                        const response = await fetch(config.src, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
                        if (!response.ok) throw new Error('status ' + response.status);
                        const html = await response.text();
                        const frame = this.$refs.frame;
                        probe = document.createElement('iframe');
                        probe.setAttribute('sandbox', 'allow-same-origin');
                        probe.setAttribute('aria-hidden', 'true');
                        probe.tabIndex = -1;
                        probe.style.cssText = 'position:absolute;left:-10000px;top:0;visibility:hidden;border:0;height:10px;width:' + Math.max(200, frame.clientWidth) + 'px';
                        document.body.appendChild(probe);
                        await new Promise((resolve) => { probe.onload = resolve; probe.srcdoc = html; setTimeout(resolve, 4000); });
                        const measured = probe.contentDocument && probe.contentDocument.documentElement ? probe.contentDocument.documentElement.scrollHeight : 0;
                        if (measured > 0) this.height = Math.min(20000, Math.ceil(measured) + 2);
                    } catch (error) {
                        this.height = Math.max(this.height, 320);
                    } finally {
                        if (probe && probe.parentNode) probe.parentNode.removeChild(probe);
                        this.measuring = false;
                    }
                },
            };
        };

        // ---------------------------------------------------------------- recipients (chips + suggestions)
        window.mbfdMailRecipients = window.mbfdMailRecipients || function (config) {
            return {
                chips: config.state,
                text: '',
                open: false,
                options: [],
                active: -1,
                seq: 0,
                timer: null,

                isValid(chip) { return EMAIL.test(addressOf(chip)); },

                add(raw) {
                    const parts = String(raw).split(/[,;\n]+/).map((part) => part.trim()).filter(Boolean);
                    if (parts.length === 0) return;
                    const next = Array.from(this.chips || []);
                    parts.forEach((part) => {
                        const value = addressOf(part);
                        if (value !== '' && !next.some((existing) => existing.toLowerCase() === value.toLowerCase())) next.push(value);
                    });
                    this.chips = next;
                    this.$dispatch('mail-touch');
                },

                commit() {
                    if (this.text.trim() !== '') {
                        this.add(this.text);
                        this.text = '';
                    }
                    this.close();
                },

                remove(index) {
                    this.chips = (this.chips || []).filter((_, position) => position !== index);
                    this.$dispatch('mail-touch');
                    this.$refs.input.focus();
                },

                close() {
                    this.open = false;
                    this.active = -1;
                },

                onInput() {
                    if (/[,;]\s*$/.test(this.text)) {
                        this.add(this.text);
                        this.text = '';
                        this.close();
                        return;
                    }
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => this.suggest(), 250);
                },

                async suggest() {
                    const query = this.text.trim();
                    if (query.length < 2) {
                        this.options = [];
                        this.close();
                        return;
                    }
                    const turn = ++this.seq;
                    let found = [];
                    try { found = await this.$wire.suggest(query); } catch (error) { found = []; }
                    if (turn !== this.seq) return;
                    const have = (this.chips || []).map((chip) => chip.toLowerCase());
                    this.options = (found || []).filter((option) => !have.includes(String(option.address).toLowerCase()));
                    this.open = this.options.length > 0;
                    this.active = -1;
                },

                pick(option) {
                    this.add(option.address);
                    this.text = '';
                    this.options = [];
                    this.close();
                },

                onKey(event) {
                    switch (event.key) {
                        case 'ArrowDown':
                            if (this.options.length === 0) return;
                            event.preventDefault();
                            this.open = true;
                            this.active = (this.active + 1) % this.options.length;
                            break;
                        case 'ArrowUp':
                            if (this.options.length === 0) return;
                            event.preventDefault();
                            this.active = (this.active - 1 + this.options.length) % this.options.length;
                            break;
                        case 'Enter':
                            if (this.open && this.active >= 0) {
                                event.preventDefault();
                                this.pick(this.options[this.active]);
                            } else if (this.text.trim() !== '') {
                                event.preventDefault();
                                this.commit();
                            }
                            break;
                        case 'Tab':
                            if (this.text.trim() !== '') this.commit();
                            break;
                        case 'Escape':
                            this.close();
                            break;
                        case 'Backspace':
                            if (this.text === '' && (this.chips || []).length > 0) this.remove(this.chips.length - 1);
                            break;
                    }
                },

                onPaste(event) {
                    const pasted = (event.clipboardData || window.clipboardData).getData('text');
                    if (/[,;\n]/.test(pasted)) {
                        event.preventDefault();
                        this.add(pasted);
                    }
                },
            };
        };

        // ---------------------------------------------------------------- composer: autosave + busy guard
        window.mbfdMailComposer = window.mbfdMailComposer || function (config) {
            return {
                limit: config.limit,
                locked: config.locked,
                showCc: config.showCc,
                showBcc: config.showBcc,
                dirty: false,
                saving: false,
                edit: 0,
                timer: null,
                online: navigator.onLine !== false,
                started: Date.now(),
                busyReason: 'email-compose:' + config.draftId,

                init() {
                    window.__mbfdMailComposers.add(this);
                    this.syncBusy();
                    this.onOnline = () => { this.online = true; if (this.dirty) this.flush(); };
                    this.onOffline = () => { this.online = false; };
                    this.onHide = () => { if (document.visibilityState === 'hidden' && this.dirty) this.flush(); };
                    this.onUnload = (event) => {
                        if (this.dirty && !this.locked) {
                            this.flush();
                            event.preventDefault();
                            event.returnValue = '';
                        }
                    };
                    window.addEventListener('online', this.onOnline);
                    window.addEventListener('offline', this.onOffline);
                    document.addEventListener('visibilitychange', this.onHide);
                    window.addEventListener('beforeunload', this.onUnload);
                    document.documentElement.classList.add('mail-compose-open');
                    this.$nextTick(() => this.focusFirst());
                },

                destroy() {
                    window.__mbfdMailComposers.delete(this);
                    clearTimeout(this.timer);
                    window.removeEventListener('online', this.onOnline);
                    window.removeEventListener('offline', this.onOffline);
                    document.removeEventListener('visibilitychange', this.onHide);
                    window.removeEventListener('beforeunload', this.onUnload);
                    document.documentElement.classList.remove('mail-compose-open');
                    this.release();
                },

                focusFirst() {
                    if (this.locked) return;
                    const target = config.focus === 'to'
                        ? this.$root.querySelector('.mail-recipients__input')
                        : this.$root.querySelector('trix-editor');
                    if (target) target.focus();
                },

                get recipientCount() {
                    const all = [].concat(this.$wire.to || [], this.$wire.cc || [], this.$wire.bcc || []);
                    return new Set(all.map((entry) => addressOf(entry).toLowerCase()).filter(Boolean)).size;
                },

                get statusText() {
                    if (this.locked) return '';
                    if (!this.online) return 'Offline. Your changes stay in this window and save when you are back online.';
                    if (this.saving) return 'Saving…';
                    if (this.$wire.saveError) return 'Not saved. See the message below.';
                    if (this.dirty) return 'Unsaved changes';
                    if (this.$wire.savedAt) {
                        const when = new Date(this.$wire.savedAt);
                        return 'Draft saved at ' + when.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', timeZone: 'America/New_York' });
                    }
                    return 'Saved as you type';
                },

                touch(event) {
                    // The editor reports its own start-up as a change; that is not an edit.
                    if (event && event.type === 'trix-change' && Date.now() - this.started < 1500) return;
                    if (this.locked) return;
                    this.dirty = true;
                    this.edit += 1;
                    this.syncBusy();
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => this.flush(), 1500);
                },

                async flush() {
                    clearTimeout(this.timer);
                    if (!this.dirty || this.saving || this.locked) return;
                    if (!this.online) return;
                    const seen = this.edit;
                    this.saving = true;
                    try {
                        await this.$wire.autosave();
                        if (this.edit === seen) this.dirty = false;
                    } catch (error) {
                        // Stay dirty: the guard keeps protecting the text and the next edit retries.
                    } finally {
                        this.saving = false;
                        if (this.dirty) this.timer = setTimeout(() => this.flush(), 3000);
                    }
                },

                syncBusy() {
                    const registry = busyRegistry();
                    const busy = !this.locked;
                    if (busy === registry.has(this.busyReason)) return;
                    if (busy) registry.add(this.busyReason); else registry.delete(this.busyReason);
                    announceBusy();
                },

                release() {
                    if (busyRegistry().delete(this.busyReason)) announceBusy();
                },
            };
        };

        // ---------------------------------------------------------------- keyboard shortcuts
        window.mbfdMailShortcuts = window.mbfdMailShortcuts || function () {
            return {
                helpOpen: false,
                returnFocus: null,
                lastG: 0,

                init() {
                    this.handler = (event) => this.onKey(event);
                    window.addEventListener('keydown', this.handler, true);
                },

                destroy() {
                    window.removeEventListener('keydown', this.handler, true);
                },

                enabled() {
                    try { return window.localStorage.getItem(SHORTCUT_KEY) !== 'off'; } catch (error) { return true; }
                },

                typing(event) {
                    const target = event.target;
                    if (!(target instanceof Element)) return false;
                    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable
                        || target.closest('trix-editor, [role="textbox"], [role="combobox"]') !== null;
                },

                openHelp(event) {
                    this.returnFocus = event && event.currentTarget instanceof HTMLElement ? event.currentTarget : document.activeElement;
                    this.helpOpen = true;
                    this.$nextTick(() => this.$refs.helpClose && this.$refs.helpClose.focus());
                },

                closeHelp() {
                    if (!this.helpOpen) return;
                    this.helpOpen = false;
                    const target = this.returnFocus;
                    this.returnFocus = null;
                    if (target && typeof target.focus === 'function') this.$nextTick(() => target.focus());
                },

                trap(event) {
                    const focusable = Array.from(this.$refs.help.querySelectorAll('button, [href], [tabindex]:not([tabindex="-1"])'))
                        .filter((element) => !element.hasAttribute('disabled'));
                    if (focusable.length === 0) return;
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
                },

                click(selector) {
                    const element = document.querySelector(selector);
                    if (!element || element.disabled) return false;
                    element.click();
                    return true;
                },

                step(direction) {
                    const rows = Array.from(document.querySelectorAll('[data-mail-row]'));
                    if (rows.length === 0) return false;
                    let index = rows.findIndex((row) => row.getAttribute('aria-current') === 'true' || row === document.activeElement);
                    index = index < 0 ? (direction > 0 ? 0 : rows.length - 1) : Math.min(rows.length - 1, Math.max(0, index + direction));
                    const row = rows[index];
                    row.click();
                    row.scrollIntoView({ block: 'nearest' });
                    return true;
                },

                onKey(event) {
                    if (event.isComposing || event.repeat || event.altKey || event.metaKey || event.ctrlKey) return;
                    if (this.helpOpen) {
                        if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); this.closeHelp(); }
                        return;
                    }
                    if (this.typing(event)) return;
                    if (event.target instanceof Element && event.target.closest('[role="dialog"], .fi-modal-window')) return;
                    if (!window.matchMedia('(pointer: fine)').matches || !this.enabled()) return;

                    // "g" starts the panel's go-to sequences; the key after it belongs to that layer.
                    if (event.key === 'g') { this.lastG = Date.now(); return; }
                    if (Date.now() - this.lastG < 1000) return;

                    let handled = false;
                    switch (event.key) {
                        case 'j': handled = this.step(1); break;
                        case 'k': handled = this.step(-1); break;
                        case 'e': handled = this.click('[data-mail-action="archive"]'); break;
                        case 'r': handled = this.click('[data-mail-action="reply"]'); break;
                        case 'a': handled = this.click('[data-mail-action="reply_all"]'); break;
                        case 'f': handled = this.click('[data-mail-action="forward"]'); break;
                        case 'c': handled = this.click('[data-mail-action="compose"]'); break;
                        case '/': {
                            const search = document.querySelector('[data-mail-search]');
                            if (search) { search.focus(); handled = true; }
                            break;
                        }
                        case '?': this.openHelp(); handled = true; break;
                    }
                    if (handled) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    }
                },
            };
        };
    })();
</script>
@endonce
