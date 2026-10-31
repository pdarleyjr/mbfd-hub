import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate, useSearchParams } from 'react-router';
import { identityForQueueCapture } from '../../lib/offlineIdentity';
import { setUnsavedWork } from '../../pwa/draftBusy.ts';
import { ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import {
  CHANGEOUT_ALREADY_PENDING,
  CHANGEOUT_ALREADY_RECORDED,
  CHANGEOUT_CHECKLIST_CHANGED,
  CHANGEOUT_REPLAY_CONFLICT,
  CHANGEOUT_STALE,
  CHANGEOUT_TRUCK_UNAVAILABLE,
  ChangeoutConflictError,
  getChangeoutContext,
  submitChangeout,
} from '../../utils/changeoutApi';
import { createClientSubmissionId } from '../../utils/storage';
import {
  clearChangeoutDraft,
  loadChangeoutDraft,
  loadContextSnapshot,
  saveChangeoutDraft,
  saveContextSnapshot,
} from './changeoutDraft';
import { applyTruckDefaults, buildSubmission, hasWork, reconcileDraft, resolveTruck, stationReturnPath, truckProblem, waitingNames, withLineChoice } from './changeoutLogic';
import type {
  ChangeoutContext,
  ChangeoutDoneState,
  ChangeoutDraft,
  ChangeoutStep,
  EquipmentLine,
  LineChoice,
  TruckChoice,
} from './types';

export const NOT_SENT_MESSAGE = 'Not sent. Your work is saved on this phone. Try again when you have signal.';
const NOT_SENT_PAGE_ONLY_MESSAGE = 'Not sent. Your work is only on this page, so keep it open. Try again when you have signal.';
const SERVER_PROBLEM_MESSAGE = 'The server had a problem, so this is not confirmed. Your work is saved on this phone. Try again.';
const SERVER_PROBLEM_PAGE_ONLY_MESSAGE = 'The server had a problem, so this is not confirmed. Your work is only on this page, so keep it open. Try again.';

export type SendState =
  | { kind: 'idle' }
  | { kind: 'sending' }
  | { kind: 'not_sent'; message: string }
  | { kind: 'refused'; message: string }
  | { kind: 'stale'; message: string; currentVehicle: string | null; currentApparatusId: number | null }
  | { kind: 'pending'; message: string; changeoutId: number | null }
  | { kind: 'replay'; message: string; changeoutId: number | null };

export type LoadState = { status: 'loading' } | { status: 'failed'; message: string } | { status: 'ready' };

const STEPS: readonly ChangeoutStep[] = ['truck', 'equipment', 'review'];
const isStep = (value: string | null): value is ChangeoutStep => STEPS.includes(value as ChangeoutStep);

const newDraft = (apparatusId: number, userId: number | null, alertId: number | null): ChangeoutDraft => ({
  version: 1,
  apparatusId,
  userId,
  alertId,
  clientSubmissionId: createClientSubmissionId(),
  step: 'truck',
  choice: null,
  typedVehicle: '',
  exceptions: {},
  note: '',
  attempted: false,
  savedAt: Date.now(),
});

const conflictSendState = (failure: ChangeoutConflictError): SendState => {
  switch (failure.code) {
    case CHANGEOUT_STALE:
      return { kind: 'stale', message: failure.message, currentVehicle: failure.details.current_vehicle_number, currentApparatusId: failure.details.current_apparatus_id };
    case CHANGEOUT_ALREADY_PENDING:
    case CHANGEOUT_ALREADY_RECORDED:
      return { kind: 'pending', message: failure.message, changeoutId: failure.details.changeout_id };
    case CHANGEOUT_REPLAY_CONFLICT:
      return { kind: 'replay', message: failure.message, changeoutId: failure.details.changeout_id };
    default:
      return { kind: 'refused', message: failure.message };
  }
};

export function useChangeoutWizard(apparatusId: number, alertParam: number | null) {
  const navigate = useNavigate();
  const location = useLocation();
  const [params, setParams] = useSearchParams();
  const busyToken = useRef(Symbol('changeout-draft'));
  const sendingRef = useRef(false);
  // Work handed from a refusal to the reload that follows it, since this phone may not have stored it.
  const carried = useRef<{ draft: ChangeoutDraft; list: ChangeoutContext } | null>(null);

  const [load, setLoad] = useState<LoadState>({ status: 'loading' });
  const [loadAttempt, setLoadAttempt] = useState(0);
  const [context, setContext] = useState<ChangeoutContext | null>(null);
  const [draft, setDraft] = useState<ChangeoutDraft | null>(null);
  const [notices, setNotices] = useState<string[]>([]);
  const [restored, setRestored] = useState(false);
  const [usingSavedList, setUsingSavedList] = useState(false);
  const [storageFailed, setStorageFailed] = useState(false);
  const [sendState, setSendState] = useState<SendState>({ kind: 'idle' });

  const returnParam = params.get('return_to');
  const stationPath = stationReturnPath(returnParam, context?.unit.station_id ?? null);

  useEffect(() => {
    let cancelled = false;
    setLoad({ status: 'loading' });

    const run = async () => {
      let userId: number | null = null;
      try {
        userId = (await identityForQueueCapture({ redirectOnExpiry: false })).userId;
      } catch {
        // The context request below decides: a 401 goes to sign-in, anything else still works.
      }
      if (cancelled) return;

      const handed = carried.current;
      carried.current = null;
      const saved = handed?.draft ?? loadChangeoutDraft(apparatusId, userId);
      const snapshot = handed?.list ?? loadContextSnapshot(apparatusId, userId);
      const alertId = alertParam ?? saved?.alertId ?? null;

      // No answer came back last time. The saved list stays, so the retry is the same payload and replays safely.
      if (saved?.attempted && snapshot) {
        setContext(snapshot);
        setDraft({ ...saved, step: 'review' });
        setUsingSavedList(false);
        setRestored(false);
        setSendState({ kind: 'not_sent', message: NOT_SENT_MESSAGE });
        setLoad({ status: 'ready' });
        return;
      }

      let fresh: ChangeoutContext;
      try {
        fresh = await getChangeoutContext(apparatusId, alertId);
      } catch (failure) {
        if (cancelled) return;
        if (isApiAuthenticationError(failure)) {
          redirectToLoginAfterSessionExpiry();
          return;
        }
        if (saved && snapshot) {
          setContext(snapshot);
          setDraft(saved);
          setUsingSavedList(true);
          setRestored(handed === null && hasWork(saved));
          setSendState({ kind: 'idle' });
          setLoad({ status: 'ready' });
          return;
        }
        setLoad({
          status: 'failed',
          message: failure instanceof ApiRequestError ? failure.message : 'The connection dropped before the truck list loaded.',
        });
        return;
      }
      if (cancelled) return;

      const nextNotices: string[] = [];
      let next = saved ? { ...saved, alertId } : newDraft(apparatusId, userId, alertId);
      if (saved) {
        const reconciled = reconcileDraft(next, fresh, snapshot);
        next = { ...reconciled.draft, attempted: false };
        if (snapshot && snapshot.unit.vehicle_number !== fresh.unit.vehicle_number) {
          nextNotices.push(`${fresh.unit.designation} is on truck ${fresh.unit.vehicle_number ?? 'not recorded'} now. Choices that still apply are kept.`);
        }
        if (reconciled.truckCleared) nextNotices.push('The truck you picked is no longer on the list. Choose another. Your equipment choices are kept.');
        if (reconciled.dropped.length > 0) nextNotices.push(`These choices no longer apply: ${reconciled.dropped.join(', ')}.`);
      }

      setContext(fresh);
      setDraft(next);
      setUsingSavedList(false);
      setRestored(handed === null && saved !== null && hasWork(saved));
      setNotices((current) => [...current, ...nextNotices]);
      setSendState({ kind: 'idle' });
      setLoad({ status: 'ready' });
    };

    void run();

    return () => { cancelled = true; };
  }, [alertParam, apparatusId, loadAttempt]);

  // Durable on every change, and reported as unsaved until the server has confirmed it.
  const working = draft !== null && hasWork(draft);
  useEffect(() => {
    if (draft === null) return;
    // Only worth saying once leaving the page would lose something.
    setStorageFailed(working && !saveChangeoutDraft(draft));
    if (!working) clearChangeoutDraft(draft.apparatusId, draft.userId);
    setUnsavedWork(busyToken.current, working);
  }, [draft, working]);

  useEffect(() => {
    if (working && context && draft) saveContextSnapshot(draft.apparatusId, draft.userId, context);
    // The snapshot only needs writing when the list changes or work first appears.
  }, [context, working]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const token = busyToken.current;

    return () => setUnsavedWork(token, false);
  }, []);

  const stepParam = params.get('step');
  const problem = draft && context ? truckProblem(draft, context) : null;
  const requested: ChangeoutStep = isStep(stepParam) ? stepParam : draft?.step ?? 'truck';
  const step: ChangeoutStep = requested !== 'truck' && problem !== null ? 'truck' : requested;

  const goTo = useCallback((target: ChangeoutStep, replace = false) => {
    setParams((current) => {
      const next = new URLSearchParams(current);
      next.set('step', target);
      return next;
    }, { replace });
  }, [setParams]);

  // The URL carries the step from the first paint, so the phone's own Back moves between steps.
  const initialStep = draft?.step ?? null;
  useEffect(() => {
    if (load.status === 'ready' && !isStep(stepParam) && initialStep !== null) goTo(initialStep, true);
  }, [goTo, initialStep, load.status, stepParam]);

  // A jump past a truck that no longer resolves lands on the truck step and stays there.
  useEffect(() => {
    if (load.status === 'ready' && isStep(stepParam) && stepParam !== 'truck' && problem !== null) goTo('truck', true);
  }, [goTo, load.status, problem, stepParam]);

  useEffect(() => {
    setDraft((current) => (current && current.step !== step ? { ...current, step } : current));
  }, [step]);

  const update = useCallback((patch: Partial<ChangeoutDraft>) => {
    setDraft((current) => (current ? { ...current, ...patch } : current));
  }, []);

  const send = useCallback(async (override?: ChangeoutDraft) => {
    const current = override ?? draft;
    if (sendingRef.current || !current || !context) return;
    sendingRef.current = true;
    setSendState({ kind: 'sending' });
    const marked: ChangeoutDraft = { ...current, attempted: true };
    setDraft(marked);
    const stored = saveChangeoutDraft(marked) && saveContextSnapshot(marked.apparatusId, marked.userId, context);

    try {
      const view = applyTruckDefaults(context, resolveTruck(marked, context));
      const { changeout } = await submitChangeout(apparatusId, buildSubmission(marked, view));
      const done: ChangeoutDoneState = {
        summary: changeout,
        // The server's own list when it sends one.
        waiting: changeout.open_items.length > 0 ? changeout.open_items.map((item) => item.name) : waitingNames(view, marked.exceptions),
        stationPath: stationReturnPath(returnParam, context.unit.station_id),
      };
      clearChangeoutDraft(marked.apparatusId, marked.userId);
      setDraft(null);
      setUnsavedWork(busyToken.current, false);
      navigate(location.pathname, { replace: true, state: { changeoutDone: done } });
    } catch (failure) {
      if (isApiAuthenticationError(failure)) {
        // The server refused before reading it, so this is not an unknown outcome. The draft waits for sign-in.
        const refused = { ...marked, attempted: false };
        setDraft(refused);
        saveChangeoutDraft(refused);
        setSendState({ kind: 'idle' });
        redirectToLoginAfterSessionExpiry();
      } else if (failure instanceof ChangeoutConflictError) {
        const refused = { ...marked, attempted: false };
        saveChangeoutDraft(refused);
        if (failure.code === CHANGEOUT_TRUCK_UNAVAILABLE) {
          const cleared: ChangeoutDraft = { ...refused, choice: null, step: 'truck' };
          saveChangeoutDraft(cleared);
          carried.current = { draft: cleared, list: context };
          setNotices([failure.message]);
          goTo('truck', true);
          setLoadAttempt((attempt) => attempt + 1);
        } else if (failure.code === CHANGEOUT_CHECKLIST_CHANGED) {
          // Resending would only be refused again: load the new list, keep what still applies, and stay on Review.
          carried.current = { draft: refused, list: context };
          setNotices([failure.message]);
          setLoadAttempt((attempt) => attempt + 1);
        } else {
          setDraft(refused);
          setSendState(conflictSendState(failure));
        }
      } else if (failure instanceof ApiRequestError && failure.status < 500) {
        const refused = { ...marked, attempted: false };
        setDraft(refused);
        saveChangeoutDraft(refused);
        setSendState({ kind: 'refused', message: failure.message });
      } else {
        // No answer, or a server failure: the outcome is unknown, and the same id makes the retry safe.
        const serverProblem = failure instanceof ApiRequestError;
        setSendState({
          kind: 'not_sent',
          message: stored
            ? (serverProblem ? SERVER_PROBLEM_MESSAGE : NOT_SENT_MESSAGE)
            : (serverProblem ? SERVER_PROBLEM_PAGE_ONLY_MESSAGE : NOT_SENT_PAGE_ONLY_MESSAGE),
        });
      }
    } finally {
      sendingRef.current = false;
    }
  }, [apparatusId, context, draft, goTo, location.pathname, navigate, returnParam]);

  /** Only after the member has been shown that an earlier send had different choices. */
  const sendAsNew = useCallback(() => {
    if (draft) void send({ ...draft, clientSubmissionId: createClientSubmissionId() });
  }, [draft, send]);

  const reviewWithCurrentTruck = useCallback(() => {
    if (sendState.kind !== 'stale' || !draft || !context) return;
    const targetId = sendState.currentApparatusId ?? draft.apparatusId;
    const next: ChangeoutDraft = { ...draft, apparatusId: targetId, attempted: false, step: 'review' };
    setSendState({ kind: 'idle' });
    // The old list comes along, so choices that no longer apply can be named.
    carried.current = { draft: next, list: context };
    if (targetId !== draft.apparatusId) {
      // The unit is on another truck row now, so the work moves with it and the route follows.
      clearChangeoutDraft(draft.apparatusId, draft.userId);
      saveChangeoutDraft(next);
      saveContextSnapshot(targetId, draft.userId, context);
      const search = new URLSearchParams(params);
      search.set('step', 'review');
      navigate(`/changeouts/new/${targetId}?${search.toString()}`, { replace: true });
      return;
    }
    saveChangeoutDraft(next);
    setLoadAttempt((attempt) => attempt + 1);
  }, [context, draft, navigate, params, sendState]);

  const startOver = useCallback(() => {
    setRestored(false);
    setNotices([]);
    setSendState({ kind: 'idle' });
    update({ choice: null, typedVehicle: '', exceptions: {}, note: '', attempted: false, step: 'truck' });
    goTo('truck', true);
  }, [goTo, update]);

  const discard = useCallback(() => {
    if (draft) clearChangeoutDraft(draft.apparatusId, draft.userId);
    setDraft(null);
    setUnsavedWork(busyToken.current, false);
    navigate(stationPath, { replace: true });
  }, [draft, navigate, stationPath]);

  const setLine = useCallback((line: EquipmentLine, choice: LineChoice) => {
    setDraft((current) => (current ? { ...current, exceptions: withLineChoice(current.exceptions, line, choice) } : current));
  }, []);

  const setChoice = useCallback((choice: TruckChoice) => update({ choice }), [update]);
  const setTyped = useCallback((typedVehicle: string) => update({ typedVehicle, choice: { kind: 'typed' } }), [update]);
  const setNote = useCallback((note: string) => update({ note }), [update]);
  const retryLoad = useCallback(() => setLoadAttempt((attempt) => attempt + 1), []);

  return useMemo(() => ({
    load,
    context,
    draft,
    step,
    problem,
    notices,
    restored,
    usingSavedList,
    storageFailed,
    sendState,
    stationPath,
    working,
    goTo,
    send,
    sendAsNew,
    reviewWithCurrentTruck,
    startOver,
    discard,
    setLine,
    setChoice,
    setTyped,
    setNote,
    retryLoad,
  }), [context, discard, draft, goTo, load, notices, problem, restored, retryLoad, reviewWithCurrentTruck, send, sendAsNew, sendState, setChoice, setLine, setNote, setTyped, startOver, stationPath, step, storageFailed, usingSavedList, working]);
}
