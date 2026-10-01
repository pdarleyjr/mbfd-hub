{{--
    Desktop keyboard-shortcut layer for the admin panel.

    SAFETY:
      - Keys are ignored inside editable elements (input/textarea/select/contenteditable).
      - The listener only registers on fine-pointer devices.
      - Single-character shortcuts can be turned off (WCAG 2.1.4); the preference persists per browser.
      - Modifier shortcuts stay available: Ctrl/Cmd+K opens global search, Ctrl/Cmd+/ opens this help.

    Registered via PanelsRenderHook::BODY_END in AdminPanelProvider.
--}}
<div
    data-admin-shortcuts-root
    x-data="adminKeyboardShortcuts()"
    aria-hidden="true"
    style="position: absolute; width: 0; height: 0; overflow: hidden;"
></div>

<div
    x-data="adminShortcutsHelp()"
    x-on:open-admin-shortcuts-help.window="show($event.detail?.trigger)"
    x-on:keydown.escape.window="hide()"
    x-show="open"
    x-cloak
    class="mbfd-shortcuts-overlay"
    style="display: none;"
>
    <div
        x-ref="dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mbfd-shortcuts-title"
        aria-describedby="mbfd-shortcuts-description"
        tabindex="-1"
        class="mbfd-shortcuts-dialog"
        @click.outside="hide()"
        @keydown.tab="trapFocus($event)"
    >
        <div class="mbfd-shortcuts-header">
            <h2 id="mbfd-shortcuts-title">Keyboard shortcuts</h2>
            <button type="button" @click="hide()" class="mbfd-shortcuts-close" aria-label="Close keyboard shortcuts">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="mbfd-shortcuts-setting">
            <div>
                <p id="mbfd-shortcuts-toggle-label" class="mbfd-shortcuts-setting-title">Single-key shortcuts</p>
                <p id="mbfd-shortcuts-description" class="mbfd-shortcuts-setting-help">Letter and symbol keys such as <kbd>g</kbd> <kbd>a</kbd>, <kbd>j</kbd>, <kbd>c</kbd> and <kbd>/</kbd>. Turn off if you use speech input or a screen reader. Saved on this device.</p>
            </div>
            <button
                type="button"
                role="switch"
                class="mbfd-shortcuts-switch"
                :aria-checked="enabled.toString()"
                aria-checked="true"
                aria-labelledby="mbfd-shortcuts-toggle-label"
                @click="toggle()"
            >
                <span class="mbfd-shortcuts-switch-thumb" aria-hidden="true"></span>
                <span class="mbfd-shortcuts-switch-text" x-text="enabled ? 'On' : 'Off'">On</span>
            </button>
        </div>
        <p class="mbfd-shortcuts-sr" role="status" aria-live="polite" x-text="announcement"></p>

        <dl class="mbfd-shortcuts-list">
            <div><dt><kbd>Ctrl/Cmd</kbd> + <kbd>K</kbd></dt><dd>Open global search</dd></div>
            <div><dt><kbd>Ctrl/Cmd</kbd> + <kbd>/</kbd></dt><dd>Show this help (always available)</dd></div>
            <div :class="{ 'is-disabled': !enabled }"><dt><kbd>/</kbd></dt><dd>Focus global search</dd></div>
            <div :class="{ 'is-disabled': !enabled }"><dt><kbd>g</kbd> <kbd>a</kbd></dt><dd>Go to Apparatus</dd></div>
            <div :class="{ 'is-disabled': !enabled }"><dt><kbd>g</kbd> <kbd>s</kbd></dt><dd>Go to Stations</dd></div>
            <div :class="{ 'is-disabled': !enabled }"><dt><kbd>g</kbd> <kbd>e</kbd></dt><dd>Go to Employees</dd></div>
            <div :class="{ 'is-disabled': !enabled }"><dt><kbd>g</kbd> <kbd>d</kbd></dt><dd>Go to Dashboard</dd></div>
            <div :class="{ 'is-disabled': !enabled }"><dt><kbd>c</kbd></dt><dd>Create new (context-aware)</dd></div>
            <div :class="{ 'is-disabled': !enabled }"><dt><kbd>j</kbd> / <kbd>k</kbd></dt><dd>Next / previous table row</dd></div>
            <div :class="{ 'is-disabled': !enabled }"><dt><kbd>?</kbd></dt><dd>Show this help</dd></div>
            <div><dt><kbd>Esc</kbd></dt><dd>Close dialog or panel</dd></div>
        </dl>
        <p class="mbfd-shortcuts-footer">Shortcuts never run while you are typing in a field.</p>
    </div>
</div>

