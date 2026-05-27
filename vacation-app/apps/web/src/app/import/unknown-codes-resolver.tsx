'use client';

import { useQuery } from '@tanstack/react-query';
import * as React from 'react';
import type { WorkCodeDecision } from '@mbfd-vacation/shared';
import { api } from '@/lib/api';

type DecisionDraft =
  | { kind: 'use_existing'; leaveCodeId: string }
  | { kind: 'skip' };

/**
 * For each unrecognised Telestaff event description, the admin chooses
 * either "map to an existing leave code" or "skip rows with this
 * description". The chosen decision is sent to the worker on commit and
 * a row is written to work_code_mappings so future imports auto-resolve.
 *
 * V1 deliberately does not expose "create new leave code" in the UI — the
 * seeded codes (V, FH, AH, EF, A, S, etc.) cover every Telestaff export
 * we've seen. Adding new codes is a Phase 2 admin concern that needs
 * policy flags (counts_against_vacation_balance, etc.) and a proper form.
 */
export function UnknownCodesResolver({
  descriptions,
  onChange,
}: {
  descriptions: string[];
  onChange: (decisions: WorkCodeDecision[]) => void;
}): React.JSX.Element {
  const codesQuery = useQuery({
    queryKey: ['leave-codes'],
    queryFn: () => api.listLeaveCodes(),
  });

  const codes = codesQuery.data?.leaveCodes ?? [];
  const defaultCodeId = React.useMemo(() => {
    if (codes.length === 0) return '';
    return codes.find((c) => c.code === 'V')?.id ?? codes[0]!.id;
  }, [codes]);

  const [drafts, setDrafts] = React.useState<Record<string, DecisionDraft>>({});

  React.useEffect(() => {
    if (descriptions.length === 0 || !defaultCodeId) return;
    setDrafts((prev) => {
      const next: Record<string, DecisionDraft> = { ...prev };
      let changed = false;
      for (const d of descriptions) {
        if (!next[d]) {
          next[d] = { kind: 'use_existing', leaveCodeId: defaultCodeId };
          changed = true;
        }
      }
      return changed ? next : prev;
    });
  }, [descriptions, defaultCodeId]);

  React.useEffect(() => {
    const out: WorkCodeDecision[] = [];
    for (const desc of descriptions) {
      const d = drafts[desc];
      if (!d) continue;
      if (d.kind === 'skip') {
        out.push({ kind: 'skip', telestaffDescription: desc });
      } else {
        out.push({
          kind: 'use_existing',
          telestaffDescription: desc,
          leaveCodeId: d.leaveCodeId,
        });
      }
    }
    onChange(out);
  }, [drafts, descriptions, onChange]);

  if (descriptions.length === 0) {
    return (
      <p className="text-sm text-green-700">
        All work codes in this file are already mapped. Nothing to resolve.
      </p>
    );
  }

  if (codesQuery.isLoading) {
    return <p className="text-sm text-stone-600">Loading available leave codes…</p>;
  }

  if (codesQuery.isError) {
    return (
      <p className="text-sm text-red-700">
        Could not load leave codes: {(codesQuery.error as Error).message}
      </p>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-stone-200 bg-white">
      <div className="grid grid-cols-[1fr_180px_240px] border-b border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-stone-600">
        <div>Telestaff description</div>
        <div>Decision</div>
        <div>Map to leave code</div>
      </div>
      <ul className="divide-y divide-stone-100">
        {descriptions.map((desc) => {
          const d: DecisionDraft =
            drafts[desc] ?? { kind: 'use_existing', leaveCodeId: defaultCodeId };
          return (
            <li
              key={desc}
              className="grid grid-cols-[1fr_180px_240px] items-center gap-3 px-4 py-2"
            >
              <span className="truncate font-mono text-sm text-stone-800" title={desc}>
                {desc}
              </span>
              <select
                value={d.kind}
                onChange={(e) =>
                  setDrafts((s) => {
                    const v = e.target.value;
                    if (v === 'skip') return { ...s, [desc]: { kind: 'skip' } };
                    const existingId =
                      s[desc]?.kind === 'use_existing'
                        ? (s[desc] as { leaveCodeId: string }).leaveCodeId
                        : defaultCodeId;
                    return {
                      ...s,
                      [desc]: { kind: 'use_existing', leaveCodeId: existingId },
                    };
                  })
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
                  value={d.leaveCodeId}
                  onChange={(e) =>
                    setDrafts((s) => ({
                      ...s,
                      [desc]: { kind: 'use_existing', leaveCodeId: e.target.value },
                    }))
                  }
                  className="rounded-md border border-stone-200 bg-white px-2 py-1 text-sm font-mono"
                >
                  {codes.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.code} — {c.label}
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
