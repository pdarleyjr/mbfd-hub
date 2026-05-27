'use client';

import * as Dialog from '@radix-ui/react-dialog';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, ExternalLink, Loader2, ShieldAlert, ShieldCheck, X, XCircle } from 'lucide-react';
import Link from 'next/link';
import { useState } from 'react';
import { api, type MemberProfile } from '@/lib/api';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { DecisionResult, StaffingRules } from '@mbfd-vacation/shared';

const EXCHANGE_DEFAULT = ['XOFF', 'EON'];

/**
 * Side drawer that shows everything an admin needs about a member when
 * deciding leave: YTD usage per code, capacity bars for exchange caps,
 * an inline "check this date" decision tool, and editable station +
 * certifications fields (used by the engine's Marine / DE / AT rules).
 */
export function MemberDetailDrawer({
  memberId,
  open,
  onOpenChange,
}: {
  memberId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}): React.JSX.Element {
  const profile = useQuery({
    queryKey: ['memberProfile', memberId],
    queryFn: () => api.memberProfile(memberId!),
    enabled: open && Boolean(memberId),
    staleTime: 10_000,
  });

  const rules = useQuery({
    queryKey: ['staffingRules'],
    queryFn: () => api.getStaffingRules(),
    staleTime: 60_000,
  });

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-40 bg-black/40 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0" />
        <Dialog.Content className="fixed inset-y-0 right-0 z-50 flex w-full max-w-xl flex-col bg-white shadow-xl outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:slide-in-from-right data-[state=closed]:slide-out-to-right">
          <div className="flex items-center justify-between border-b border-stone-200 bg-admin-850 px-4 py-3 text-white">
            <Dialog.Title className="text-sm font-semibold uppercase tracking-wide">
              Member detail
            </Dialog.Title>
            <Dialog.Close asChild>
              <button
                type="button"
                aria-label="Close"
                className="rounded p-1 text-stone-200 hover:bg-white/10 hover:text-white"
              >
                <X className="h-4 w-4" />
              </button>
            </Dialog.Close>
          </div>
          <div className="flex-1 overflow-auto p-4">
            {profile.isLoading && (
              <div className="flex items-center gap-2 py-8 text-sm text-stone-500">
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> Loading…
              </div>
            )}
            {profile.isError && (
              <div className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                Could not load: {(profile.error as Error).message}
              </div>
            )}
            {profile.data && rules.data && (
              <MemberDetailBody
                profile={profile.data}
                rules={rules.data.rules}
              />
            )}
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

function MemberDetailBody({
  profile,
  rules,
}: {
  profile: MemberProfile;
  rules: StaffingRules;
}): React.JSX.Element {
  const exchangeCodes = (rules.exchangeLeaveCodes.length > 0
    ? rules.exchangeLeaveCodes
    : EXCHANGE_DEFAULT
  ).map((c) => c.toUpperCase());

  const exchangeBalance = profile.balances
    .filter((b) => exchangeCodes.includes(b.code.toUpperCase()))
    .reduce(
      (acc, b) => ({ shifts: acc.shifts + b.entries, hours: acc.hours + b.hours }),
      { shifts: 0, hours: 0 },
    );

  return (
    <div className="flex flex-col gap-4">
      <MemberHeader profile={profile} />

      <MemberEditor profile={profile} rules={rules} />

      <BalancesCard balances={profile.balances} />

      <CapacityCard
        shiftsUsed={exchangeBalance.shifts}
        shiftsCap={rules.exchangeShiftsCapPerYear}
        hoursUsed={exchangeBalance.hours}
        hoursCap={rules.exchangeHoursCapPerYear}
      />

      <DecisionCheckCard profile={profile} />

      <EntriesList entries={profile.entries} />
    </div>
  );
}

function MemberHeader({ profile }: { profile: MemberProfile }): React.JSX.Element {
  const m = profile.member;
  return (
    <div className="flex items-start justify-between gap-2">
      <div>
        <h2 className="font-display text-2xl font-bold tracking-tight text-stone-900">
          {m.lastName}, {m.firstName}
        </h2>
        <div className="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-stone-600">
          <span className="font-mono">#{m.employeeId}</span>
          <span aria-hidden>·</span>
          {m.rank ? <Badge tone="admin">{m.rank.code}</Badge> : null}
          {m.shift ? <Badge tone="brand">{m.shift}</Badge> : null}
          {m.station ? <Badge>{m.station}</Badge> : null}
          {m.isProbationary ? <Badge tone="warning">Probationary</Badge> : null}
        </div>
      </div>
      <Link
        href={`/grant?memberId=${m.id}`}
        className="inline-flex items-center gap-1 rounded-md border border-stone-200 bg-white px-2 py-1 text-xs font-medium text-stone-700 hover:bg-stone-100"
      >
        Open in /grant <ExternalLink className="h-3 w-3" aria-hidden />
      </Link>
    </div>
  );
}

function BalancesCard({
  balances,
}: {
  balances: MemberProfile['balances'];
}): React.JSX.Element {
  if (balances.length === 0) {
    return (
      <div className="rounded-md border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-600">
        No leave entries on file this year.
      </div>
    );
  }
  const totalHours = balances.reduce((s, b) => s + b.hours, 0);
  return (
    <div className="rounded-md border border-stone-200 bg-white">
      <div className="border-b border-stone-200 px-3 py-2">
        <div className="text-xs font-semibold uppercase tracking-wide text-stone-600">
          YTD usage
        </div>
        <div className="text-sm text-stone-800">
          {totalHours.toLocaleString()} hrs · {balances.reduce((s, b) => s + b.entries, 0)} entries
        </div>
      </div>
      <ul className="divide-y divide-stone-100">
        {balances.map((b) => (
          <li key={b.leaveCodeId} className="flex items-center gap-2 px-3 py-2 text-sm">
            <span
              className="inline-block h-3 w-3 shrink-0 rounded-sm"
              style={{ backgroundColor: b.uiColor }}
              aria-hidden
            />
            <span className="flex-1 truncate">
              <span className="font-semibold text-stone-900">{b.code}</span>{' '}
              <span className="text-stone-600">{b.label}</span>
            </span>
            <span className="font-mono text-xs tabular-nums text-stone-700">
              {b.hours.toLocaleString()} hr
            </span>
            <span className="font-mono text-xs tabular-nums text-stone-500">
              {b.entries}×
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function CapacityCard({
  shiftsUsed,
  shiftsCap,
  hoursUsed,
  hoursCap,
}: {
  shiftsUsed: number;
  shiftsCap: number;
  hoursUsed: number;
  hoursCap: number;
}): React.JSX.Element {
  return (
    <div className="rounded-md border border-stone-200 bg-white px-3 py-3">
      <div className="text-xs font-semibold uppercase tracking-wide text-stone-600">
        Exchange cap (per calendar year)
      </div>
      <CapacityRow label="Shifts" used={shiftsUsed} cap={shiftsCap} />
      <CapacityRow label="Hours" used={hoursUsed} cap={hoursCap} unit="hr" />
    </div>
  );
}

function CapacityRow({
  label,
  used,
  cap,
  unit,
}: {
  label: string;
  used: number;
  cap: number;
  unit?: string;
}): React.JSX.Element {
  const pct = cap === 0 ? 0 : Math.min(100, Math.round((used / cap) * 100));
  const over = used >= cap;
  return (
    <div className="mt-2">
      <div className="flex items-center justify-between text-sm">
        <span className="text-stone-700">{label}</span>
        <span className={cn('font-mono tabular-nums', over ? 'text-red-700' : 'text-stone-900')}>
          {used.toLocaleString()}
          {unit ? ` ${unit}` : ''} / {cap.toLocaleString()}
          {unit ? ` ${unit}` : ''}
        </span>
      </div>
      <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-stone-100">
        <div
          className={cn(
            'h-full',
            over ? 'bg-red-600' : pct >= 80 ? 'bg-amber-500' : 'bg-brand-700',
          )}
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  );
}

function EntriesList({
  entries,
}: {
  entries: MemberProfile['entries'];
}): React.JSX.Element {
  if (entries.length === 0) return <></>;
  return (
    <details className="rounded-md border border-stone-200 bg-white">
      <summary className="cursor-pointer px-3 py-2 text-xs font-semibold uppercase tracking-wide text-stone-600 hover:bg-stone-50">
        All entries ({entries.length})
      </summary>
      <ul className="max-h-72 overflow-auto divide-y divide-stone-100">
        {entries.map((e) => (
          <li key={e.id} className="flex items-center gap-2 px-3 py-1.5 text-xs">
            <span className="font-mono text-stone-700">{e.dayDate}</span>
            <span className="rounded bg-stone-200 px-1 font-mono text-[10px] text-stone-600">
              {e.blockIndex === 0 ? 'AM' : 'PM'}
            </span>
            <span
              className="inline-block h-2.5 w-2.5 shrink-0 rounded-sm"
              style={{ backgroundColor: e.leaveCode.uiColor }}
              aria-hidden
            />
            <span className="flex-1 truncate text-stone-800">
              {e.leaveCode.code} — {e.leaveCode.label}
            </span>
            {e.hours != null && (
              <span className="font-mono tabular-nums text-stone-500">{e.hours}h</span>
            )}
          </li>
        ))}
      </ul>
    </details>
  );
}

function MemberEditor({
  profile,
  rules,
}: {
  profile: MemberProfile;
  rules: StaffingRules;
}): React.JSX.Element {
  const qc = useQueryClient();
  const [station, setStation] = useState<string>(profile.member.station ?? '');
  const [certs, setCerts] = useState<string[]>(profile.member.certifications);
  const [dirty, setDirty] = useState(false);

  const mut = useMutation({
    mutationFn: () =>
      api.updateMember(profile.member.id, {
        station: station.length === 0 ? null : station,
        certifications: certs,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['memberProfile', profile.member.id] });
      qc.invalidateQueries({ queryKey: ['board'] });
      setDirty(false);
    },
  });

  const toggleCert = (c: string): void => {
    setCerts((curr) => (curr.includes(c) ? curr.filter((x) => x !== c) : [...curr, c]));
    setDirty(true);
  };

  return (
    <details className="rounded-md border border-stone-200 bg-stone-50">
      <summary className="cursor-pointer px-3 py-2 text-xs font-semibold uppercase tracking-wide text-stone-600 hover:bg-stone-100">
        Edit station & certifications
      </summary>
      <div className="space-y-3 px-3 py-3 text-sm">
        <div>
          <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-stone-600">
            Station
          </div>
          <select
            value={station}
            onChange={(e) => {
              setStation(e.target.value);
              setDirty(true);
            }}
            className="h-9 w-full rounded-md border border-stone-200 bg-white px-2 text-sm"
          >
            <option value="">— None —</option>
            {rules.stationOptions.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </div>
        <div>
          <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-stone-600">
            Certifications
          </div>
          <div className="flex flex-wrap gap-1">
            {rules.certificationOptions.map((c) => (
              <button
                key={c}
                type="button"
                onClick={() => toggleCert(c)}
                className={cn(
                  'rounded-md border px-2 py-1 text-xs font-semibold transition-colors',
                  certs.includes(c)
                    ? 'border-brand-700 bg-brand-700 text-white'
                    : 'border-stone-200 bg-white text-stone-800 hover:bg-stone-100',
                )}
              >
                {c}
              </button>
            ))}
          </div>
        </div>
        <Button
          size="sm"
          disabled={!dirty || mut.isPending}
          onClick={() => mut.mutate()}
        >
          {mut.isPending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : null}
          Save
        </Button>
        {mut.isError && (
          <div className="text-xs text-red-700">
            {(mut.error as Error).message}
          </div>
        )}
      </div>
    </details>
  );
}

function DecisionCheckCard({
  profile,
}: {
  profile: MemberProfile;
}): React.JSX.Element {
  const today = new Date().toISOString().slice(0, 10);
  const [dayDate, setDayDate] = useState(today);
  const [blockIndex, setBlockIndex] = useState<0 | 1>(0);
  const [leaveCode, setLeaveCode] = useState('V');

  const lc = useQuery({
    queryKey: ['leaveCodes'],
    queryFn: () => api.listLeaveCodes(),
    staleTime: 60_000,
  });

  const decision = useMutation({
    mutationFn: () =>
      api.staffingDecision({
        memberId: profile.member.id,
        dayDate,
        blockIndex,
        leaveCode,
      }),
  });

  return (
    <div className="rounded-md border border-stone-200 bg-white px-3 py-3">
      <div className="text-xs font-semibold uppercase tracking-wide text-stone-600">
        Check this date
      </div>
      <div className="mt-2 flex flex-wrap items-end gap-2 text-sm">
        <label className="flex flex-col">
          <span className="text-[11px] text-stone-500">Date</span>
          <input
            type="date"
            value={dayDate}
            onChange={(e) => setDayDate(e.target.value)}
            className="h-9 rounded-md border border-stone-200 bg-white px-2"
          />
        </label>
        <label className="flex flex-col">
          <span className="text-[11px] text-stone-500">Block</span>
          <select
            value={blockIndex}
            onChange={(e) => setBlockIndex(Number(e.target.value) as 0 | 1)}
            className="h-9 rounded-md border border-stone-200 bg-white px-2"
          >
            <option value={0}>AM (08–20)</option>
            <option value={1}>PM (20–08)</option>
          </select>
        </label>
        <label className="flex flex-col">
          <span className="text-[11px] text-stone-500">Code</span>
          <select
            value={leaveCode}
            onChange={(e) => setLeaveCode(e.target.value)}
            className="h-9 rounded-md border border-stone-200 bg-white px-2"
          >
            {lc.data?.leaveCodes.map((c) => (
              <option key={c.id} value={c.code}>
                {c.code} — {c.label}
              </option>
            ))}
          </select>
        </label>
        <Button
          size="sm"
          onClick={() => decision.mutate()}
          disabled={decision.isPending}
        >
          {decision.isPending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : null}
          Check
        </Button>
      </div>
      {decision.data ? <DecisionResultPanel result={decision.data} /> : null}
      {decision.isError ? (
        <div className="mt-2 text-xs text-red-700">
          {(decision.error as Error).message}
        </div>
      ) : null}
    </div>
  );
}

export function DecisionResultPanel({
  result,
}: {
  result: DecisionResult;
}): React.JSX.Element {
  const palette: Record<DecisionResult['decision'], { bg: string; fg: string; label: string; icon: React.ReactNode }> = {
    grant: { bg: 'bg-green-50 border-green-300', fg: 'text-green-800', label: 'Granted', icon: <ShieldCheck className="h-4 w-4" aria-hidden /> },
    grant_after_2000: { bg: 'bg-amber-50 border-amber-300', fg: 'text-amber-800', label: 'Granted after 2000', icon: <ShieldAlert className="h-4 w-4" aria-hidden /> },
    requires_chief_override: { bg: 'bg-amber-50 border-amber-400', fg: 'text-amber-900', label: 'Requires Fire Chief override', icon: <ShieldAlert className="h-4 w-4" aria-hidden /> },
    deny: { bg: 'bg-red-50 border-red-300', fg: 'text-red-800', label: 'Denied', icon: <XCircle className="h-4 w-4" aria-hidden /> },
  };
  const p = palette[result.decision];
  return (
    <div className={cn('mt-3 rounded-md border p-3', p.bg)}>
      <div className={cn('flex items-center gap-2 text-sm font-semibold', p.fg)}>
        {p.icon}
        {p.label}
      </div>
      <ul className="mt-2 space-y-1 text-xs">
        {result.reasons.map((r, i) => (
          <li key={i} className="flex items-start gap-1.5">
            {r.ok ? (
              <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-green-700" aria-hidden />
            ) : (
              <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-red-700" aria-hidden />
            )}
            <span className={r.ok ? 'text-stone-700' : 'text-stone-900'}>{r.message}</span>
          </li>
        ))}
      </ul>
      <div className="mt-2 text-[11px] text-stone-500">
        On-duty for block: {result.context.staffingForBlock} ·{' '}
        Marine FFs off this day: {result.context.marineFfOffOnDay}
      </div>
    </div>
  );
}
