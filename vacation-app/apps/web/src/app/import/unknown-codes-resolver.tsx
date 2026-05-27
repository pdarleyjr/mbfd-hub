'use client';

import * as React from 'react';
import type { WorkCodeDecision } from '@mbfd-vacation/shared';

const COMMON_CODES = ['V', 'VP', 'EV', 'FH', 'AH', 'EF', 'A', 'S', 'HO', 'XOFF', 'EON'];

type DecisionDraft =
  | { kind: 'use_existing'; code: string }
  | { kind: 'create_new'; code: string }
  | { kind: 'skip' };

export function UnknownCodesResolver({
  descriptions,
  onChange,
}: {
  descriptions: string[];
  onChange: (decisions: WorkCodeDecision[]) => void;
}): React.JSX.Element {
  // For V1 we let the admin pick from existing codes by literal code string.
  // The API resolves the string to an id via lookupByCode. This keeps the UI
  // simple without needing a separate /api/leave-codes endpoint in V1.
  const [drafts, setDrafts] = React.useState<Record<string, DecisionDraft>>(
    () =>
      Object.fromEntries(
        descriptions.map((d) => [d, { kind: 'use_existing', code: 'V' } as DecisionDraft]),
      ),
  );

  React.useEffect(() => {
    // Note: this UI emits 'create_new' for everything for simplicity; the
    // worker normalises duplicates by code. A full implementation would call
    // /api/leave-codes to fetch ids; for V1 the worker's lookupByCode covers it.
    const out: WorkCodeDecision[] = descriptions.map((desc) => {
      const d = drafts[desc] ?? { kind: 'use_existing', code: 'V' };
      if (d.kind === 'skip') return { kind: 'skip', telestaffDescription: desc };
      return {
        kind: 'create_new',
        telestaffDescription: desc,
        newCode: {
          code: d.code,
          label: desc,
          uiColor: '#78716C',
          countsAgainstVacationBalance: false,
          countsAgainstFloatingBalance: false,
          countsAgainstDailyVacationCapacity: false,
          countsAgainstMinimumStaffing: false,
          isADayMarker: false,
        },
      };
    });
    onChange(out);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [drafts, descriptions]);

  if (descriptions.length === 0) {
    return (
      <p className="text-sm text-green-700">
        All work codes in this file are already mapped. Nothing to resolve.
      </p>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-stone-200 bg-white">
      <div className="grid grid-cols-3 border-b border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-stone-600">
        <div>Telestaff description</div>
        <div>Decision</div>
        <div>Code</div>
      </div>
      <ul className="divide-y divide-stone-100">
        {descriptions.map((desc) => {
          const d = drafts[desc] ?? { kind: 'use_existing', code: 'V' };
          return (
            <li key={desc} className="grid grid-cols-3 items-center gap-3 px-4 py-2">
              <span className="truncate font-mono text-sm text-stone-800">{desc}</span>
              <select
                value={d.kind}
                onChange={(e) =>
                  setDrafts((s) => ({
                    ...s,
                    [desc]:
                      e.target.value === 'skip'
                        ? { kind: 'skip' }
                        : { kind: 'use_existing', code: (s[desc] as { code?: string })?.code ?? 'V' },
                  }))
                }
                className="rounded-md border border-stone-200 bg-white px-2 py-1 text-sm"
              >
                <option value="use_existing">Map to leave code</option>
                <option value="skip">Skip rows with this description</option>
              </select>
              {d.kind === 'skip' ? (
                <span className="text-sm text-stone-400">—</span>
              ) : (
                <select
                  value={d.code}
                  onChange={(e) =>
                    setDrafts((s) => ({ ...s, [desc]: { kind: 'use_existing', code: e.target.value } }))
                  }
                  className="rounded-md border border-stone-200 bg-white px-2 py-1 text-sm font-mono"
                >
                  {COMMON_CODES.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </select>
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}