<script>
    /**
     * Alpine.js component for desktop-only keyboard shortcuts.
     * Registered globally so it survives Livewire morph updates.
     */
    document.addEventListener('alpine:init', () => {
        const STORAGE_KEY = 'mbfd.admin.singleKeyShortcuts';
        const readStoredEnabled = () => {
            try { return window.localStorage.getItem(STORAGE_KEY) !== 'off'; } catch { return true; }
        };
        let singleKeysEnabled = readStoredEnabled();
        const readEnabled = () => singleKeysEnabled;

        Alpine.data('adminKeyboardShortcuts', () => ({
            keySequence: [],
            sequenceTimer: null,
            keyListener: null,

            init() {
                // Preserve the fine-pointer gate for the desktop shortcut layer.
                if (!window.matchMedia('(pointer: fine)').matches) return;
                this.keyListener = this.handleKeyDown.bind(this);
                document.addEventListener('keydown', this.keyListener);
            },

            destroy() {
                document.removeEventListener('keydown', this.keyListener);
                this.resetSequence();
            },

            isEditableTarget(event) {
                const t = event.target;
                if (!t) return false;
                const tag = t.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
                if (t.isContentEditable || t.closest?.('[role="textbox"]')) return true;
                return false;
            },

            resetSequence() {
                this.keySequence = [];
                if (this.sequenceTimer) clearTimeout(this.sequenceTimer);
            },

            navigate(path) {
                window.location.href = path;
            },

            openHelp() {
                window.dispatchEvent(new CustomEvent('open-admin-shortcuts-help', { detail: { trigger: document.activeElement } }));
            },

            handleKeyDown(event) {
                if (event.isComposing || event.repeat) return;

                // Filament's native search remains available regardless of focus.
                if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault();
                    document.querySelector('.fi-global-search-field input')?.focus();
                    return;
                }

                // Modifier route to help keeps the setting reachable when single keys are off.
                if ((event.metaKey || event.ctrlKey) && event.key === '/') {
                    event.preventDefault();
                    this.openHelp();
                    return;
                }

                if (this.isEditableTarget(event)) return;
                if (event.altKey || event.metaKey || event.ctrlKey) return;
                if (event.target.closest?.('[role="dialog"], .fi-modal-window')) {
                    this.resetSequence();
                    return;
                }
                if (!readEnabled()) {
                    this.resetSequence();
                    return;
                }

                switch (event.key) {
                    case '/':
                        event.preventDefault();
                        document.querySelector('.fi-global-search-field input')?.focus();
                        return;
                    case '?':
                        event.preventDefault();
                        this.openHelp();
                        return;
                    case 'Escape':
                        this.resetSequence();
                        return;
                    case 'j':
                        event.preventDefault();
                        this.moveTableRow(1);
                        return;
                    case 'k':
                        event.preventDefault();
                        this.moveTableRow(-1);
                        return;
                    case 'c':
                        // Trigger the page's primary create action if it exists
                        document.querySelector('[data-create-button], .fi-resource-list-records-page header a[href*="/create"]')?.click();
                        event.preventDefault();
                        return;
                }

                // 2-key sequences (g + next key)
                if (event.key === 'g') {
                    this.keySequence = ['g'];
                    if (this.sequenceTimer) clearTimeout(this.sequenceTimer);
                    this.sequenceTimer = setTimeout(() => this.resetSequence(), 1000);
                    return;
                }

                if (this.keySequence[0] === 'g') {
                    event.preventDefault();
                    const targets = {
                        a: '/admin/apparatuses',
                        s: '/admin/stations',
                        e: '/admin/employees',
                        d: '/admin',
                        w: '/workgroups',
                        t: '/training',
                        p: '/admin/pulse',
                        h: '/admin/health',
                    };
                    const dest = targets[event.key.toLowerCase()];
                    this.resetSequence();
                    if (dest) this.navigate(dest);
                }
            },

            moveTableRow(direction) {
                const rows = Array.from(document.querySelectorAll('.fi-ta-row'));
                if (rows.length === 0) return;
                const currentIdx = rows.findIndex((r) => r.classList.contains('admin-row-focused'));
                let nextIdx = currentIdx + direction;
                if (nextIdx < 0) nextIdx = 0;
                if (nextIdx >= rows.length) nextIdx = rows.length - 1;
                if (currentIdx === nextIdx) return;
                if (currentIdx >= 0) rows[currentIdx].classList.remove('admin-row-focused');
                rows[nextIdx].classList.add('admin-row-focused');
                rows[nextIdx].querySelector('a[href], button:not([disabled]), [tabindex="0"]')?.focus({ preventScroll: true });
                rows[nextIdx].scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            },
        }));

        Alpine.data('adminShortcutsHelp', () => ({
            open: false,
            enabled: readEnabled(),
            announcement: '',
            returnFocus: null,

            show(trigger) {
                this.enabled = readEnabled();
                this.returnFocus = trigger instanceof HTMLElement ? trigger : document.activeElement;
                this.open = true;
                this.$nextTick(() => this.$refs.dialog?.querySelector('button')?.focus());
            },

            hide() {
                if (!this.open) return;
                this.open = false;
                const target = this.returnFocus;
                this.returnFocus = null;
                if (target && typeof target.focus === 'function') this.$nextTick(() => target.focus());
            },

            toggle() {
                this.enabled = !this.enabled;
                singleKeysEnabled = this.enabled;
                this.announcement = this.enabled ? 'Single-key shortcuts turned on.' : 'Single-key shortcuts turned off.';
                try {
                    window.localStorage.setItem(STORAGE_KEY, this.enabled ? 'on' : 'off');
                } catch {
                    this.announcement += ' Applied for this visit; browser storage could not save the device preference.';
                }
            },

            trapFocus(event) {
                const focusable = Array.from(this.$refs.dialog.querySelectorAll('button, [href], [tabindex]:not([tabindex="-1"])'))
                    .filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
                if (focusable.length === 0) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            },
        }));
    });
