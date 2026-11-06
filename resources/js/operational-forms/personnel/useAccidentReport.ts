import { useCallback, useEffect, useRef, useState } from 'react';
import { PersonnelApiError, type PersonnelApi } from './personnelApi';
import {
    META_KEYS, META_SUBJECT, META_SUPERVISOR, META_VEHICLE, buildPatch, classifyFailure, conflictRows, markDirty, mergeAfterSave, newClientKey, settleDirty,
} from './personnelLogic.js';
import { accidentDraftKey, accidentDrafts } from './personnelStore';
import type { AccidentDetail, AccidentLocalDraft, EditableSection, MetaLabels, ReportType, SaveStatus } from './personnelTypes';

type Values = Record<string, unknown>;
type Meta = Record<string, number | null>;

function sectionData(report: AccidentDetail | null, section: EditableSection): Values {
    if (!report) return {};
    return { ...((section === 'supervisor' ? report.supervisor_data : report.driver_data) ?? {}) };
}

function metaFromReport(report: AccidentDetail | null, defaultSubject: { id: number | null } | null): Meta {
    return {
        [META_SUBJECT]: report ? (report.subject?.id ?? null) : (defaultSubject?.id ?? null),
        [META_VEHICLE]: report?.vehicle?.id ?? null,
        [META_SUPERVISOR]: report?.supervisor?.id ?? null,
    };
}

function labelsFromReport(report: AccidentDetail | null, defaultSubject: { name: string | null } | null): MetaLabels {
    return {
        subject: report ? report.subject?.name : (defaultSubject?.name ?? undefined),
        vehicle: report?.vehicle?.label,
        supervisor: report?.supervisor ? [report.supervisor.rank, report.supervisor.name].filter(Boolean).join(' ') : undefined,
    };
}

const LABEL_FOR_META: Record<string, keyof MetaLabels> = { [META_SUBJECT]: 'subject', [META_VEHICLE]: 'vehicle', [META_SUPERVISOR]: 'supervisor' };

export interface UseAccidentReportArgs {
    api: PersonnelApi;
    owner: string;
    type: ReportType;
    /** The report as the server holds it, or null for a new report that starts on the first change. */
    initial: AccidentDetail | null;
    /** For a new Vehicle Accident Report: the member, who is the employee involved unless they choose someone else. */
    defaultSubject: { id: number | null; name: string | null } | null;
    onCreated: (report: AccidentDetail) => void;
}

/**
 * One accident report being edited: local values, debounced autosave with the revision the member last saw,
 * a device copy of anything unsaved, conflict handling, and the signed submission.
 */
