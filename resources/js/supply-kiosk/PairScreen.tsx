// Not paired: type the 6-character code an admin made, and this screen pairs itself.
import { useState } from 'react';
import { KioskError, NOT_PAIRED_MESSAGE, pairWithCode, plainMessage } from './api.ts';

/**
 * Codes use A-Z without I, L and O, plus 2-9, shown as ABC-234. Spaces and a dash are allowed while typing. Any six
 * letters or digits turn Pair on; the server answers a mistyped one (an O for a 0) in plain words.
 */
export const CODE_MAX = 8;

export function codeCharacters(typed: string): string {
    return typed.toUpperCase().replace(/[^A-Z0-9]/g, '');
}

interface Props {
    /** Loads bootstrap after pairing; resolves true once the screen is ready. */
    onPaired: () => Promise<boolean>;
}

function pairingMessage(error: unknown): string {
    if (error instanceof KioskError && error.code === 'network') return 'No connection to the Hub. Check the network and try again.';
    return plainMessage(error);
}

export function PairScreen({ onPaired }: Props) {
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const ready = codeCharacters(code).length >= 6;

    const pair = async () => {
        if (!ready || busy) return;
        setBusy(true);
        setError(null);
        try {
            await pairWithCode(code);
            // The code is used up now; the screen keeps trying to load on its own (every 15 s), so there is nothing to tap.
            if (!(await onPaired())) setError('This screen is paired. The supply list did not load yet; the screen keeps trying on its own.');
        } catch (failure) {
            setError(pairingMessage(failure));
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="k-screen k-status-screen k-pair" aria-labelledby="k-pair-title">
            <h1 id="k-pair-title" className="k-title">This screen is not paired</h1>
            <p className="k-sub">{NOT_PAIRED_MESSAGE}</p>
            <form className="k-pair-form" onSubmit={event => { event.preventDefault(); void pair(); }}>
                <h2 className="k-heading">Pair this screen</h2>
                <label className="k-field">
                    <span>Pairing code</span>
                    <input className="k-pair-code" type="text" inputMode="text" autoCapitalize="characters" autoCorrect="off" autoComplete="off"
                        spellCheck={false} enterKeyHint="go" maxLength={CODE_MAX} placeholder="ABC-234" value={code} disabled={busy}
                        aria-invalid={error ? true : undefined} aria-describedby={error ? 'k-pair-error' : undefined}
                        onChange={event => { setCode(event.target.value.toUpperCase().slice(0, CODE_MAX)); setError(null); }} />
                </label>
                {error ? <p id="k-pair-error" className="k-error" role="alert">{error}</p> : null}
                <button type="submit" className="k-go" disabled={!ready || busy}>{busy ? 'Pairing' : 'Pair'}</button>
            </form>
        </section>
    );
}
