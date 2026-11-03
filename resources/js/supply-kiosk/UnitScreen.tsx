// Optional unit, then the two ways in: search or the whole supply room.
import { useState } from 'react';
import type { Apparatus, UnitChoice } from './types.ts';

interface PickerProps {
    units: Apparatus[];
    value: UnitChoice;
    onChange: (value: UnitChoice) => void;
}

/** Big unit chips plus a short free-text box. Tap a chosen chip again to clear it. */
export function UnitPicker({ units, value, onChange }: PickerProps) {
    const [text, setText] = useState(value && value.apparatus_id === null ? value.label : '');
    const typeUnit = (raw: string) => {
        const label = raw.slice(0, 20);
        setText(label);
        onChange(label.trim() === '' ? null : { apparatus_id: null, label: label.trim(), display: label.trim() });
    };
    return (
        <div className="k-units">
            <div className="k-chips" role="group" aria-label="Unit">
                <button type="button" className="k-chip" aria-pressed={value === null} onClick={() => { setText(''); onChange(null); }}>No unit</button>
                {units.map(unit => {
                    const selected = value?.apparatus_id === unit.id;
                    return (
                        <button key={unit.id} type="button" className="k-chip" aria-pressed={selected}
                            onClick={() => { setText(''); onChange(selected ? null : { apparatus_id: unit.id, label: null, display: unit.label }); }}>
                            {unit.label}
                        </button>
                    );
                })}
            </div>
            <label className="k-field">
                <span>Other unit</span>
                <input type="text" value={text} maxLength={20} autoCapitalize="characters" autoCorrect="off" spellCheck={false}
                    autoComplete="off" enterKeyHint="done" onChange={event => typeUnit(event.target.value)} />
            </label>
        </div>
    );
}

interface Props extends PickerProps {
    memberName: string;
    onSearch: () => void;
    onBrowse: () => void;
}

export function UnitScreen({ memberName, units, value, onChange, onSearch, onBrowse }: Props) {
    return (
        <section className="k-screen" aria-labelledby="k-unit-title">
            <p className="k-hello">{memberName}</p>
            <h1 id="k-unit-title" className="k-title">Which unit is this for?</h1>
            <p className="k-sub">Optional. Pick one, or go straight to the supplies.</p>
            <UnitPicker units={units} value={value} onChange={onChange} />
            <div className="k-ways">
                <button type="button" className="k-way" onClick={onSearch}>Search for an item</button>
                <button type="button" className="k-way k-way-quiet" onClick={onBrowse}>View entire supply room</button>
            </div>
        </section>
    );
}
