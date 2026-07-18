{{--
    Desktop keyboard-shortcut layer for the admin panel.

    Listens at the document level for vim-style navigation, Cmd+K for the
    spotlight palette, and standard IDE patterns (/ for search, ? for help).

    SAFETY:
      - All keys are ignored when the focus is inside an editable element
        (input/textarea/contenteditable) — never steal user typing.
      - The whole block is gated by `matchMedia('(pointer: fine)')` so a
        touch-primary mobile/tablet device never executes this code.
      - Failure modes are silent (no console noise on production).

    Registered via PanelsRenderHook::BODY_END in AdminPanelProvider.
--}}
<div
    data-admin-shortcuts-root
    x-data="adminKeyboardShortcuts()"
    x-init="init()"
    aria-hidden="true"
    style="position: absolute; width: 0; height: 0; overflow: hidden;"
></div>

<div
    x-data="{ open: false }"
    x-on:open-admin-shortcuts-help.window="open = true"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm"
    style="display: none;"
>
    <div class="w-full max-w-xl rounded-lg bg-white shadow-2xl dark:bg-slate-900" @click.outside="open = false">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-700">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Keyboard Shortcuts</h2>
            <button type="button" @click="open = false" class="rounded p-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Close">
                &times;
            </button>
        </div>
        <div class="grid grid-cols-2 gap-x-6 gap-y-2 px-5 py-4 text-sm">
            <div class="font-mono text-slate-600 dark:text-slate-400">Ctrl/Cmd + K</div><div class="text-slate-800 dark:text-slate-200">Open command palette</div>
            <div class="font-mono text-slate-600 dark:text-slate-400">/</div><div class="text-slate-800 dark:text-slate-200">Focus global search</div>
            <div class="font-mono text-slate-600 dark:text-slate-400">g a</div><div class="text-slate-800 dark:text-slate-200">Go to Apparatus</div>
            <div class="font-mono text-slate-600 dark:text-slate-400">g s</div><div class="text-slate-800 dark:text-slate-200">Go to Stations</div>
            <div class="font-mono text-slate-600 dark:text-slate-400">g e</div><div class="text-slate-800 dark:text-slate-200">Go to Employees</div>
            <div class="font-mono text-slate-600 dark:text-slate-400">g d</div><div class="text-slate-800 dark:text-slate-200">Go to Dashboard</div>
            <div class="font-mono text-slate-600 dark:text-slate-400">c</div><div class="text-slate-800 dark:text-slate-200">Create new (context-aware)</div>
            <div class="font-mono text-slate-600 dark:text-slate-400">j / k</div><div class="text-slate-800 dark:text-slate-200">Next / previous table row</div>
            <div class="font-mono text-slate-600 dark:text-slate-400">Esc</div><div class="text-slate-800 dark:text-slate-200">Close modal / slide-over</div>
            <div class="font-mono text-slate-600 dark:text-slate-400">?</div><div class="text-slate-800 dark:text-slate-200">Show this help</div>
        </div>
        <div class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
            Shortcuts are disabled while typing in input fields.
        </div>
    </div>
</div>

<script>
    /**
     * Alpine.js component for desktop-only keyboard shortcuts.
     * Registered globally so it survives Livewire morph updates.
     */
    document.addEventListener('alpine:init', () => {
        Alpine.data('adminKeyboardShortcuts', () => ({
            keySequence: [],
            sequenceTimer: null,

            init() {
                // Gate: pointer:fine implies physical keyboard + mouse — never on phones
                if (!window.matchMedia('(pointer: fine)').matches) return;
                document.addEventListener('keydown', this.handleKeyDown.bind(this));
            },

            isEditableTarget(event) {
                const t = event.target;
                if (!t) return false;
                const tag = t.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
                if (t.isContentEditable) return true;
                return false;
            },

            resetSequence() {
                this.keySequence = [];
                if (this.sequenceTimer) clearTimeout(this.sequenceTimer);
            },

            navigate(path) {
                window.location.href = path;
            },

            handleKeyDown(event) {
                // Cmd/Ctrl+K opens spotlight regardless of focus
                if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault();
                    window.dispatchEvent(new CustomEvent('open-filament-spotlight'));
                    return;
                }

                if (this.isEditableTarget(event)) return;
                if (event.altKey || event.metaKey || event.ctrlKey) return;

                switch (event.key) {
                    case '/':
                        event.preventDefault();
                        document.querySelector('.fi-global-search-field input')?.focus();
                        return;
                    case '?':
                        event.preventDefault();
                        window.dispatchEvent(new CustomEvent('open-admin-shortcuts-help'));
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
                        a: '/admin/apparatus',
                        s: '/admin/stations',
                        e: '/admin/users',
                        d: '/admin',
                        w: '/workgroups',
                        t: '/admin/training-todos',
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
                rows[nextIdx].scrollIntoView({ block: 'center', behavior: 'smooth' });
            },
        }));
    });
</script>

<style>
    [x-cloak] { display: none !important; }
    .fi-ta-row.admin-row-focused {
        outline: 2px solid rgb(220 38 38 / 0.5);
        outline-offset: -2px;
        background-color: rgb(254 242 242 / 0.5);
    }
    .dark .fi-ta-row.admin-row-focused {
        background-color: rgb(127 29 29 / 0.15);
    }
</style>
