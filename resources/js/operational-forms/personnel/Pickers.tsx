import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Button } from '@fluentui/react-components';
import { Check, ChevronRight, Search, X } from 'lucide-react';
import { PersonnelApiError, type PersonnelApi } from './personnelApi';
import type { SupervisorOption, VehicleOption } from './personnelTypes';
import { FieldError } from './personnelUi';

export interface PickOption { id: number; title: string; detail?: string }

const MAX_SHOWN = 30;

/** Narrows a list by every word typed, in any order ("e 1 pierce" finds "Vehicle 4512 · E1 · Pierce"). */
function matches(option: PickOption, query: string): boolean {
    const haystack = `${option.title} ${option.detail ?? ''}`.toLowerCase();
    return query.toLowerCase().split(/\s+/).filter(Boolean).every((word) => haystack.includes(word));
}

/**
 * The chosen item in words, with a Change button that opens a search. The search narrows a local list, or asks the
 * server when `search` is given. Every result is a full-width button at least 48px tall.
 */
function ChoicePicker({ label, required, chosenLabel, chosenId, options, search, placeholder, emptyText, error, onPick, quickPick }: {
    label: string; required?: boolean; chosenLabel?: string; chosenId: number | null; options?: PickOption[];
    search?: (query: string) => Promise<PickOption[]>; placeholder: string; emptyText: string; error?: string;
    onPick: (option: PickOption) => void; quickPick?: PickOption | null;
}) {
    const baseId = useId();
    const inputId = `${baseId}-search`;
    const statusId = `${baseId}-status`;
    const errorId = `${baseId}-error`;
    const [open, setOpen] = useState(chosenId === null);
    const [query, setQuery] = useState('');
    const [remote, setRemote] = useState<PickOption[]>([]);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => { if (chosenId === null) setOpen(true); }, [chosenId]);

    useEffect(() => {
        if (!search) return undefined;
        const term = query.trim();
        if (term.length < 2) { setRemote([]); setSearchError(''); return undefined; }
        let active = true;
        const timer = window.setTimeout(() => {
            setSearching(true);
            search(term).then((results) => { if (active) { setRemote(results); setSearchError(''); } })
                .catch((caught) => { if (active) setSearchError(caught instanceof PersonnelApiError ? caught.message : 'The search did not work. Try again.'); })
                .finally(() => { if (active) setSearching(false); });
        }, 300);
        return () => { active = false; window.clearTimeout(timer); };
    }, [query, search]);

    const shown = useMemo(() => {
        if (search) return remote;
        const list = options ?? [];
        const term = query.trim();
        return (term ? list.filter((option) => matches(option, term)) : list).slice(0, MAX_SHOWN);
    }, [search, remote, options, query]);
    const total = search ? remote.length : (query.trim() ? (options ?? []).filter((option) => matches(option, query.trim())).length : (options ?? []).length);

    const pick = (option: PickOption) => { onPick(option); setOpen(false); setQuery(''); };

    const statusText = search
        ? (query.trim().length < 2 ? 'Type at least two letters of a name or an ID number.' : searching ? 'Searching…' : `${shown.length} found`)
        : (total > shown.length ? `Showing ${shown.length} of ${total}. Type to narrow the list.` : `${total} found`);

    return <div className="pf-picker">
        <span className="pf-label" id={`${baseId}-label`}>{label}{required ? <span className="pf-required">Required</span> : null}</span>
        {chosenId !== null && chosenLabel && <div className="pf-chosen">
            <Check size={18} aria-hidden="true" />
            <strong>{chosenLabel}</strong>
            {!open && <Button appearance="secondary" className="pf-chosen-change" onClick={() => { setOpen(true); window.setTimeout(() => inputRef.current?.focus(), 0); }} aria-label={`Change ${label.toLowerCase()}`}>Change</Button>}
        </div>}
        {open && <div className="pf-picker-body">
            {quickPick && quickPick.id !== chosenId && <Button className="pf-quick" appearance="secondary" onClick={() => pick(quickPick)}>{quickPick.title}</Button>}
            <div className="pf-search">
                <Search size={18} aria-hidden="true" />
                <input
                    ref={inputRef} id={inputId} className="pf-input pf-search-input" type="search" value={query} placeholder={placeholder} autoComplete="off"
                    aria-labelledby={`${baseId}-label`} aria-describedby={`${statusId}${error ? ` ${errorId}` : ''}`} aria-invalid={error ? true : undefined}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => { if (event.key === 'Enter' && shown.length === 1) { event.preventDefault(); pick(shown[0]); } }}
                />
                {query && <button type="button" className="pf-search-clear" aria-label="Clear search" onClick={() => setQuery('')}><X size={18} aria-hidden="true" /></button>}
            </div>
            <p className="pf-search-status" id={statusId} aria-live="polite">{searchError || statusText}</p>
            {shown.length > 0 && <ul className="pf-options" aria-label={`${label} results`}>
                {shown.map((option) => <li key={option.id}>
                    <button type="button" className={`pf-option${option.id === chosenId ? ' is-chosen' : ''}`} aria-pressed={option.id === chosenId} onClick={() => pick(option)}>
                        <span><strong>{option.title}</strong>{option.detail && <small>{option.detail}</small>}</span>
                        <ChevronRight size={18} aria-hidden="true" />
                    </button>
                </li>)}
            </ul>}
            {shown.length === 0 && !searching && (!search || query.trim().length >= 2) && <p className="pf-hint">{emptyText}</p>}
            {chosenId !== null && <div><Button appearance="subtle" onClick={() => { setOpen(false); setQuery(''); }}>Keep {chosenLabel}</Button></div>}
        </div>}
        <FieldError id={errorId} message={error} />
    </div>;
}