export function useAccidentReport({ api, owner, type, initial, defaultSubject, onCreated }: UseAccidentReportArgs) {
    const section: EditableSection = initial?.editable_section ?? 'driver';
    const [report, setReport] = useState<AccidentDetail | null>(initial);
    const reportRef = useRef(initial);
    const [values, setValues] = useState<Values>(() => sectionData(initial, section));
    const valuesRef = useRef(values);
    const [meta, setMeta] = useState<Meta>(() => metaFromReport(initial, defaultSubject));
    const metaRef = useRef(meta);
    const [labels, setLabels] = useState<MetaLabels>(() => labelsFromReport(initial, defaultSubject));
    const labelsRef = useRef(labels);
    const versionsRef = useRef<Record<string, number>>({});
    const counterRef = useRef(0);
    const clientKeyRef = useRef(newClientKey());
    const inFlight = useRef<Promise<AccidentDetail | null> | null>(null);
    const pausedRef = useRef(false);
    const onCreatedRef = useRef(onCreated);
    useEffect(() => { onCreatedRef.current = onCreated; }, [onCreated]);

    const [tick, setTick] = useState(0);
    const [status, setStatus] = useState<SaveStatus>(initial ? 'saved' : 'idle');
    const [message, setMessage] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [conflict, setConflict] = useState<AccidentDetail | null>(null);
    const [recovery, setRecovery] = useState<AccidentLocalDraft | null>(null);
    const [restored, setRestored] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const dirtyKeys = () => Object.keys(versionsRef.current);
    const localKey = () => accidentDraftKey(owner, reportRef.current?.id ?? null, type);

    const commitValues = (next: Values) => { valuesRef.current = next; setValues(next); };
    const commitMeta = (next: Meta) => { metaRef.current = next; setMeta(next); };
    const commitLabels = (next: MetaLabels) => { labelsRef.current = next; setLabels(next); };
    const commitReport = (next: AccidentDetail) => { reportRef.current = next; setReport(next); };

    /** The device copy holds only what the server has not confirmed. */
    const persistLocal = useCallback(async () => {
        const dirty = dirtyKeys();
        const key = localKey();
        if (dirty.length === 0) { await accidentDrafts.remove(key); return; }
        const localValues: Values = {};
        const localMeta: Meta = {};
        for (const name of dirty) {
            if (META_KEYS.includes(name)) localMeta[name] = metaRef.current[name] ?? null;
            else localValues[name] = valuesRef.current[name] ?? null;
        }
        await accidentDrafts.put({
            key, owner, type, clientKey: clientKeyRef.current, reportId: reportRef.current?.id ?? null, baseRevision: reportRef.current?.revision ?? null,
            section, values: localValues, meta: localMeta, labels: labelsRef.current, savedAt: new Date().toISOString(),
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [owner, type, section]);

    /** Takes the server's employee, vehicle and supervisor for every one the member is not changing right now. */
    const adoptServerMeta = (server: AccidentDetail) => {
        const fromServer = metaFromReport(server, null);
        const serverLabels = labelsFromReport(server, null);
        const nextMeta = { ...metaRef.current };
        const nextLabels = { ...labelsRef.current };
        for (const key of META_KEYS) {
            if (key in versionsRef.current) continue;
            nextMeta[key] = fromServer[key];
            nextLabels[LABEL_FOR_META[key]] = serverLabels[LABEL_FOR_META[key]];
        }
        commitMeta(nextMeta);
        commitLabels(nextLabels);
    };

    const touch = (key: string) => {
        counterRef.current += 1;
        versionsRef.current = markDirty(versionsRef.current, key, counterRef.current);
        setFieldErrors((errors) => {
            if (!(key in errors)) return errors;
            const next = { ...errors };
            delete next[key];
            return next;
        });
        setStatus((current) => (current === 'conflict' ? current : (navigator.onLine ? 'dirty' : 'offline')));
        void persistLocal();
        setTick((value) => value + 1);
    };

    const setField = useCallback((key: string, value: unknown) => {
        commitValues({ ...valuesRef.current, [key]: value });
        touch(key);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [persistLocal]);

    const setMetaValue = useCallback((key: string, id: number | null, label: string | undefined) => {
        commitMeta({ ...metaRef.current, [key]: id });
        commitLabels({ ...labelsRef.current, [LABEL_FOR_META[key]]: label });
        touch(key);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [persistLocal]);

    const handleFailure = (error: unknown) => {
        if (!(error instanceof PersonnelApiError)) {
            console.error('An accident report could not be saved.', error);
            setStatus('error');
            setMessage('Your entries could not be saved. They are kept on this device; try again in a moment.');
            return;
        }
        const kind = classifyFailure(error);
        if (kind === 'offline') { setStatus('offline'); return; }
        if (kind === 'conflict' && error.current) {
            const server = error.current;
            const serverValues = sectionData(server, section);
            const metaDiffers = META_KEYS.some((key) => key in versionsRef.current && metaFromReport(server, null)[key] !== metaRef.current[key]);
            const rows = conflictRows(serverValues, valuesRef.current, dirtyKeys(), []);
            if (rows.length === 0 && !metaDiffers) {
                // The server already holds exactly these entries (an earlier save landed but its answer was lost).
                versionsRef.current = {};
                commitReport(server);
                commitValues(mergeAfterSave(serverValues, valuesRef.current, Object.keys(serverValues), []));
                adoptServerMeta(server);
                void persistLocal();
                setStatus('saved');
                return;
            }
            pausedRef.current = true;
            setConflict(server);
            setStatus('conflict');
            return;
        }
        if (kind === 'invalid') setFieldErrors((errors) => ({ ...errors, ...error.fields }));
        setStatus('error');
        setMessage(error.message);
    };

    const save = useCallback((keepalive = false): Promise<AccidentDetail | null> => {
        if (pausedRef.current) return Promise.resolve(null);
        if (inFlight.current) return inFlight.current;
        if (dirtyKeys().length === 0) return Promise.resolve(reportRef.current);

        const run = async (): Promise<AccidentDetail | null> => {
            setStatus('saving');
            try {
                let current = reportRef.current;
                if (!current) {
                    const sentSubject: Record<string, number> = META_SUBJECT in versionsRef.current ? { [META_SUBJECT]: versionsRef.current[META_SUBJECT] } : {};
                    const newKey = localKey();
                    current = await api.create(type, clientKeyRef.current, metaRef.current[META_SUBJECT] ?? null);
                    versionsRef.current = settleDirty(versionsRef.current, sentSubject);
                    commitReport(current);
                    commitValues(mergeAfterSave(sectionData(current, section), valuesRef.current, [], dirtyKeys()));
                    adoptServerMeta(current);
                    await accidentDrafts.remove(newKey);
                    await persistLocal();
                    onCreatedRef.current(current);
                    if (dirtyKeys().length === 0) { setStatus('saved'); setMessage(''); return current; }
                }
                const sent = { ...versionsRef.current };
                const body = buildPatch(current.revision, valuesRef.current, Object.keys(sent), metaRef.current);
                const saved = await api.save(current.id, body, keepalive);
                versionsRef.current = settleDirty(versionsRef.current, sent);
                commitReport(saved);
                commitValues(mergeAfterSave(sectionData(saved, section), valuesRef.current, Object.keys(sent), dirtyKeys()));
                adoptServerMeta(saved);
                await persistLocal();
                const remaining = dirtyKeys().length > 0;
                setStatus(remaining ? 'dirty' : 'saved');
                setMessage('');
                if (remaining) setTick((value) => value + 1);
                return saved;
            } catch (error) {
                handleFailure(error);
                return null;
            }
        };

        const promise = run().finally(() => { inFlight.current = null; });
        inFlight.current = promise;
        return promise;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [api, type, section, persistLocal]);

    /** Saves until nothing is left, so a preview or a submission always sees the latest entries. */
    const flush = useCallback(async (): Promise<AccidentDetail | null> => {
        for (let attempt = 0; attempt < 6; attempt += 1) {
            if (inFlight.current) await inFlight.current;
            if (dirtyKeys().length === 0) return reportRef.current;
            const saved = await save();
            if (!saved) return null;
        }
        return dirtyKeys().length === 0 ? reportRef.current : null;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [save]);

    // Debounced autosave about a second after the last change.
    useEffect(() => {
        if (tick === 0 || dirtyKeys().length === 0) return undefined;
        const timer = window.setTimeout(() => { if (navigator.onLine) void save(); }, 1000);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tick, save]);

    // Save when the connection returns, the tab is hidden, or the page is closing.
    useEffect(() => {
        const online = () => { if (dirtyKeys().length > 0) void save(); };
        const hidden = () => { if (document.visibilityState === 'hidden' && dirtyKeys().length > 0) void save(true); };
        const pagehide = () => { if (dirtyKeys().length > 0) void save(true); };
        window.addEventListener('online', online);
        document.addEventListener('visibilitychange', hidden);
        window.addEventListener('pagehide', pagehide);
        return () => { window.removeEventListener('online', online); document.removeEventListener('visibilitychange', hidden); window.removeEventListener('pagehide', pagehide); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [save]);

    const applyLocal = (local: AccidentLocalDraft) => {
        let nextValues = { ...valuesRef.current };
        let nextMeta = { ...metaRef.current };
        for (const [key, value] of Object.entries(local.values)) {
            nextValues = { ...nextValues, [key]: value };
            counterRef.current += 1;
            versionsRef.current = markDirty(versionsRef.current, key, counterRef.current);
        }
        for (const [key, value] of Object.entries(local.meta)) {
            nextMeta = { ...nextMeta, [key]: value };
            counterRef.current += 1;
            versionsRef.current = markDirty(versionsRef.current, key, counterRef.current);
        }
        commitValues(nextValues);
        commitMeta(nextMeta);
        const nextLabels = { ...labelsRef.current };
        for (const key of Object.keys(local.meta)) nextLabels[LABEL_FOR_META[key]] = local.labels[LABEL_FOR_META[key]];
        commitLabels(nextLabels);
        setStatus(navigator.onLine ? 'dirty' : 'offline');
        setTick((value) => value + 1);
    };

    // A copy left on this device by a refresh, a closed tab or a dropped connection.
    useEffect(() => {
        let active = true;
        void (async () => {
            const key = accidentDraftKey(owner, initial?.id ?? null, type);
            const local = await accidentDrafts.get(key);
            if (!active || !local) return;
            if (local.section !== section || (initial && initial.editable_section === null)) { await accidentDrafts.remove(key); return; }
            if (!initial) {
                clientKeyRef.current = local.clientKey;
                applyLocal(local);
                setRestored(true);
                return;
            }
            if (local.baseRevision === initial.revision) {
                applyLocal(local);
                setRestored(true);
                return;
            }
            setRecovery(local);
        })();
        return () => { active = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const restoreRecovery = useCallback(() => {
        if (!recovery) return;
        applyLocal(recovery);
        setRecovery(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [recovery]);

    const discardRecovery = useCallback(() => {
        if (recovery) void accidentDrafts.remove(recovery.key);
        setRecovery(null);
    }, [recovery]);

    /** "Changed elsewhere": use the version on the server and drop the entries from this screen. */
    const keepTheirs = useCallback(() => {
        if (!conflict) return;
        versionsRef.current = {};
        commitReport(conflict);
        commitValues(sectionData(conflict, section));
        adoptServerMeta(conflict);
        void persistLocal();
        pausedRef.current = false;
        setConflict(null);
        setStatus('saved');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conflict, section, persistLocal]);

    /** "Changed elsewhere": start from the server's version and put this screen's changes back on top of it. */
    const reapplyMine = useCallback(() => {
        if (!conflict) return;
        commitReport(conflict);
        commitValues(mergeAfterSave(sectionData(conflict, section), valuesRef.current, [], dirtyKeys()));
        adoptServerMeta(conflict);
        pausedRef.current = false;
        setConflict(null);
        setStatus('dirty');
        setTick((value) => value + 1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [conflict, section]);

    /** A new report restored from this device that the member does not want: forget it and start empty. */
    const startOver = useCallback(async () => {
        await accidentDrafts.remove(accidentDraftKey(owner, null, type));
        versionsRef.current = {};
        clientKeyRef.current = newClientKey();
        commitValues({});
        commitMeta(metaFromReport(null, defaultSubject));
        commitLabels(labelsFromReport(null, defaultSubject));
        setRestored(false);
        setStatus('idle');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [owner, type]);

    const submit = useCallback(async (signature: string): Promise<AccidentDetail | null> => {
        setSubmitting(true);
        setMessage('');
        try {
            const current = await flush();
            if (!current) {
                setMessage((existing) => existing || 'Your latest entries are not saved yet. Check the connection and try again.');
                return null;
            }
            const payload = { revision: current.revision, signature, attest: true as const };
            const done = section === 'supervisor' ? await api.completeSupervisor(current.id, payload) : await api.submit(current.id, payload);
            await accidentDrafts.remove(accidentDraftKey(owner, current.id, type));
            commitReport(done);
            return done;
        } catch (error) {
            if (error instanceof PersonnelApiError && classifyFailure(error) === 'offline') {
                setMessage('You are offline. Nothing was submitted; your entries are saved on this device. Submit again when the connection returns.');
            } else {
                handleFailure(error);
            }
            return null;
        } finally {
            setSubmitting(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [api, flush, owner, section, type]);

    const removeDraft = useCallback(async () => {
        const current = reportRef.current;
        versionsRef.current = {};
        pausedRef.current = true;
        await accidentDrafts.remove(localKey());
        if (current?.can_delete) await api.remove(current.id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [api]);

    return {
        section, report, values, meta, labels, status, message, fieldErrors, conflict, recovery, restored, submitting,
        dirtyKeys: () => dirtyKeys(),
        setField, setMetaValue, save, flush, submit, keepTheirs, reapplyMine, restoreRecovery, discardRecovery, startOver, removeDraft,
        dismissRestored: () => setRestored(false),
        setFieldErrors, setMessage,
    };
}

export type AccidentReportState = ReturnType<typeof useAccidentReport>;