</script>

<style>
    [x-cloak] { display: none !important; }
    .mbfd-shortcuts-overlay { position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgb(15 23 42 / 0.45); }
    .mbfd-shortcuts-dialog { width: 100%; max-width: 560px; max-height: calc(100vh - 32px); overflow-y: auto; border: 1px solid rgb(220 226 232); border-radius: 12px; background: rgb(255 255 255); color: rgb(15 23 42); box-shadow: 0 24px 48px -12px rgb(16 42 67 / 0.28); font-size: 14px; line-height: 22px; }
    .mbfd-shortcuts-dialog:focus { outline: none; }
    .mbfd-shortcuts-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 8px 8px 20px; border-bottom: 1px solid rgb(220 226 232); }
    .mbfd-shortcuts-header h2 { margin: 0; font-size: 18px; line-height: 26px; font-weight: 600; }
    .mbfd-shortcuts-close { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border: 0; border-radius: 6px; background: transparent; color: rgb(71 85 105); cursor: pointer; }
    .mbfd-shortcuts-close:hover { background: rgb(243 245 248); }
    .mbfd-shortcuts-setting { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 16px 20px; border-bottom: 1px solid rgb(232 236 241); }
    .mbfd-shortcuts-setting-title { margin: 0; font-weight: 600; }
    .mbfd-shortcuts-setting-help { margin: 2px 0 0; color: rgb(71 85 105); font-size: 13px; line-height: 20px; }
    .mbfd-shortcuts-switch { position: relative; display: inline-flex; flex: none; align-items: center; gap: 8px; min-height: 44px; padding: 0 4px; border: 0; background: transparent; color: rgb(30 41 59); font: inherit; font-weight: 600; cursor: pointer; }
    .mbfd-shortcuts-switch::before { content: ''; width: 40px; height: 24px; border-radius: 9999px; background: rgb(100 116 139); transition: background-color 120ms cubic-bezier(.2,0,0,1); }
    .mbfd-shortcuts-switch[aria-checked="true"]::before { background: rgb(30 78 140); }
    .mbfd-shortcuts-switch-thumb { position: absolute; left: 7px; width: 18px; height: 18px; border-radius: 9999px; background: rgb(255 255 255); transition: transform 120ms cubic-bezier(.2,0,0,1); }
    .mbfd-shortcuts-switch[aria-checked="true"] .mbfd-shortcuts-switch-thumb { transform: translateX(16px); }
    .mbfd-shortcuts-switch:focus-visible, .mbfd-shortcuts-close:focus-visible { outline: 2px solid rgb(29 78 216); outline-offset: 2px; }
    .mbfd-shortcuts-sr { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
    .mbfd-shortcuts-list { display: grid; margin: 0; padding: 8px 20px; }
    .mbfd-shortcuts-list > div { display: grid; grid-template-columns: minmax(9rem, 2fr) 3fr; gap: 12px; padding: 6px 0; }
    .mbfd-shortcuts-list > div.is-disabled dd { color: rgb(100 116 139); text-decoration: line-through; }
    .mbfd-shortcuts-list dt { color: rgb(71 85 105); }
    .mbfd-shortcuts-list dd { margin: 0; }
    .mbfd-shortcuts-dialog kbd { display: inline-block; min-width: 1.5em; padding: 0 6px; border: 1px solid rgb(220 226 232); border-radius: 4px; background: rgb(250 251 252); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; line-height: 20px; text-align: center; }
    .mbfd-shortcuts-footer { margin: 0; padding: 12px 20px 16px; border-top: 1px solid rgb(232 236 241); color: rgb(71 85 105); font-size: 13px; line-height: 20px; }
    .fi-ta-row.admin-row-focused {
        outline: 2px solid rgb(29 78 216);
        outline-offset: -2px;
        background-color: rgb(238 243 250);
    }
    @media (prefers-reduced-motion: reduce) {
        .mbfd-shortcuts-switch::before, .mbfd-shortcuts-switch-thumb { transition: none; }
    }
</style>
