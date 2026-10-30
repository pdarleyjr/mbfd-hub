import { forwardRef, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { choiceForUnit, nextUnitChoice, unitChoices, unitLine } from './emsLogic.js';
import type { EmsUnit } from './emsTypes';

function statusText(unit: EmsUnit): string {
    return unit.status && unit.status !== 'In Service' ? unit.status : '';
}

/**
 * Which unit this request is for: Rescue units and Detail / Special Event as large one-tap buttons (a radio group),
 * every other unit in a list under "Other units". The stored choice is `unit:ID` or `detail`, exactly as before, and
 * the unit is always named in words right under the buttons, so a wrong tap is seen at once.
 */
export const UnitPicker = forwardRef<HTMLDivElement, {
    units: EmsUnit[];
    value: string;
    onChange: (choice: string) => void;
    describedBy?: string;
    invalid?: boolean;
}>(function UnitPicker({ units, value, onChange, describedBy, invalid }, groupRef) {
    const groups = useMemo(() => unitChoices(units), [units]);
    const buttonChoices = useMemo(() => [...groups.rescue.map(choiceForUnit), ...(groups.detail ? ['detail'] : [])], [groups]);
    const otherChoice = groups.other.some((unit) => choiceForUnit(unit) === value) ? value : '';
    const [othersOpen, setOthersOpen] = useState(otherChoice !== '');
    const buttons = useRef(new Map<string, HTMLButtonElement>());
    useEffect(() => { if (otherChoice !== '') setOthersOpen(true); }, [otherChoice]);

    const move = (event: KeyboardEvent<HTMLButtonElement>, current: string) => {
        const next = nextUnitChoice(buttonChoices, current, event.key);
        if (next === null) return;
        event.preventDefault();
        onChange(next);
        buttons.current.get(next)?.focus();
    };

    // One button is in the tab order: the chosen one, else the first.
    const tabStop = buttonChoices.includes(value) ? value : (buttonChoices[0] ?? '');

    const radio = (unit: EmsUnit) => {
        const choice = choiceForUnit(unit);
        const checked = choice === value;
        const status = statusText(unit);
        return <button
            key={choice} type="button" role="radio" aria-checked={checked} tabIndex={choice === tabStop ? 0 : -1}
            className={`ems-unit${checked ? ' is-chosen' : ''}`} data-choice={choice}
            ref={(element) => { if (element) buttons.current.set(choice, element); else buttons.current.delete(choice); }}
            onClick={() => onChange(choice)} onKeyDown={(event) => move(event, choice)}
        >
            <span className="ems-unit-name">{unit.label}</span>
            {status && <span className="ems-unit-status">{status}</span>}
        </button>;
    };

    return <div className="ems-picker">
        <div
            id="ems-target" ref={groupRef} role="radiogroup" tabIndex={-1} className="ems-units"
            aria-labelledby="ems-target-label" aria-describedby={describedBy} aria-invalid={invalid ? true : undefined} aria-required="true"
        >
            {groups.rescue.length > 0 && <p className="ems-unit-group" aria-hidden="true">Rescue units</p>}
            {groups.rescue.length > 0 && <div className="ems-unit-grid">{groups.rescue.map(radio)}</div>}
            {groups.detail && <>
                <p className="ems-unit-group" aria-hidden="true">Details</p>
                <div className="ems-unit-grid">{radio(groups.detail)}</div>
            </>}
        </div>
        {groups.other.length > 0 && <div className="ems-other">
            <button type="button" className="ems-other-toggle" aria-expanded={othersOpen} aria-controls="ems-other-units" onClick={() => setOthersOpen((open) => !open)}>
                Other units{otherChoice !== '' ? `: ${unitLine(otherChoice, units)}` : ''}
            </button>
            <div id="ems-other-units" hidden={!othersOpen}>
                <label htmlFor="ems-other-select" className="sr-only">Other units</label>
                <select id="ems-other-select" className="ems-select" value={otherChoice} onChange={(event) => { if (event.target.value !== '') onChange(event.target.value); }}>
                    <option value="">Choose another unit</option>
                    {groups.other.map((unit) => <option key={choiceForUnit(unit)} value={choiceForUnit(unit)}>{unit.label}{statusText(unit) ? ` (${statusText(unit)})` : ''}</option>)}
                </select>
            </div>
        </div>}
        <p id="ems-target-confirm" className={`ems-unit-confirm${value === '' ? ' is-empty' : ''}`} role="status" aria-live="polite" aria-atomic="true">
            {value === '' ? 'No unit chosen yet.' : <>For: <strong>{unitLine(value, units) || 'a unit that is no longer on the list'}</strong></>}
        </p>
    </div>;
});