export function EmployeePicker({ api, label, chosenId, chosenLabel, me, error, onPick }: {
    api: PersonnelApi; label: string; chosenId: number | null; chosenLabel?: string; me?: { id: number | null; name: string | null } | null;
    error?: string; onPick: (option: PickOption) => void;
}) {
    const search = useMemo(() => async (query: string) => (await api.searchEmployees(query)).map((employee) => ({
        id: employee.id, title: employee.name, detail: employee.employee_id ? `ID ${employee.employee_id}` : undefined,
    })), [api]);
    const quickPick = me?.id ? { id: me.id, title: `Me (${me.name ?? 'my record'})` } : null;
    return <ChoicePicker
        label={label} required chosenId={chosenId} chosenLabel={chosenLabel} search={search} quickPick={quickPick} error={error}
        placeholder="Search by name or ID number" emptyText="No employee matches. Check the spelling or try the ID number." onPick={onPick}
    />;
}

export function VehiclePicker({ vehicles, chosenId, chosenLabel, error, onPick }: {
    vehicles: VehicleOption[]; chosenId: number | null; chosenLabel?: string; error?: string; onPick: (option: PickOption) => void;
}) {
    const options = useMemo(() => vehicles.map((vehicle) => ({ id: vehicle.id, title: `Vehicle ${vehicle.label}`, detail: vehicle.detail || undefined })), [vehicles]);
    return <ChoicePicker
        label="City vehicle" required chosenId={chosenId} chosenLabel={chosenLabel} options={options} error={error}
        placeholder="Search by vehicle number, unit, make or model" emptyText="No City vehicle matches. Try the vehicle number on the door." onPick={onPick}
    />;
}

export function SupervisorPicker({ supervisors, chosenId, chosenLabel, excludeId, error, onPick }: {
    supervisors: SupervisorOption[]; chosenId: number | null; chosenLabel?: string; excludeId: number | null; error?: string; onPick: (option: PickOption) => void;
}) {
    const options = useMemo(() => supervisors
        .filter((supervisor) => supervisor.id !== excludeId)
        .map((supervisor) => ({
            id: supervisor.id,
            title: [supervisor.rank, supervisor.name].filter(Boolean).join(' '),
            detail: supervisor.station ? `Station ${supervisor.station}`.replace(/^Station Station/i, 'Station') : undefined,
        })), [supervisors, excludeId]);
    return <ChoicePicker
        label="Supervisor" required chosenId={chosenId} chosenLabel={chosenLabel} options={options} error={error}
        placeholder="Search by name, rank or station" emptyText="No supervisor matches. Ask your officer who should complete the supervisor's part." onPick={onPick}
    />;
}
