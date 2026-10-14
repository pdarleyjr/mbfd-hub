import { useRef, type KeyboardEvent, type ReactNode } from 'react';

export interface TabItem<T extends string> {
  id: T;
  label: string;
  /** Count shown after the label; `badgeLabel` gives screen readers its meaning. */
  badge?: number;
  badgeLabel?: string;
}

interface TabsProps<T extends string> {
  tabs: TabItem<T>[];
  active: T;
  onChange: (id: T) => void;
  label: string;
  idPrefix: string;
  /** Renders the active panel only; inactive panels stay mounted, hidden and empty. */
  renderPanel: (id: T) => ReactNode;
  panelClassName?: string;
}

/**
 * WAI-ARIA tabs with automatic activation: Left/Right arrows, Home and End move between tabs.
 * The active indicator is CSS only (an inset bottom rule), so nothing is measured in JavaScript.
 */
export default function Tabs<T extends string>({ tabs, active, onChange, label, idPrefix, renderPanel, panelClassName = '' }: TabsProps<T>) {
  const tabRefs = useRef<Partial<Record<T, HTMLButtonElement | null>>>({});

  const activate = (id: T) => {
    onChange(id);
    const tab = tabRefs.current[id];
    tab?.focus();
    tab?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  };

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const index = tabs.findIndex((tab) => tab.id === active);
    const targets: Record<string, number> = {
      ArrowRight: (index + 1) % tabs.length,
      ArrowLeft: (index - 1 + tabs.length) % tabs.length,
      Home: 0,
      End: tabs.length - 1,
    };
    if (!(event.key in targets) || event.altKey || event.ctrlKey || event.metaKey) return;
    event.preventDefault();
    activate(tabs[targets[event.key]].id);
  };

  return (
    <>
      <div role="tablist" aria-label={label} onKeyDown={onKeyDown} className="flex overflow-x-auto overscroll-x-contain border-b border-hub-glass-border px-2 scroll-snap-x-mandatory">
        {tabs.map((tab) => {
          const selected = tab.id === active;
          return (
            <button
              key={tab.id}
              ref={(element) => { tabRefs.current[tab.id] = element; }}
              id={`${idPrefix}-tab-${tab.id}`}
              type="button"
              role="tab"
              aria-selected={selected}
              aria-controls={`${idPrefix}-panel-${tab.id}`}
              tabIndex={selected ? 0 : -1}
              onClick={() => activate(tab.id)}
              className={`scroll-snap-align-start relative inline-flex min-h-12 flex-none items-center gap-2 whitespace-nowrap px-4 text-sm font-semibold transition-colors ${selected
                ? 'text-hub-blue shadow-[inset_0_-2px_0_rgb(var(--hub-blue))]'
                : 'text-hub-n-600 hover:text-hub-ink hover:shadow-[inset_0_-2px_0_rgb(var(--hub-n-200))]'}`}
            >
              {tab.label}
              {typeof tab.badge === 'number' && tab.badge > 0 && (
                <span className="hub-tag hub-tag--info tabular-nums">
                  {tab.badge}
                  {tab.badgeLabel && <span className="sr-only"> {tab.badgeLabel}</span>}
                </span>
              )}
            </button>
          );
        })}
      </div>
      {tabs.map((tab) => (
        <div
          key={tab.id}
          id={`${idPrefix}-panel-${tab.id}`}
          role="tabpanel"
          aria-labelledby={`${idPrefix}-tab-${tab.id}`}
          hidden={tab.id !== active}
          tabIndex={0}
          className={panelClassName}
        >
          {tab.id === active && renderPanel(tab.id)}
        </div>
      ))}
    </>
  );
}
