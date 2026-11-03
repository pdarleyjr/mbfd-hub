// Employee ID on the screen's own number pad. GO lights up only when the ID belongs to a member.
import { useEffect } from 'react';
import type { Member } from './types.ts';

const MAX_DIGITS = 10;
const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

interface Props {
    typed: string;
    member: Member | null;
    longestId: number;
    error: string | null;
    onChange: (typed: string) => void;
    onGo: () => void;
}

export function IdScreen({ typed, member, longestId, error, onChange, onGo }: Props) {
    const press = (digit: string) => { if (typed.length < MAX_DIGITS) onChange(typed + digit); };
    const back = () => onChange(typed.slice(0, -1));

    // A USB keyboard or badge reader that types digits and Enter works too.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) return;
            if (/^\d$/.test(event.key)) { event.preventDefault(); if (typed.length < MAX_DIGITS) onChange(typed + event.key); }
            else if (event.key === 'Backspace') { event.preventDefault(); onChange(typed.slice(0, -1)); }
            else if (event.key === 'Escape') onChange('');
            else if (event.key === 'Enter' && member) { event.preventDefault(); onGo(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [typed, member, onChange, onGo]);

    const unknown = !member && typed.length >= Math.max(longestId, 1);
    return (
        <section className="k-screen k-id" aria-labelledby="k-id-title">
            <h1 id="k-id-title" className="k-title">Type your employee ID</h1>
            <div className="k-id-display">
                <output className="k-id-digits" aria-label="Employee ID typed">
                    {typed === '' ? <span className="k-id-placeholder">ID number</span> : typed}
                </output>
                <p className={`k-id-name${member ? ' is-match' : ''}${unknown ? ' is-unknown' : ''}`} aria-live="polite" data-testid="member-name">
                    {member ? member.name : unknown ? 'We do not have that ID. Check the number.' : ' '}
                </p>
            </div>
            {error ? <p className="k-error" role="alert">{error}</p> : null}
            <div className="k-pad" role="group" aria-label="Number pad">
                {KEYS.map(key => (
                    <button key={key} type="button" className="k-pad-key" onClick={() => press(key)}>{key}</button>
                ))}
                <button type="button" className="k-pad-key k-pad-quiet" onClick={() => onChange('')} disabled={typed === ''}>Clear</button>
                <button type="button" className="k-pad-key" onClick={() => press('0')}>0</button>
                <button type="button" className="k-pad-key k-pad-quiet" onClick={back} disabled={typed === ''} aria-label="Delete last digit">Delete</button>
            </div>
            <button type="button" className="k-go" onClick={onGo} disabled={!member}>GO</button>
        </section>
    );
}
