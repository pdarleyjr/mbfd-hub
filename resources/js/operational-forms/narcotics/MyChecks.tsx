import { useCallback, useEffect, useRef, useState } from 'react';
import { Button, MessageBar, MessageBarBody, Spinner } from '@fluentui/react-components';
import { ArrowLeft, CheckCircle2, Clock3, Plus, RefreshCw, XCircle, ZoomIn } from 'lucide-react';
import { HUB_TIMEZONE } from '../../shared/timezone.ts';
import { NarcoticsApiError, type NarcoticsApi } from './narcoticsApi';
import type { CheckStatus, NarcoticsCheckDetail, NarcoticsCheckSummary } from './narcoticsTypes';
import { sealText } from './narcoticsSeal.js';
import { unitText } from './narcoticsUnit.js';
import { PhotoViewer } from './PhotoViewer';

const when = (value: string | null | undefined) => (
    value ? new Intl.DateTimeFormat('en-US', { timeZone: HUB_TIMEZONE, dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : ''
);

/** Status is text first; the icon and colour only reinforce it. Pending is "Sent", never "Approved". */
export function StatusBadge({ status, label }: { status: CheckStatus; label: string }) {
    const Icon = status === 'approved' ? CheckCircle2 : status === 'rejected' ? XCircle : Clock3;
    return <span className={`nc-status nc-status--${status}`}><Icon size={15} aria-hidden="true" /> {label}</span>;
}

function loadError(error: unknown, fallback: string): string {
    return error instanceof NarcoticsApiError ? error.message : fallback;
}

interface ListProps {
    api: NarcoticsApi;
    goForm: () => void;
    goForms: () => void;
    goDetail: (id: string) => void;
    /** Bumped after a send, so the list refreshes when it opens. */
    refreshKey: number;
}

/** The member's own narcotics checks and what became of each. */
export function MyChecksList({ api, goForm, goForms, goDetail, refreshKey }: ListProps) {
    const [checks, setChecks] = useState<NarcoticsCheckSummary[] | null>(null);
    const [lastPage, setLastPage] = useState(1);
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const latest = useRef(0);

    const load = useCallback(async (target: number) => {
        const mine = ++latest.current;
        setLoading(true);
        try {
            const result = await api.list(target);
            if (mine !== latest.current) return;
            setChecks(result.checks); setLastPage(result.lastPage); setError('');
        } catch (caught) {
            if (mine === latest.current) setError(loadError(caught, 'Your narcotics checks could not be loaded.'));
        } finally { if (mine === latest.current) setLoading(false); }
    }, [api]);

    useEffect(() => { void load(page); }, [load, page, refreshKey]);

    return (
        <div className="nc nc-app" data-testid="narcotics-mine">
            <div className="nc-topline">
                <Button appearance="subtle" icon={<ArrowLeft size={18} />} onClick={goForms}>Back to Forms</Button>
                <Button appearance="subtle" icon={<RefreshCw size={16} />} onClick={() => void load(page)} disabled={loading}>Refresh</Button>
            </div>
            <header className="nc-head">
                <p className="of-eyebrow">Forms</p>
                <h1>My narcotics checks</h1>
                <p>Every narcotics check you sent to Captain 5 and what happened to it.</p>
            </header>
            <div className="nc-actions"><Button appearance="primary" size="large" icon={<Plus size={18} />} onClick={goForm}>New narcotics check</Button></div>

            {error && <MessageBar intent="error" role="alert"><MessageBarBody>{error} <Button size="small" onClick={() => void load(page)}>Try again</Button></MessageBarBody></MessageBar>}
            {!checks && !error && <div className="nc-loading"><Spinner label="Loading your narcotics checks…" /></div>}

            {checks && checks.length === 0 && (
                <div className="nc-empty">
                    <p><strong>No narcotics checks yet.</strong></p>
                    <p>When you send one, it appears here with its status.</p>
                    <Button appearance="primary" onClick={goForm}>Start a check</Button>
                </div>
            )}

            {checks && checks.length > 0 && (
                <ul className="nc-list" aria-label="My narcotics checks" aria-busy={loading}>
                    {checks.map((check) => (
                        <li key={check.id}>
                            <button type="button" className="nc-row" onClick={() => goDetail(check.id)} data-testid="narcotics-row">
                                {check.image.thumb_url
                                    ? <img src={check.image.thumb_url} alt="" className="nc-thumb" loading="lazy" width={72} height={72} />
                                    : <span className="nc-thumb nc-thumb--gone" aria-hidden="true">Photo removed</span>}
                                <span className="nc-row-text">
                                    <span className="nc-row-top"><StatusBadge status={check.status} label={check.status_label} /></span>
                                    <span className="nc-row-date">Sent {when(check.submitted_at)}{check.rescue_unit ? ` · Rescue unit: ${check.rescue_unit}` : ''}</span>
                                    {check.seal_number && <span className="nc-row-date">Seal number: {check.seal_number}</span>}
                                    {check.status === 'rejected' && <span className="nc-row-note">{check.decision_note ? `Reason: ${check.decision_note}` : 'No reason was given.'}</span>}
                                    {check.status === 'approved' && <span className="nc-row-note">Approved {when(check.decided_at)}</span>}
                                    {check.status === 'pending' && check.has_note && <span className="nc-row-note">Your note: {check.member_note}</span>}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {lastPage > 1 && (
                <div className="nc-actions">
                    <Button disabled={page <= 1 || loading} onClick={() => setPage((current) => current - 1)}>Newer</Button>
                    <span className="nc-hint">Page {page} of {lastPage}</span>
                    <Button disabled={page >= lastPage || loading} onClick={() => setPage((current) => current + 1)}>Older</Button>
                </div>
            )}
        </div>
    );
}

interface DetailProps {
    api: NarcoticsApi;
    id: string;
    goMine: () => void;
    goForms: () => void;
    goDetail: (id: string) => void;
    resubmit: (check: NarcoticsCheckDetail) => void;
}

const ACTION_TEXT: Record<string, string> = { submitted: 'Sent to Captain 5', approved: 'Approved', rejected: 'Rejected' };

/** One check: where it stands, why if it was rejected, the photo, and the way to send it again. */
export function CheckDetail({ api, id, goMine, goForms, goDetail, resubmit }: DetailProps) {
    const [check, setCheck] = useState<NarcoticsCheckDetail | null>(null);
    const [error, setError] = useState<NarcoticsApiError | null>(null);
    const [viewing, setViewing] = useState(false);

    const load = useCallback(async () => {
        try { setCheck(await api.show(id)); setError(null); } catch (caught) { setError(caught instanceof NarcoticsApiError ? caught : new NarcoticsApiError('That check could not be loaded.', 0)); }
    }, [api, id]);

    useEffect(() => { setCheck(null); setError(null); void load(); }, [load]);
    // A pending check changes when Captain 5 decides: look again quietly while it is open and the page is visible.
    useEffect(() => {
        if (check?.status !== 'pending') return undefined;
        const timer = window.setInterval(() => { if (document.visibilityState === 'visible') void load(); }, 60_000);
        return () => window.clearInterval(timer);
    }, [check?.status, load]);

    if (error && !check) {
        const gone = error.status === 404 || error.status === 403;
        return (
            <div className="nc nc-app">
                <MessageBar intent="error" role="alert"><MessageBarBody>{gone ? 'That narcotics check is not available to you.' : error.message}</MessageBarBody></MessageBar>
                <div className="nc-actions">
                    {!gone && <Button appearance="primary" onClick={() => void load()}>Try again</Button>}
                    <Button onClick={goMine}>My narcotics checks</Button>
                    <Button appearance="subtle" onClick={goForms}>Back to Forms</Button>
                </div>
            </div>
        );
    }
    if (!check) return <div className="nc nc-app"><div className="nc-loading"><Spinner label="Opening the narcotics check…" /></div></div>;

    return (
        <div className="nc nc-app" data-testid="narcotics-detail">
            <div className="nc-topline">
                <Button appearance="subtle" icon={<ArrowLeft size={18} />} onClick={goMine}>My narcotics checks</Button>
                <Button appearance="subtle" icon={<RefreshCw size={16} />} onClick={() => void load()}>Refresh</Button>
            </div>

            <header className="nc-head">
                <p className="of-eyebrow">Narcotics check</p>
                <h1 className="nc-detail-title">Sent {when(check.submitted_at)} <StatusBadge status={check.status} label={check.status_label} /></h1>
            </header>

            <div className={`nc-banner nc-banner--${check.status}`} role="status" data-testid="narcotics-banner">
                {check.status === 'pending' && <p><strong>Sent to Captain 5.</strong> It is waiting for review. This is not an approval yet.</p>}
                {check.status === 'approved' && <p><strong>Approved</strong> by {[check.decided_by_rank, check.decided_by_name].filter(Boolean).join(' ')} on {when(check.decided_at)}.</p>}
                {check.status === 'rejected' && (
                    <>
                        <p><strong>Rejected</strong> by {[check.decided_by_rank, check.decided_by_name].filter(Boolean).join(' ')} on {when(check.decided_at)}.</p>
                        <p data-testid="narcotics-reason">{check.decision_note ? <>Reason: {check.decision_note}</> : 'No reason was given.'}</p>
                        {check.resubmitted_as && <p>You sent a new photo for this one.</p>}
                    </>
                )}
            </div>

            {check.status === 'rejected' && check.can_resubmit && (
                <div className="nc-actions"><Button appearance="primary" size="large" onClick={() => resubmit(check)} data-testid="narcotics-resubmit">Send a new photo</Button></div>
            )}
            {check.status === 'rejected' && check.resubmitted_as && (
                <div className="nc-actions"><Button size="large" onClick={() => goDetail(check.resubmitted_as as string)}>See the new check</Button></div>
            )}

            <section className="nc-card" aria-labelledby="nc-detail-photo">
                <h2 id="nc-detail-photo">Photo</h2>
                {check.image.available && check.image.preview_url ? (
                    <button type="button" className="nc-preview-frame" onClick={() => setViewing(true)} aria-label="Zoom in on the photo">
                        <img src={check.image.preview_url} alt={`Narcotics check photo sent ${when(check.submitted_at)}`} />
                        <span className="nc-zoom-hint"><ZoomIn size={16} aria-hidden="true" /> Tap to zoom</span>
                    </button>
                ) : <p className="nc-hint">The photo file was removed under the retention policy. The record and the decision are kept.</p>}
                <p className="nc-note" data-testid="narcotics-detail-unit"><strong>Rescue unit:</strong> {unitText(check)}</p>
                <p className="nc-note" data-testid="narcotics-detail-seal"><strong>Seal number:</strong> {sealText(check)}</p>
                {check.member_note && <p className="nc-note"><strong>Your note:</strong> {check.member_note}</p>}
            </section>

            <section className="nc-card" aria-labelledby="nc-history">
                <h2 id="nc-history">History</h2>
                <ol className="nc-history">
                    {check.history.map((entry, index) => (
                        <li key={`${entry.action}-${index}`}>
                            <strong>{ACTION_TEXT[entry.action] ?? entry.action}</strong>
                            <span className="nc-hint"> {when(entry.at)}{entry.action !== 'submitted' && entry.actor_name ? ` · ${entry.actor_name}` : ''}</span>
                        </li>
                    ))}
                </ol>
            </section>

            <div className="nc-actions"><Button appearance="subtle" onClick={goForms}>Back to Forms</Button></div>
            {viewing && check.image.full_url && <PhotoViewer src={check.image.full_url} alt="Narcotics check photo" onClose={() => setViewing(false)} />}
        </div>
    );
}
