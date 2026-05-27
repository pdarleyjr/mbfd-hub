'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Loader2, Save } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import type { StaffingRules } from '@mbfd-vacation/shared';

/**
 * Admin form for the singleton staffing-rules row. Every number on this
 * page maps 1-to-1 to the Daily Shift Staffing Guidelines (v1.13, 12/1/25).
 * Save → write the new rules JSON, an audit row, and immediately become
 * live for the decision engine. No redeploy needed.
 */
export default function RulesPage(): React.JSX.Element {
  const qc = useQueryClient();
  const rules = useQuery({
    queryKey: ['staffingRules'],
    queryFn: () => api.getStaffingRules(),
    staleTime: 5_000,
  });

  const [draft, setDraft] = useState<StaffingRules | null>(null);
  useEffect(() => {
    if (rules.data?.rules && !draft) setDraft(rules.data.rules);
  }, [rules.data, draft]);

  const mut = useMutation({
    mutationFn: (next: StaffingRules) => api.putStaffingRules(next),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['staffingRules'] });
    },
  });

  if (rules.isLoading || !draft) {
    return (
      <div className="flex items-center gap-2 py-8 text-sm text-stone-500">
        <Loader2 className="h-4 w-4 animate-spin" /> Loading rules…
      </div>
    );
  }

  const updateField = <K extends keyof StaffingRules>(
    key: K,
    value: StaffingRules[K],
  ): void => {
    setDraft((d) => (d ? { ...d, [key]: value } : d));
  };

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6">
      <header>
        <h1 className="font-display text-2xl font-bold tracking-tight text-stone-900">
          Staffing rules
        </h1>
        <p className="mt-1 text-sm text-stone-600">
          Edits apply immediately to every decision the engine makes. Match
          the values in <em>Daily Shift Staffing Guidelines v1.13 (12-1-25)</em>{' '}
          unless the chief publishes a new version.
        </p>
        {rules.data?.updatedAt ? (
          <p className="mt-1 text-xs text-stone-500">
            Last updated {new Date(rules.data.updatedAt).toLocaleString()}
          </p>
        ) : null}
      </header>

      <Card title="Daily staffing">
        <NumberField
          label="Minimum daily staffing (hard floor)"
          help="From section 'Minimum Staffing' — floor including Marine Station 6."
          value={draft.minDailyStaffing}
          onChange={(n) => updateField('minDailyStaffing', n)}
        />
        <NumberField
          label="Air Tech trigger"
          help="At this count the detached Air Tech position is staffed; no scheduled leave until the cutoff hour below."
          value={draft.airTechTrigger}
          onChange={(n) => updateField('airTechTrigger', n)}
        />
        <NumberField
          label="Minimum pre-schedule staffing"
          help="Personnel must be at or above this count for prescheduled leave to be granted."
          value={draft.minPreScheduleStaffing}
          onChange={(n) => updateField('minPreScheduleStaffing', n)}
        />
        <NumberField
          label="Air Tech no-leave cutoff (local hour)"
          help="When Air Tech position is staffed, no scheduled leave permitted until this local hour (24h)."
          value={draft.airTechNoLeaveUntilLocalHour}
          onChange={(n) => updateField('airTechNoLeaveUntilLocalHour', n)}
          min={0}
          max={23}
        />
      </Card>

      <Card title="Marine Station">
        <NumberField
          label="Marine Firefighter off cap"
          help="Max Firefighters off at the same time on a given day, including A-Days."
          value={draft.marineFirefighterOffCap}
          onChange={(n) => updateField('marineFirefighterOffCap', n)}
        />
        <StringField
          label="Marine Station 6 key"
          help="The members.station value that identifies Marine 6 personnel (default 'M6')."
          value={draft.marineStationKey}
          onChange={(s) => updateField('marineStationKey', s)}
        />
      </Card>

      <Card title="Exchange caps (per calendar year)">
        <NumberField
          label="Exchange shifts cap"
          help="Max shifts (banked + owed) per calendar year. Per the doc: '10 shifts or 240 hours, whichever first.'"
          value={draft.exchangeShiftsCapPerYear}
          onChange={(n) => updateField('exchangeShiftsCapPerYear', n)}
        />
        <NumberField
          label="Exchange hours cap"
          help="Max hours (banked + owed) per calendar year. Partial shifts count toward the cap."
          value={draft.exchangeHoursCapPerYear}
          onChange={(n) => updateField('exchangeHoursCapPerYear', n)}
        />
        <StringListField
          label="Exchange leave codes"
          help="Comma-separated leave_codes.code values the engine treats as exchanges (default 'XOFF, EON')."
          value={draft.exchangeLeaveCodes}
          onChange={(arr) => updateField('exchangeLeaveCodes', arr)}
        />
      </Card>

      <Card title="Rank pairing (regular exchanges)">
        <RankPairingEditor
          value={draft.rankPairingRules}
          onChange={(v) => updateField('rankPairingRules', v)}
        />
      </Card>

      <Card title="A-Day exchange pairing (officers vs FFs only)">
        <StringListField
          label="Officer rank codes"
          help="Ranks the engine treats as officers for A-Day exchanges."
          value={draft.aDayExchangePairingRules.officers}
          onChange={(arr) =>
            updateField('aDayExchangePairingRules', {
              ...draft.aDayExchangePairingRules,
              officers: arr,
            })
          }
        />
        <StringListField
          label="Firefighter rank codes"
          help="Ranks treated as firefighters for A-Day exchanges."
          value={draft.aDayExchangePairingRules.firefighters}
          onChange={(arr) =>
            updateField('aDayExchangePairingRules', {
              ...draft.aDayExchangePairingRules,
              firefighters: arr,
            })
          }
        />
      </Card>

      <Card title="Enumerations">
        <StringListField
          label="Station options"
          help="The full list of station tags admins can assign to members."
          value={draft.stationOptions}
          onChange={(arr) => updateField('stationOptions', arr)}
        />
        <StringListField
          label="Certification options"
          help="The full list of certification slugs admins can toggle on members."
          value={draft.certificationOptions}
          onChange={(arr) => updateField('certificationOptions', arr)}
        />
      </Card>

      <div className="flex items-center gap-3 pt-2">
        <Button
          onClick={() => mut.mutate(draft)}
          disabled={mut.isPending}
        >
          {mut.isPending ? (
            <Loader2 className="h-4 w-4 animate-spin" />
          ) : (
            <Save className="h-4 w-4" />
          )}
          Save rules
        </Button>
        {mut.isSuccess && !mut.isPending && (
          <span className="text-sm text-green-700">Saved.</span>
        )}
        {mut.isError && (
          <span className="text-sm text-red-700">
            {(mut.error as Error).message}
          </span>
        )}
      </div>
    </div>
  );
}

