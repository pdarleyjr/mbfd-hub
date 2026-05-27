'use client';

import { useMutation, useQuery } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { useSearchParams } from 'next/navigation';
import { Suspense, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { DecisionResultPanel } from '@/app/board/member-detail-drawer';

/**
 * Standalone "Can I grant this leave?" tool. Three-step form: pick a
 * member (typeahead), pick a date + block + leave code, click Check —
 * the engine returns a verdict with every rule it evaluated.
 */
export default function GrantPage(): React.JSX.Element {
  return (
    <Suspense
      fallback={
        <div className="flex items-center gap-2 py-8 text-sm text-stone-500">
          <Loader2 className="h-4 w-4 animate-spin" /> Loading…
        </div>
      }
    >
      <GrantPageInner />
    </Suspense>
  );
}

function GrantPageInner(): React.JSX.Element {
  const sp = useSearchParams();
  const initialMemberId = sp?.get('memberId') ?? '';

  const [memberId, setMemberId] = useState<string>(initialMemberId);
  const [memberSearch, setMemberSearch] = useState('');
  const [dayDate, setDayDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [blockIndex, setBlockIndex] = useState<0 | 1>(0);
  const [leaveCode, setLeaveCode] = useState<string>('');
  const [exchangePartnerId, setExchangePartnerId] = useState('');
  const [partnerSearch, setPartnerSearch] = useState('');
  const [requestedLocalStartHour, setRequestedLocalStartHour] = useState<number | undefined>(undefined);

  // Seed the member-search field's display label when arriving with a
  // memberId from the drawer's "Open in /grant" link.
  useEffect(() => {
    if (!initialMemberId || memberSearch) return;
    void api.memberProfile(initialMemberId).then((p) => {
      setMemberSearch(`${p.member.lastName}, ${p.member.firstName}`);
    });
  }, [initialMemberId, memberSearch]);

  const lc = useQuery({
    queryKey: ['leaveCodes'],
    queryFn: () => api.listLeaveCodes(),
    staleTime: 60_000,
  });

  // Default to the first available code once the list loads — never a
  // hardcoded 'V' that might not exist on this DB.
  useEffect(() => {
    if (!lc.data || leaveCode) return;
    const first = lc.data.leaveCodes[0]?.code;
    if (first) setLeaveCode(first);
  }, [lc.data, leaveCode]);

  const rules = useQuery({
    queryKey: ['staffingRules'],
    queryFn: () => api.getStaffingRules(),
    staleTime: 60_000,
  });

  const memberMatches = useQuery({
    queryKey: ['memberSearch', memberSearch],
    queryFn: () => api.searchMembers(memberSearch),
    enabled: memberSearch.trim().length > 0,
    staleTime: 30_000,
  });

  const partnerMatches = useQuery({
    queryKey: ['memberSearch', partnerSearch],
    queryFn: () => api.searchMembers(partnerSearch),
    enabled: partnerSearch.trim().length > 0,
    staleTime: 30_000,
  });

  const decision = useMutation({
    mutationFn: () =>
      api.staffingDecision({
        memberId,
        dayDate,
        blockIndex,
        leaveCode,
        ...(exchangePartnerId ? { exchangePartnerId } : {}),
        ...(requestedLocalStartHour !== undefined
          ? { requestedLocalStartHour }
          : {}),
      }),
  });

  const exchangeCodes = (rules.data?.rules.exchangeLeaveCodes ?? ['XOFF', 'EON']).map((c) =>
    c.toUpperCase(),
  );
  const isExchange = leaveCode.length > 0 && exchangeCodes.includes(leaveCode.toUpperCase());

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <header>
        <h1 className="font-display text-2xl font-bold tracking-tight text-stone-900">
          Grant tool
        </h1>
        <p className="mt-1 text-sm text-stone-600">
          Check whether a member's requested leave can be granted under the
          current Daily Shift Staffing Guidelines (v1.13). Edit the rules at{' '}
          <a className="text-brand-700 underline" href="/admin/rules">
            /admin/rules
          </a>
          .
        </p>
      </header>

      <section className="grid gap-3 rounded-lg border border-stone-200 bg-white p-4 shadow-sm">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-stone-600">
          1. Member
        </h2>
        <Typeahead
          placeholder="Search by last name, first name, or emp ID"
          value={memberSearch}
          onChange={setMemberSearch}
          matches={memberMatches.data?.matches ?? []}
          loading={memberMatches.isLoading}
          onPick={(m) => {
            setMemberId(m.id);
            setMemberSearch(`${m.lastName}, ${m.firstName}`);
          }}
        />
        {memberId ? (
          <div className="text-xs text-stone-500">Selected member id: {memberId}</div>
        ) : null}
      </section>

      <section className="grid gap-3 rounded-lg border border-stone-200 bg-white p-4 shadow-sm">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-stone-600">
          2. Requested leave
        </h2>
        <div className="flex flex-wrap gap-3">
          <label className="flex flex-col">
            <span className="text-[11px] text-stone-500">Date</span>
            <input
              type="date"
              value={dayDate}
              onChange={(e) => setDayDate(e.target.value)}
              className="h-9 rounded-md border border-stone-200 bg-white px-2 text-sm"
            />
          </label>
          <label className="flex flex-col">
            <span className="text-[11px] text-stone-500">Block</span>
            <select
              value={blockIndex}
              onChange={(e) => setBlockIndex(Number(e.target.value) as 0 | 1)}
              className="h-9 rounded-md border border-stone-200 bg-white px-2 text-sm"
            >
              <option value={0}>AM (08–20)</option>
              <option value={1}>PM (20–08)</option>
            </select>
          </label>
          <label className="flex flex-col">
            <span className="text-[11px] text-stone-500">Leave code</span>
            <select
              value={leaveCode}
              onChange={(e) => setLeaveCode(e.target.value)}
              className="h-9 rounded-md border border-stone-200 bg-white px-2 text-sm"
            >
              {lc.data?.leaveCodes.map((c) => (
                <option key={c.id} value={c.code}>
                  {c.code} — {c.label}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col">
            <span className="text-[11px] text-stone-500">
              Local start hr (optional)
            </span>
            <input
              type="number"
              min={0}
              max={23}
              placeholder="auto"
              value={requestedLocalStartHour ?? ''}
              onChange={(e) =>
                setRequestedLocalStartHour(
                  e.target.value === '' ? undefined : Number(e.target.value),
                )
              }
              className="h-9 w-20 rounded-md border border-stone-200 bg-white px-2 text-sm"
            />
          </label>
        </div>
      </section>

      {isExchange && (
        <section className="grid gap-3 rounded-lg border border-stone-200 bg-white p-4 shadow-sm">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-stone-600">
            3. Exchange partner (required for {exchangeCodes.join(' / ')})
          </h2>
          <Typeahead
            placeholder="Search for the partner"
            value={partnerSearch}
            onChange={setPartnerSearch}
            matches={partnerMatches.data?.matches ?? []}
            loading={partnerMatches.isLoading}
            onPick={(m) => {
              setExchangePartnerId(m.id);
              setPartnerSearch(`${m.lastName}, ${m.firstName}`);
            }}
          />
        </section>
      )}

      <div>
        <Button
          onClick={() => decision.mutate()}
          disabled={!memberId || !leaveCode || decision.isPending}
        >
          {decision.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
          Check decision
        </Button>
        {decision.isError && (
          <div className="mt-2 text-sm text-red-700">
            {(decision.error as Error).message}
          </div>
        )}
      </div>

      {decision.data ? <DecisionResultPanel result={decision.data} /> : null}
    </div>
  );
}

function Typeahead({
  placeholder,
  value,
  onChange,
  matches,
  loading,
  onPick,
}: {
  placeholder: string;
  value: string;
  onChange: (v: string) => void;
  matches: Array<{
    id: string;
    employeeId: string;
    lastName: string;
    firstName: string;
    rank: { code: string; label: string } | null;
    shift: string | null;
  }>;
  loading: boolean;
  onPick: (m: { id: string; lastName: string; firstName: string }) => void;
}): React.JSX.Element {
  const [open, setOpen] = useState(false);
  const wrapperRef = useRef<HTMLDivElement>(null);
  // Close on outside mousedown instead of onBlur — the previous setTimeout-
  // based onBlur racing against onClick lost selections on slow renders.
  useEffect(() => {
    function onDocMouseDown(e: MouseEvent): void {
      if (!wrapperRef.current?.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener('mousedown', onDocMouseDown);
    return () => document.removeEventListener('mousedown', onDocMouseDown);
  }, []);
  return (
    <div ref={wrapperRef} className="relative">
      <input
        type="search"
        placeholder={placeholder}
        value={value}
        onChange={(e) => {
          onChange(e.target.value);
          setOpen(true);
        }}
        onFocus={() => value.length > 0 && setOpen(true)}
        className="h-9 w-full rounded-md border border-stone-200 bg-white px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-700"
      />
      {open && value.trim().length > 0 && (
        <div className="absolute left-0 right-0 top-full z-30 mt-1 max-h-72 overflow-auto rounded-md border border-stone-200 bg-white shadow-lg">
          {loading && (
            <div className="px-3 py-3 text-xs text-stone-500">Searching…</div>
          )}
          {!loading && matches.length === 0 && (
            <div className="px-3 py-3 text-xs text-stone-500">No matches.</div>
          )}
          {matches.map((m) => (
            <button
              type="button"
              key={m.id}
              onClick={() => {
                onPick({ id: m.id, lastName: m.lastName, firstName: m.firstName });
                setOpen(false);
              }}
              className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-stone-100"
            >
              <span className="flex-1 truncate font-medium text-stone-900">
                {m.lastName}, {m.firstName}
              </span>
              <span className="rounded bg-stone-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-stone-700">
                #{m.employeeId}
              </span>
              {m.rank && (
                <span className="rounded bg-admin-850 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                  {m.rank.code}
                </span>
              )}
              {m.shift && (
                <span className="rounded bg-brand-700 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                  {m.shift}
                </span>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
