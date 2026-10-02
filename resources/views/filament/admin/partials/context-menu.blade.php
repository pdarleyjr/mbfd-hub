{{--
    Right-click context menu for Filament admin tables.

    Listens for contextmenu on .fi-ta-row, prevents the browser default,
    and shows a small action sheet anchored at the cursor. Actions are
    sourced from data-* attributes on the row's existing Filament actions
    so we don't fork the action system.

    Gated by `pointer:fine` — phones never see this even if they could fire
    a contextmenu event via long-press.
--}}
<div
    data-admin-context-menu
    x-ref="menu"
    x-data="adminContextMenu()"
    x-show="open"
    x-cloak
    @click.outside="close()"
    @keydown.escape.window="close()"
    :style="`position:fixed; top:${y}px; left:${x}px; z-index:9997;`"
    class="min-w-[180px] rounded-md border border-slate-200 bg-white py-1 text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
    role="group"
    aria-label="Record actions"
    style="display: none;"
>
    <template x-for="(item, idx) in items" :key="idx">
        <button
            type="button"
            :disabled="item.disabled || false"
            @click="run(item)"
            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
        >
            <span class="text-slate-400" x-text="item.icon || '·'"></span>
            <span x-text="item.label"></span>
        </button>
    </template>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('adminContextMenu', () => ({
            open: false,
            x: 0,
            y: 0,
            items: [],
            contextListener: null,
            returnFocus: null,

            init() {
                if (!window.matchMedia('(pointer: fine)').matches) return;
                this.contextListener = (event) => {
                    const row = event.target.closest('.fi-ta-row');
                    if (!row) return;
                    event.preventDefault();
                    this.buildItems(row);
                    this.returnFocus = document.activeElement;
                    this.x = event.clientX;
                    this.y = event.clientY;
                    this.open = true;
                    this.$nextTick(() => {
                        const bounds = this.$refs.menu.getBoundingClientRect();
                        this.x = Math.max(8, Math.min(this.x, window.innerWidth - bounds.width - 8));
                        this.y = Math.max(8, Math.min(this.y, window.innerHeight - bounds.height - 8));
                        this.$refs.menu.querySelector('button:not([disabled])')?.focus();
                    });
                };
                document.addEventListener('contextmenu', this.contextListener);
            },

            destroy() {
                document.removeEventListener('contextmenu', this.contextListener);
            },

            buildItems(row) {
                const items = [];
                // Look for Filament edit / view / delete buttons within the row
                const view = row.querySelector('[aria-label*="View" i], a[href*="/view"], a[href*="/edit"]');
                if (view) items.push({ label: 'Open', icon: '↗', node: view });

                const popout = row.querySelector('a[href]');
                if (popout) items.push({ label: 'Open in new window', icon: '⧉', popout: popout.getAttribute('href') });

                const allButtons = row.querySelectorAll('.fi-ta-actions button, .fi-ta-actions a');
                allButtons.forEach((btn) => {
                    const label = btn.getAttribute('aria-label') || btn.textContent.trim();
                    if (!label || label.length < 2) return;
                    if (items.some((i) => i.label === label)) return;
                    items.push({ label, icon: '·', node: btn });
                });

                if (items.length === 0) {
                    items.push({ label: 'No actions available', icon: '·', disabled: true });
                }
                this.items = items.slice(0, 8);
            },

            run(item) {
                this.close();
                if (item.disabled) return;
                if (item.popout) {
                    window.open(item.popout, '_blank', 'popup,noopener,noreferrer,width=1200,height=800');
                    return;
                }
                if (item.node && typeof item.node.click === 'function') {
                    item.node.click();
                }
            },

            close() {
                if (!this.open) return;
                this.open = false;
                this.items = [];
                const target = this.returnFocus;
                this.returnFocus = null;
                if (target && typeof target.focus === 'function') this.$nextTick(() => target.focus());
            },
        }));
    });
</script>