function Card({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}): React.JSX.Element {
  return (
    <section className="rounded-lg border border-stone-200 bg-white p-4 shadow-sm">
      <h2 className="text-xs font-semibold uppercase tracking-wide text-stone-600">
        {title}
      </h2>
      <div className="mt-3 grid gap-3">{children}</div>
    </section>
  );
}

function NumberField({
  label,
  help,
  value,
  onChange,
  min,
  max,
}: {
  label: string;
  help: string;
  value: number;
  onChange: (n: number) => void;
  min?: number;
  max?: number;
}): React.JSX.Element {
  return (
    <label className="grid gap-1">
      <span className="text-sm font-medium text-stone-900">{label}</span>
      <span className="text-xs text-stone-500">{help}</span>
      <input
        type="number"
        value={value}
        min={min}
        max={max}
        onChange={(e) => onChange(Number(e.target.value))}
        className="h-9 w-32 rounded-md border border-stone-200 bg-white px-2 text-sm"
      />
    </label>
  );
}

function StringField({
  label,
  help,
  value,
  onChange,
}: {
  label: string;
  help: string;
  value: string;
  onChange: (s: string) => void;
}): React.JSX.Element {
  return (
    <label className="grid gap-1">
      <span className="text-sm font-medium text-stone-900">{label}</span>
      <span className="text-xs text-stone-500">{help}</span>
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="h-9 w-full max-w-sm rounded-md border border-stone-200 bg-white px-2 text-sm"
      />
    </label>
  );
}

function StringListField({
  label,
  help,
  value,
  onChange,
}: {
  label: string;
  help: string;
  value: string[];
  onChange: (arr: string[]) => void;
}): React.JSX.Element {
  // Track the joined string the parent told us about last so we don't
  // clobber mid-typing edits. Only re-sync when the parent's value
  // (joined) actually changes vs the last accepted external value.
  const lastExternal = useRef(value.join(', '));
  const [text, setText] = useState(value.join(', '));
  useEffect(() => {
    const next = value.join(', ');
    if (next !== lastExternal.current) {
      lastExternal.current = next;
      setText(next);
    }
  }, [value]);
  return (
    <label className="grid gap-1">
      <span className="text-sm font-medium text-stone-900">{label}</span>
      <span className="text-xs text-stone-500">{help}</span>
      <input
        type="text"
        value={text}
        onChange={(e) => setText(e.target.value)}
        onBlur={() =>
          onChange(
            text
              .split(',')
              .map((s) => s.trim())
              .filter((s) => s.length > 0),
          )
        }
        className="h-9 w-full rounded-md border border-stone-200 bg-white px-2 text-sm"
      />
    </label>
  );
}

function RankPairingEditor({
  value,
  onChange,
}: {
  value: Record<string, string[]>;
  onChange: (v: Record<string, string[]>) => void;
}): React.JSX.Element {
  const keys = Object.keys(value);
  return (
    <div className="grid gap-2">
      <p className="text-xs text-stone-500">
        For each rank on the left, list the rank codes (comma-separated) that
        member is allowed to exchange a regular shift with.
      </p>
      {keys.map((k) => (
        <div key={k} className="flex items-center gap-2">
          <span className="w-20 shrink-0 rounded bg-admin-850 px-2 py-1 text-center text-xs font-semibold uppercase text-white">
            {k}
          </span>
          <input
            type="text"
            defaultValue={value[k]?.join(', ') ?? ''}
            onBlur={(e) =>
              onChange({
                ...value,
                [k]: e.target.value
                  .split(',')
                  .map((s) => s.trim())
                  .filter((s) => s.length > 0),
              })
            }
            className="h-9 flex-1 rounded-md border border-stone-200 bg-white px-2 text-sm"
          />
        </div>
      ))}
    </div>
  );
}
