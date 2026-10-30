import { useCallback, useEffect, useState } from 'react';
import { Button, MessageBar, MessageBarBody, Spinner } from '@fluentui/react-components';
import './narcotics.css';
import { NarcoticsApiError, type NarcoticsApi } from './narcoticsApi';
import type { NarcoticsCheckDetail, NarcoticsFormDefinition, NarcoticsView } from './narcoticsTypes';
import { CheckDetail, MyChecksList } from './MyChecks';
import { NarcoticsForm, type UnitSource } from './NarcoticsForm';

export interface NarcoticsAppProps {
    api: NarcoticsApi;
    view: NarcoticsView;
    navigate: (view: NarcoticsView) => void;
    goForms: () => void;
}

/** The Narcotics check views inside the Forms app: the form, the member's own list, and one check. */
export function NarcoticsApp({ api, view, navigate, goForms }: NarcoticsAppProps) {
    const [definition, setDefinition] = useState<NarcoticsFormDefinition | null>(null);
    const [loadError, setLoadError] = useState('');
    const [loading, setLoading] = useState(false);
    const [resubmitOf, setResubmitOf] = useState<string | null>(null);
    /** The rejected check's unit and seal number, so the answer starts with them (still editable). */
    const [resubmitUnit, setResubmitUnit] = useState<UnitSource | null>(null);
    const [sentCount, setSentCount] = useState(0);

    const loadDefinition = useCallback(async () => {
        setLoading(true); setLoadError('');
        try {
            setDefinition(await api.form());
        } catch (caught) {
            setLoadError(caught instanceof NarcoticsApiError ? caught.message : 'The narcotics check form could not be loaded.');
        } finally { setLoading(false); }
    }, [api]);

    useEffect(() => { if (view.name === 'form' && !definition && !loading && !loadError) void loadDefinition(); }, [view.name, definition, loading, loadError, loadDefinition]);

    const goForm = useCallback(() => { setResubmitOf(null); setResubmitUnit(null); navigate({ name: 'form' }); }, [navigate]);
    const goMine = useCallback(() => navigate({ name: 'mine' }), [navigate]);
    const goDetail = useCallback((id: string) => navigate({ name: 'detail', id }), [navigate]);
    const resubmit = useCallback((check: NarcoticsCheckDetail) => {
        setResubmitOf(check.id);
        setResubmitUnit({ rescue_unit_choice: check.rescue_unit_choice, rescue_unit_other: check.rescue_unit_other, seal_number: check.seal_number });
        navigate({ name: 'form' });
    }, [navigate]);

    let content;
    if (view.name === 'mine') {
        content = <MyChecksList api={api} goForm={goForm} goForms={goForms} goDetail={goDetail} refreshKey={sentCount} />;
    } else if (view.name === 'detail') {
        content = <CheckDetail key={view.id} api={api} id={view.id} goMine={goMine} goForms={goForms} goDetail={goDetail} resubmit={resubmit} />;
    } else if (loadError) {
        content = <div className="nc nc-app">
            <MessageBar intent="error" role="alert"><MessageBarBody>{loadError}</MessageBarBody></MessageBar>
            <div className="nc-actions"><Button appearance="primary" onClick={() => void loadDefinition()}>Try again</Button><Button appearance="subtle" onClick={goForms}>Back to Forms</Button></div>
        </div>;
    } else if (!definition) {
        content = <div className="nc nc-app"><div className="nc-loading"><Spinner label="Opening the narcotics check" /></div></div>;
    } else {
        content = <NarcoticsForm
            key={resubmitOf ?? 'new'} api={api} definition={definition} resubmitOf={resubmitOf} resubmitUnit={resubmitUnit}
            goForms={goForms} goMine={goMine} goDetail={goDetail} onSent={() => setSentCount((count) => count + 1)}
        />;
    }

    return <div className="nc-root">{content}</div>;
}
