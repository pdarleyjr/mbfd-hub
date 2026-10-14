import { useState, useEffect, useRef, useMemo } from 'react';
import { useParams, useNavigate, useLocation } from 'react-router';
import { Apparatus, ApparatusServiceTicketSummary, OfficerInfo, ChecklistData, ChecklistFieldValue, Compartment, Defect, InspectionData, MeterData, InspectionSubmission, ScheduledChecklistTaskResult } from '../types';
import { ApiClient, READ_TIMEOUT_MS } from '../utils/api';
import {
  DAILY_CHECKOUT_QUEUE_SYNC_EVENT,
  describeSubmissionFailure,
  getQueuedSubmission,
  getQueuedSubmissionForApparatusAndChecklistVersion,
  isPermanentSubmissionFailure,
  onDailyCheckoutQueueChanged,
  queueSubmission,
  receiptOutcomeForSync,
  retryQueuedSubmission,
  submitQueuedInspection,
  withDeadline,
  type DailyCheckoutQueueSyncResult,
  type InspectionReceiptOutcome,
  type InspectionReceiptState,
} from '../utils/dailyCheckoutSubmissionQueue';
import type { DailyCheckoutQueuedSubmission } from '../lib/db';
import { clearInspectionProgress, clearInspectionSessionAbandonKey, clearInspectionSessionStartKey, createClientSubmissionId, createDraftAutosaver, getOrCreateInspectionSessionAbandonKey, getOrCreateInspectionSessionStartKey, loadInspectionProgress, type DraftAutosaver } from '../utils/storage';
import { safeContextualPath } from '../utils/contextualBack';
import { useOffline } from '../hooks/useOffline';
import OfficerStep from './OfficerStep';
import ChecklistFieldsStep from './ChecklistFieldsStep';
import MeterStep from './MeterStep';
import CompartmentStep from './CompartmentStep';
import SubmitStep from './SubmitStep';
import PreviousPageButton from './PreviousPageButton';
import { inspectionProgress, inspectionReadiness } from '../utils/inspectionProgress';
import { identityForQueueCapture, type OfflineIdentity } from '../lib/offlineIdentity';
import { ClipboardList, Gauge, Truck, Users } from 'lucide-react';

type Step = 'officer' | 'meter' | 'details' | 'compartments' | 'submit';

const inspectionSessionIsExpired = (session: InspectionData['inspectionSession']): boolean => {
  const expiresAt = typeof session?.expires_at === 'string' ? Date.parse(session.expires_at) : Number.NaN;

  return !Number.isFinite(expiresAt) || expiresAt <= Date.now();
};

const easternDutyDate = (): string => {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date(Date.now()));
  const part = (type: Intl.DateTimeFormatPartTypes): string => (
    parts.find((entry) => entry.type === type)?.value ?? ''
  );

  return `${part('year')}-${part('month')}-${part('day')}`;
};

const restoreIssuedFireBoatChecklist = (saved: InspectionData | null): ChecklistData | null => {
  const session = saved?.inspectionSession;
  const checklist = saved?.checklistSnapshot;
  if (
    !session
    || !checklist
    || checklist.schema_version !== 2
    || checklist.checklist_version !== saved.checklist_version
    || session.checklist_hash !== saved.checklist_version
    || typeof session.id !== 'string'
    || typeof session.token !== 'string'
    || typeof session.duty_date !== 'string'
    || !Array.isArray(session.due_tasks)
    || inspectionSessionIsExpired(session)
    || checklist.inspection_date !== session.duty_date
    || JSON.stringify(checklist.due_tasks) !== JSON.stringify(session.due_tasks)
  ) {
    return null;
  }

  return {
    ...checklist,
    inspection_date: session.duty_date,
    due_tasks: session.due_tasks,
    inspection_session: session,
  };
};

const restoreIssuedFireBoatApparatus = (saved: InspectionData | null, slug: string): Apparatus | null => {
  const apparatus = saved?.apparatusSnapshot;
  if (
    !apparatus
    || apparatus.slug !== slug
    || !Number.isInteger(apparatus.id)
    || apparatus.id < 1
    || typeof apparatus.name !== 'string'
    || (apparatus.vehicle_number !== null && typeof apparatus.vehicle_number !== 'string')
  ) {
    return null;
  }

  return apparatus;
};

type ReadinessSummary = ReturnType<typeof inspectionReadiness>;

/** Why the inspection cannot be submitted yet, in the member's terms. */
const readinessExplanation = (readiness: ReadinessSummary): string => {
  if (readiness.equipment.total === 0) return 'This checklist has no equipment to inspect. Contact an officer.';
  if (readiness.equipment.remaining > 0) {
    return `${readiness.equipment.remaining} ${readiness.equipment.remaining === 1 ? 'item still needs' : 'items still need'} a status under Apparatus.`;
  }
  if (readiness.missingFields.length > 0 || readiness.missingDuties.length > 0) return 'Complete the required checklist details.';
  if (readiness.missingShift) return 'Select your shift under Member / Vehicle Info.';
  if (readiness.invalidMeters) return 'Correct the vehicle readings.';

  return 'Review the inspection and sign it.';
};

/** The station or list the member came from, as an in-app route. */
const inAppReturnTarget = (state: unknown, search: string): string | null => {
  const fromState = (state as { returnTo?: unknown } | null)?.returnTo;
  const candidate = typeof fromState === 'string' ? fromState : new URLSearchParams(search).get('return_to');
  const safe = safeContextualPath(candidate, window.location.origin);

  return safe?.startsWith('/daily/') ? safe.slice('/daily'.length) : null;
};

const SAVE_STATUS_TEXT = {
  saved: 'Changes saved on this device',
  failed: 'Not saved · Keep this page open',
} as const;

export default function InspectionWizard() {
  const { slug } = useParams<{ slug: string }>();
  const navigate = useNavigate();
  const location = useLocation();
  const isOffline = useOffline();
  const returnTo = useMemo(() => inAppReturnTarget(location.state, location.search), [location.state, location.search]);

  const [apparatus, setApparatus] = useState<Apparatus | null>(null);
  const [signature, setSignature] = useState<string | null>(null);
  const [checklist, setChecklist] = useState<ChecklistData | null>(null);
  const [currentStep, setCurrentStep] = useState<Step>('compartments');
  const [memberIdentity, setMemberIdentity] = useState<OfflineIdentity | null>(null);
  const [officerInfo, setOfficerInfo] = useState<OfficerInfo>({
    name: '',
    rank: 'Firefighter',
    shift: '',
    unitNumber: '',
  });
  const [meterData, setMeterData] = useState<MeterData>({
    engine_hours: null,
    miles: null,
  });
  const [compartments, setCompartments] = useState<Compartment[]>([]);
  const [fieldValues, setFieldValues] = useState<Array<{ id: string; value: ChecklistFieldValue }>>([]);
  const [scheduledTasks, setScheduledTasks] = useState<ScheduledChecklistTaskResult[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [submissionError, setSubmissionError] = useState<string | null>(null);
  const [hasLoadedAutosave, setHasLoadedAutosave] = useState(false);
  const [autosaveState, setAutosaveState] = useState<'idle' | 'saving' | 'saved' | 'failed'>('idle');
  const [saveStatusText, setSaveStatusText] = useState('');
  const [autosaveReviewMessage, setAutosaveReviewMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [abandonmentError, setAbandonmentError] = useState<string | null>(null);
  const [abandoningPriorSession, setAbandoningPriorSession] = useState(false);
  const [serviceNotices, setServiceNotices] = useState<ApparatusServiceTicketSummary[]>([]);
  const [serviceNoticesUnavailable, setServiceNoticesUnavailable] = useState(false);
  const [queuedSubmissionBlocker, setQueuedSubmissionBlocker] = useState<DailyCheckoutQueuedSubmission | null>(null);
  const [queueRevision, setQueueRevision] = useState(0);
  const [loadAttempt, setLoadAttempt] = useState(0);
  const [retryingQueued, setRetryingQueued] = useState(false);
  const [queuedRetryMessage, setQueuedRetryMessage] = useState<string | null>(null);
  const [showNotReady, setShowNotReady] = useState(false);
  const clientSubmissionIdRef = useRef<string | null>(null);
  const autosaverRef = useRef<DraftAutosaver | null>(null);
  const lastAutosaveStepRef = useRef<Step | null>(null);
  const mountedRef = useRef(true);
  const blockerPresentRef = useRef(false);
  const receiptShownRef = useRef(false);
  const meterFormRef = useRef<HTMLFormElement>(null);
  const hasQueuedInspectionRef = useRef(false);
  const initializedChecklistKeyRef = useRef<string | null>(null);
  const loadedContractRef = useRef<{ slug: string; apparatus: Apparatus; checklist: ChecklistData } | null>(null);
  const readiness = inspectionReadiness(checklist, compartments, fieldValues, scheduledTasks, officerInfo, meterData);
  const continueLabel = readiness.nextStep === 'compartments' ? 'Continue: Apparatus' : readiness.label;
  const backFallback = returnTo ?? '/vehicle-inspections';

  if (autosaverRef.current === null) {
    autosaverRef.current = createDraftAutosaver((succeeded) => {
      if (!mountedRef.current) return;
      setAutosaveState(succeeded ? 'saved' : 'failed');
      setSaveStatusText(succeeded ? SAVE_STATUS_TEXT.saved : SAVE_STATUS_TEXT.failed);
    });
  }
  const autosaver = autosaverRef.current;

  const showReceipt = (outcome: InspectionReceiptOutcome, queuedSubmissionId?: string) => {
    receiptShownRef.current = true;
    const state: InspectionReceiptState = { outcome, queuedSubmissionId, returnTo: returnTo ?? undefined };
    navigate(outcome === 'queued' ? '/success?queued=true' : '/success', { state });
  };

  useEffect(() => onDailyCheckoutQueueChanged(() => {
    setQueueRevision((revision) => revision + 1);
  }), []);

  // A background replay (reconnect, return to the app, backoff timer) may
  // send the record blocking this checkout; show its receipt.
  const blockerId = queuedSubmissionBlocker?.id;
  useEffect(() => {
    if (!blockerId) return undefined;
    const onSynced = (event: Event) => {
      const outcome = receiptOutcomeForSync((event as CustomEvent<DailyCheckoutQueueSyncResult>).detail, blockerId);
      if (outcome) showReceipt(outcome);
    };
    window.addEventListener(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, onSynced);

    return () => window.removeEventListener(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, onSynced);
  });

  // Never lose the last edits: write the pending draft when the page is
  // hidden or closed and when this screen unmounts (in-app navigation).
  useEffect(() => {
    mountedRef.current = true;
    // The document may be torn down before an IndexedDB write commits, so
    // hiding the page also keeps a synchronous copy of unconfirmed edits.
    const persistBeforeUnload = () => autosaver.persistBeforeUnload();
    const onVisibilityChange = () => {
      if (document.visibilityState === 'hidden') persistBeforeUnload();
    };
    window.addEventListener('pagehide', persistBeforeUnload);
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      window.removeEventListener('pagehide', persistBeforeUnload);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      void autosaver.flush();
      mountedRef.current = false;
    };
  }, [autosaver]);

  useEffect(() => {
    const fetchData = async () => {
      if (!slug) return;

      try {
        const loadedContract = loadedContractRef.current;
        if (loadedContract?.slug === slug) {
          const blocker = await getQueuedSubmissionForApparatusAndChecklistVersion(
            loadedContract.apparatus.id,
            loadedContract.checklist.checklist_version,
          );
          const hadBlocker = blockerPresentRef.current;
          blockerPresentRef.current = blocker !== null;
          setQueuedSubmissionBlocker(blocker);
          setError(null);

          // Keep the current form while nothing changed. When the record that
          // blocked this checkout disappears without a receipt (the member
          // discarded a rejected inspection), start over from the saved draft
          // with a new submission identity so it can be corrected and sent.
          const formInitialized = initializedChecklistKeyRef.current === `${slug}:${loadedContract.checklist.checklist_version}`;
          if (blocker || receiptShownRef.current || (formInitialized && !hadBlocker)) {
            return;
          }
          loadedContractRef.current = null;
          initializedChecklistKeyRef.current = null;
          hasQueuedInspectionRef.current = false;
          clientSubmissionIdRef.current = null;
          lastAutosaveStepRef.current = null;
          setQueuedRetryMessage(null);
          setSubmissionError(null);
          setLoading(true);
        }

        blockerPresentRef.current = false;
        setQueuedSubmissionBlocker(null);
        const identity = await identityForQueueCapture();
        if (!identity.personnel?.name || !identity.personnel.employee_profile_id) {
          throw new Error('Reconnect and sign in to load your member information before starting this inspection.');
        }
        setMemberIdentity(identity);
        const savedBeforeChecklistFetch = await loadInspectionProgress(slug, '', identity);
        let checklistData = restoreIssuedFireBoatChecklist(savedBeforeChecklistFetch);
        const savedChecklist = savedBeforeChecklistFetch?.checklistSnapshot;
        if (isOffline && savedChecklist && savedChecklist.schema_version !== 2
          && savedChecklist.checklist_version === savedBeforeChecklistFetch?.checklist_version) {
          checklistData = savedChecklist;
        }
        const savedApparatus = restoreIssuedFireBoatApparatus(savedBeforeChecklistFetch, slug);
        let saved = savedBeforeChecklistFetch;
        let foundApparatus: Apparatus | undefined;
        let restoredFromLocalContract = false;

        if (isOffline && checklistData !== null && savedApparatus !== null) {
          foundApparatus = savedApparatus;
          restoredFromLocalContract = true;
        } else {
          try {
            // The apparatus catalog remains the online source of truth. A
            // persisted issued contract may only be used as a narrow offline
            // recovery fallback for its matching apparatus snapshot.
            const apparatuses = await ApiClient.getApparatuses();
            foundApparatus = apparatuses.find(a => a.slug === slug);
          } catch (error) {
            if (checklistData !== null && savedApparatus !== null) {
              foundApparatus = savedApparatus;
              restoredFromLocalContract = true;
            } else {
              throw error;
            }
          }
        }

        if (!foundApparatus) {
          throw new Error('Apparatus not found');
        }

        setApparatus(foundApparatus);
        setOfficerInfo(prev => ({ ...prev, unitNumber: foundApparatus.vehicle_number ?? foundApparatus.unit_id ?? '' }));

        // Service status is intentionally secondary. A network or API failure
        // must never prevent the inspection checklist from loading.
        if (!restoredFromLocalContract) {
          ApiClient.getApparatusServiceNotices(foundApparatus.id)
            .then((notices) => {
              setServiceNotices(notices);
              setServiceNoticesUnavailable(false);
            })
            .catch(() => {
              setServiceNotices([]);
              setServiceNoticesUnavailable(true);
            });
        }

        if (checklistData === null) {
          if (isOffline && saved?.inspectionSession) {
            throw new Error(
              inspectionSessionIsExpired(saved.inspectionSession)
                ? 'This server-issued Fire Boat inspection session has expired. Reconnect and start a new Fire Boat inspection.'
                : 'A server-issued Fire Boat inspection session is required before this checkout can be submitted. Reconnect before starting a new Fire Boat inspection.',
            );
          }

          checklistData = await ApiClient.getChecklist(foundApparatus.id);
          saved = await loadInspectionProgress(slug, checklistData.checklist_version, identity);
          if (checklistData.schema_version === 2) {
            const savedSession = saved?.inspectionSession;
            if (
              saved?.checklist_version === checklistData.checklist_version
              && savedSession?.checklist_hash === checklistData.checklist_version
              && !inspectionSessionIsExpired(savedSession)
            ) {
              checklistData = {
                ...checklistData,
                inspection_date: savedSession.duty_date,
                due_tasks: savedSession.due_tasks,
                inspection_session: savedSession,
              };
            } else {
              if (isOffline) {
                throw new Error('A server-issued Fire Boat inspection session is required before this checkout can be submitted. Reconnect before starting a new Fire Boat inspection.');
              }

              checklistData = await ApiClient.startInspectionSession(
                foundApparatus.id,
                getOrCreateInspectionSessionStartKey(slug, checklistData.checklist_version),
              );
            }
          }
        }
        setChecklist(checklistData);
        loadedContractRef.current = { slug, apparatus: foundApparatus, checklist: checklistData };

        const queuedSubmission = await getQueuedSubmissionForApparatusAndChecklistVersion(
          foundApparatus.id,
          checklistData.checklist_version,
        );
        if (queuedSubmission) {
          blockerPresentRef.current = true;
          setQueuedSubmissionBlocker(queuedSubmission);

          return;
        }

        const checklistKey = `${slug}:${checklistData.checklist_version}`;
        if (initializedChecklistKeyRef.current === checklistKey) {
          return;
        }

        initializedChecklistKeyRef.current = checklistKey;
        clientSubmissionIdRef.current = null;
        setHasLoadedAutosave(false);
        setAutosaveReviewMessage(null);
        setOfficerInfo({
          name: identity.personnel.name,
          rank: identity.personnel.rank as OfficerInfo['rank'],
          shift: '',
          unitNumber: foundApparatus.vehicle_number ?? foundApparatus.unit_id ?? '',
          employeeId: identity.personnel.employee_profile_id,
        });
        setMeterData({ engine_hours: null, miles: null });
        setSignature(null);
        setCurrentStep('compartments');
        setCompartments(checklistData.compartments);
        setFieldValues(checklistData.fields.map((field) => ({
          id: field.id,
          value: field.id === checklistData.inspection_date_field_id
            ? checklistData.inspection_date ?? ''
            : field.id === 'vehicle_num' ? foundApparatus.vehicle_number ?? ''
            : field.inputType === 'checkbox'
              ? false
              : field.inputType === 'number' || field.inputType === 'percentage'
                ? null
                : '',
        })));
        setScheduledTasks(checklistData.due_tasks.map((task) => ({
          id: task.id,
          status: 'Present',
          notes: null,
        })));

        if (saved) {
          const canRestoreSavedProgress = saved.checklist_version === checklistData.checklist_version
            && saved.actorUserId === identity.userId
            && saved.actorSecurityVersion === identity.securityVersion
            && (
              checklistData.schema_version !== 2
              || saved.inspectionSession?.id === checklistData.inspection_session?.id
            );
          if (canRestoreSavedProgress) {
            setSignature(saved.signature ?? null);
            // Keep the issued baseline with the observation, even after a newer
            // checkout changed the live fleet meters while this draft was offline.
            setApparatus({ ...foundApparatus, meter_baseline_token: saved.apparatusSnapshot?.meter_baseline_token });
            setOfficerInfo({ ...saved.officer, name: identity.personnel.name, rank: identity.personnel.rank as OfficerInfo['rank'], employeeId: identity.personnel.employee_profile_id, unitNumber: foundApparatus.vehicle_number ?? '' });
            if (saved.meter) {
              setMeterData(saved.meter);
            }
            setCompartments(saved.compartments);
            {
              if (saved.fieldValues) {
                setFieldValues(saved.fieldValues);
              }
              if (saved.scheduledTasks) {
                setScheduledTasks(saved.scheduledTasks);
              }
            }
            setCurrentStep('compartments');
            setHasLoadedAutosave(true);
          } else if (saved.actorUserId !== identity.userId || saved.actorSecurityVersion !== identity.securityVersion) {
            setAutosaveReviewMessage('A saved inspection belongs to a different or unverified sign-in. Its answers were not loaded.');
          } else if (saved.checklist_version === checklistData.checklist_version && checklistData.schema_version === 2) {
            setAutosaveReviewMessage('The prior Fire Boat session expired or changed. Its saved answers were not copied into this new server-issued session.');
          } else {
            setAutosaveReviewMessage('A previously saved inspection uses a different checklist version. It remains saved on this device and requires officer review; this form is using the current checklist.');
          }
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load data');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [slug, queueRevision, isOffline, loadAttempt]);

  // Autosave progress: debounced while editing, immediate on a step change.
  useEffect(() => {
    if (
      loading
      || submitting
      || hasQueuedInspectionRef.current
      || queuedSubmissionBlocker
      || !memberIdentity
      || !slug
      || !apparatus
      || !checklist
    ) {
      // A queued or submitting inspection is durable in the submission queue;
      // the draft was flushed before submitting.
      autosaver.cancel();
      return;
    }

    const stepChanged = lastAutosaveStepRef.current !== currentStep;
    lastAutosaveStepRef.current = currentStep;
    setAutosaveState((state) => (state === 'failed' ? state : 'saving'));
    autosaver.schedule({
      apparatusSlug: slug,
      data: {
        actorUserId: memberIdentity.userId,
        actorSecurityVersion: memberIdentity.securityVersion,
        checklist_version: checklist.checklist_version,
        apparatusSnapshot: apparatus,
        officer: officerInfo,
        signature,
        meter: meterData,
        compartments,
        fieldValues,
        scheduledTasks,
        inspectionSession: checklist.inspection_session,
        checklistSnapshot: checklist,
      },
      clearSessionStartKeyFor: checklist.schema_version === 2 && checklist.inspection_session ? checklist.checklist_version : undefined,
    }, stepChanged);
  }, [officerInfo, meterData, compartments, fieldValues, scheduledTasks, currentStep, slug, apparatus, checklist, memberIdentity, loading, signature, submitting, queuedSubmissionBlocker, autosaver]);

  useEffect(() => {
    if (readiness.ready) setShowNotReady(false);
  }, [readiness.ready]);

  const handleOfficerChange = (info: OfficerInfo) => {
    setOfficerInfo(info);
    setFieldValues(previous => previous.map(field => field.id === 'fb6-shift' ? { ...field, value: info.shift } : field));
  };

  const handleOfficerSubmit = (info: OfficerInfo) => {
    handleOfficerChange(info);
    setCurrentStep(readiness.nextStep);
  };

  const handleMetersChange = (data: MeterData) => {
    setMeterData(data);
    setFieldValues(previous => previous.map(field => field.id === 'mileage' ? { ...field, value: data.miles } : field));
  };

  const handleFieldsChange: React.Dispatch<React.SetStateAction<Array<{ id: string; value: ChecklistFieldValue }>>> = (update) => {
    const next = typeof update === 'function' ? update(fieldValues) : update;
    setFieldValues(next);
    const mileage = next.find(field => field.id === 'mileage');
    if (mileage) setMeterData(previous => ({ ...previous, miles: typeof mileage.value === 'number' ? mileage.value : null }));
    const shift = next.find(field => field.id === 'fb6-shift')?.value;
    if (shift === '' || shift === 'A' || shift === 'B' || shift === 'C') setOfficerInfo(previous => ({ ...previous, shift }));
  };

  const handleMeterSubmit = (data: MeterData) => {
    setMeterData(data);
    setCurrentStep(readiness.nextStep);
  };

  const handleChecklistDetailsSubmit = (
    updatedFieldValues: Array<{ id: string; value: ChecklistFieldValue }>,
    updatedScheduledTasks: ScheduledChecklistTaskResult[],
  ) => {
    setFieldValues(updatedFieldValues);
    setScheduledTasks(updatedScheduledTasks);
    setCurrentStep(readiness.nextStep);
  };

  const handleCompartmentsSubmit = (updatedCompartments: Compartment[]) => {
    if (inspectionProgress(updatedCompartments).remaining > 0) return;
    setCompartments(updatedCompartments);
    setCurrentStep(readiness.nextStep);
  };

  const handleAbandonPriorSession = async () => {
    const inspectionSession = checklist?.inspection_session;
    if (!apparatus || !slug || !checklist || !inspectionSession || isOffline) return;

    setAbandonmentError(null);
    setAbandoningPriorSession(true);
    try {
      const todayChecklist = await ApiClient.abandonInspectionSession(
        apparatus.id,
        inspectionSession.id,
        inspectionSession.token,
        inspectionSession.replay_key,
        getOrCreateInspectionSessionAbandonKey(slug, inspectionSession.id),
      );
      clearInspectionSessionAbandonKey(slug, inspectionSession.id);
      await clearInspectionProgress(slug, checklist.checklist_version, memberIdentity);
      clearInspectionSessionStartKey(slug, checklist.checklist_version);
      clientSubmissionIdRef.current = null;
      initializedChecklistKeyRef.current = `${slug}:${todayChecklist.checklist_version}`;
      setChecklist(todayChecklist);
      setHasLoadedAutosave(false);
      setAutosaveReviewMessage('The prior Fire Boat inspection was explicitly abandoned. This form now uses today\'s server-issued contract.');
      setOfficerInfo({
        name: memberIdentity?.personnel?.name ?? officerInfo.name,
        rank: (memberIdentity?.personnel?.rank as OfficerInfo['rank']) ?? officerInfo.rank,
        employeeId: memberIdentity?.personnel?.employee_profile_id,
        shift: '',
        unitNumber: apparatus.vehicle_number ?? apparatus.unit_id ?? '',
      });
      setMeterData({ engine_hours: null, miles: null });
      setSignature(null);
      setCompartments(todayChecklist.compartments);
      setFieldValues(todayChecklist.fields.map((field) => ({
        id: field.id,
        value: field.id === todayChecklist.inspection_date_field_id
          ? todayChecklist.inspection_date ?? ''
          : field.inputType === 'checkbox'
            ? false
            : field.inputType === 'number' || field.inputType === 'percentage'
              ? null
              : '',
      })));
      setScheduledTasks(todayChecklist.due_tasks.map((task) => ({
        id: task.id,
        status: 'Present',
        notes: null,
      })));
      setCurrentStep('officer');
    } catch (err) {
      setAbandonmentError(err instanceof Error ? err.message : 'Unable to start today\'s Fire Boat inspection.');
      setAbandoningPriorSession(false);
    }
  };

  const handleSubmit = async (signature: string | null) => {
    if (!apparatus || !slug || !checklist) return;
    if (!readiness.ready) {
      // Say why instead of silently moving to another step.
      setShowNotReady(true);
      setCurrentStep(readiness.nextStep);
      return;
    }

    const inspectionSession = checklist.inspection_session;
    if (checklist.schema_version === 2 && !inspectionSession) {
      setError('A server-issued Fire Boat inspection session is required before this checkout can be submitted.');

      return;
    }

    setSubmitting(true);
    setSubmissionError(null);
    try {
      // The draft must match what is queued so it is cleared once accepted.
      await autosaver.flush();
      const currentIdentity = await withDeadline(
        identityForQueueCapture(),
        READ_TIMEOUT_MS,
        'Your sign-in could not be checked in time. Nothing was sent; your answers are saved on this device. Try again.',
      );
      if (currentIdentity.userId !== memberIdentity?.userId || currentIdentity.securityVersion !== memberIdentity?.securityVersion) {
        throw new Error('Your sign-in changed. This inspection remains saved for its original member; sign in with that account to continue.');
      }
      // Compile defects from items marked Missing or Damaged
      const defects: Defect[] = [];
      compartments.forEach(compartment => {
        compartment.items.forEach(item => {
          if (item.status === 'Missing' || item.status === 'Damaged') {
            defects.push({
              item: item.name,
              compartment: compartment.name,
              status: item.status,
              notes: item.notes,
              photo: item.photo,
              ...(checklist.schema_version === 2 ? {
                compartment_id: compartment.id,
                item_id: item.id,
              } : {}),
            });
          }
        });
      });

      const submission: InspectionSubmission = {
        processing_version: 1,
        meter_baseline_token: apparatus.meter_baseline_token,
        client_submission_id: clientSubmissionIdRef.current ?? createClientSubmissionId(),
        checklist_version: checklist.checklist_version,
        operator_name: officerInfo.name,
        rank: officerInfo.rank,
        shift: officerInfo.shift,
        unit_number: officerInfo.unitNumber,
        employee_id: officerInfo.employeeId,
        engine_hours: meterData.engine_hours,
        miles: meterData.miles,
        compartments: compartments.map(c => ({
          id: c.id,
          name: c.name,
          items: c.items.map(item => ({
            id: item.id,
            name: item.name,
            status: item.status,
            observed: item.observed === true,
            ...(item.photo ? { photo: item.photo } : {}),
            ...(item.value !== undefined ? { value: item.value } : {}),
            notes: item.notes || null,
          })),
        })),
        defects,
        field_values: fieldValues,
        ...(checklist.schema_version === 2 ? {
          scheduled_tasks: scheduledTasks,
          inspection_session_id: inspectionSession?.id,
          inspection_session_token: inspectionSession?.token,
          inspection_session_replay_key: inspectionSession?.replay_key,
        } : {}),
        officer_signature: signature,
      };
      clientSubmissionIdRef.current = submission.client_submission_id;
      const queueId = await queueSubmission(apparatus.id, submission);
      const queuedSubmission = await getQueuedSubmission(queueId);
      if (!queuedSubmission) {
        throw new Error('Unable to retain this inspection for submission. Please try again.');
      }
      if (
        queuedSubmission.apparatusId !== apparatus.id
        || queuedSubmission.data.client_submission_id !== submission.client_submission_id
        || queuedSubmission.data.checklist_version !== submission.checklist_version
        || JSON.stringify(queuedSubmission.data) !== JSON.stringify(submission)
      ) {
        throw new Error('A different saved Daily Checkout was found. This inspection remains in the current autosave and was not submitted.');
      }
      hasQueuedInspectionRef.current = true;

      if (isOffline) {
        // Vibrate to indicate queued
        if ('vibrate' in navigator) {
          navigator.vibrate([50, 100, 50]);
        }
        
        showReceipt('queued', queueId);
      } else {
        const submissionResult = await submitQueuedInspection(queueId);
        if (submissionResult !== 'submitted' && submissionResult !== 'pending_review' && submissionResult !== 'accepted_with_exception') {
          throw new Error('The queued inspection could not be located for submission. Please try again.');
        }
        
        // Vibrate on success
        if ('vibrate' in navigator) {
          navigator.vibrate(200);
        }
        
        showReceipt(submissionResult);
      }
    } catch (err) {
      setSubmissionError(err instanceof Error ? err.message : 'Failed to submit inspection');
    } finally {
      if (mountedRef.current) setSubmitting(false);
    }
  };

  /**
   * Replay the record blocking this checkout through the shared queue path
   * (same payload and client_submission_id; a concurrent background replay
   * shares the same request).
   */
  const handleRetryQueued = async () => {
    const blocker = queuedSubmissionBlocker;
    if (!blocker || retryingQueued) return;
    setRetryingQueued(true);
    setQueuedRetryMessage(null);
    try {
      const result = await retryQueuedSubmission(blocker.id);
      if (result === 'not_found') {
        setQueueRevision((revision) => revision + 1);
        return;
      }
      if ('vibrate' in navigator) navigator.vibrate(200);
      showReceipt(result);
    } catch (err) {
      setQueuedRetryMessage(isPermanentSubmissionFailure(err) ? err.message : describeSubmissionFailure(err));
    } finally {
      if (mountedRef.current) setRetryingQueued(false);
    }
  };

  const retryLoad = () => {
    loadedContractRef.current = null;
    setError(null);
    setLoading(true);
    setLoadAttempt((attempt) => attempt + 1);
  };

  const changeStep = (step: Step) => {
    if (currentStep === 'meter' && meterFormRef.current && !meterFormRef.current.reportValidity()) return;
    setCurrentStep(step);
  };

  const goBack = () => {
    if (currentStep === 'meter') {
      changeStep('officer');
    } else if (currentStep === 'details') {
      setCurrentStep('officer');
    } else if (currentStep === 'compartments') {
      setCurrentStep(checklist?.schema_version === 2 ? 'details' : 'meter');
    } else if (currentStep === 'submit') {
      setCurrentStep('compartments');
    }
  };

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="skeleton h-7 w-64 mb-2"></div>
        <div className="skeleton h-4 w-40 mb-6"></div>
        <div className="flex items-center justify-center gap-4 mb-8">
          {[1,2,3,4].map(i => (
            <div key={i} className="flex items-center gap-2">
              <div className="skeleton w-10 h-10 rounded-full"></div>
              <div className="skeleton h-4 w-20"></div>
            </div>
          ))}
        </div>
        <div className="skeleton h-64 w-full"></div>
      </div>
    );
  }

  if (queuedSubmissionBlocker !== null && apparatus !== null && checklist !== null) {
    const requiresOfficerReview = queuedSubmissionBlocker.status === 'requires_attention';
    const lastError = queuedRetryMessage ?? queuedSubmissionBlocker.lastError;

    return (
      <section className="mx-auto max-w-md rounded-xl border border-hub-warning/40 bg-hub-warning/5 p-6 text-hub-ink sm:p-8" aria-labelledby="queued-inspection-heading">
        <h1 id="queued-inspection-heading" className="text-lg font-semibold">
          {requiresOfficerReview ? 'Saved inspection needs review' : 'Saved inspection not sent yet'}
        </h1>
        <p role="alert" className="mt-2 font-medium text-hub-warning">
          {requiresOfficerReview
            ? 'A saved Daily Checkout using this checklist version needs officer review.'
            : 'A saved Daily Checkout using this checklist version is still awaiting synchronization.'}
        </p>
        {lastError ? (
          <p className="mt-3 rounded-lg border border-hub-border bg-hub-surface p-3 text-sm" data-testid="queued-inspection-last-error">
            <span className="font-semibold">{requiresOfficerReview ? 'Server response: ' : 'Last attempt: '}</span>
            {lastError}
          </p>
        ) : !requiresOfficerReview && (
          <p className="mt-3 text-sm text-hub-ink-secondary">It will be sent automatically when this device is connected.</p>
        )}
        <p className="mt-3 text-sm text-hub-ink-secondary">
          Its original answers remain saved on this device. To prevent a duplicate or overwritten inspection, do not begin another checkout for {apparatus.name} until this record is submitted or reconciled.
        </p>
        <div className="mt-6 flex flex-wrap gap-3">
          {!requiresOfficerReview && (
            <button
              type="button"
              onClick={() => { void handleRetryQueued(); }}
              disabled={retryingQueued || isOffline}
              className="inline-flex min-h-11 items-center rounded-lg bg-hub-blue px-5 font-semibold text-white transition-colors hover:bg-hub-blue-strong focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus disabled:cursor-not-allowed disabled:opacity-60 touch-manipulation"
            >
              {retryingQueued ? 'Sending…' : 'Retry now'}
            </button>
          )}
          <PreviousPageButton
            fallback={backFallback}
            className="inline-flex min-h-11 items-center rounded-lg border border-hub-border bg-hub-surface px-5 font-semibold text-hub-ink transition-colors hover:bg-hub-surface-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus touch-manipulation"
          >
            {returnTo?.startsWith('/stations/') ? 'Back to station' : 'Back to vehicle inspections'}
          </PreviousPageButton>
        </div>
        {!requiresOfficerReview && isOffline && (
          <p className="mt-3 text-sm text-hub-ink-secondary">This device is offline. Retry becomes available when the connection returns.</p>
        )}
        {requiresOfficerReview && (
          <p className="mt-3 text-sm text-hub-ink-secondary">Use Review saved inspections at the top of the page to see every saved inspection that needs review.</p>
        )}
      </section>
    );
  }

  if (error || !apparatus || !checklist) {
    return (
      <section className="mx-auto max-w-md rounded-xl border border-hub-border bg-hub-surface p-8 text-center" aria-labelledby="inspection-unavailable-heading">
        <div className="mb-4 inline-flex h-16 w-16 items-center justify-center rounded-full bg-hub-danger/10">
          <svg className="h-8 w-8 text-hub-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <p id="inspection-unavailable-heading" className="mb-2 font-medium text-hub-danger">Inspection Data Unavailable</p>
        <p className="mb-6 text-sm text-hub-ink-secondary">{error || 'Failed to load inspection data'}</p>
        <div className="flex flex-wrap justify-center gap-3">
          <button
            type="button"
            onClick={retryLoad}
            className="inline-flex min-h-11 items-center rounded-lg bg-hub-blue px-5 font-medium text-white transition-colors hover:bg-hub-blue-strong focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus touch-manipulation"
          >
            Try again
          </button>
          <PreviousPageButton
            fallback={backFallback}
            className="inline-flex min-h-11 items-center rounded-lg border border-hub-border bg-hub-surface px-5 font-medium text-hub-ink transition-colors hover:bg-hub-surface-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus touch-manipulation"
          >
            Back to previous page
          </PreviousPageButton>
        </div>
      </section>
    );
  }

  const isV2Checklist = checklist.schema_version === 2;
  const priorDayInspectionSession = isV2Checklist
    && checklist.inspection_session !== undefined
    && !inspectionSessionIsExpired(checklist.inspection_session)
    && checklist.inspection_session.duty_date !== easternDutyDate();
  const progress = inspectionProgress(compartments);

  const isOutOfService = apparatus.status === 'Out of Service';
  const formatServiceDateTime = (value: string) => new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value));

  return (
    <div className="apparatus-inspection">
      <header className="inspection-authority">
        <div><p className="inspection-eyebrow">Daily Checkout</p><div className="inspection-unit-title"><h1>{apparatus.name}</h1><span className={isOutOfService ? 'inspection-status is-oos' : 'inspection-status'}>{apparatus.status ?? 'Status unavailable'}</span></div>
          <div className="inspection-identity"><span>Vehicle {apparatus.vehicle_number ?? 'Not recorded'}</span><span>{officerInfo.name} · {officerInfo.shift ? 'Shift ' + officerInfo.shift : 'Shift not selected'}</span></div>
        </div>
        <div className="inspection-save" data-save-state={autosaveState === 'idle' ? 'saving' : autosaveState}><div><strong>{isOffline ? 'Offline · On this device' : 'Online'}</strong>{/* Announced politely and only when a write finishes or fails, not on every edit. */}<small role="status" aria-live="polite">{saveStatusText}</small></div><div className="inspection-progress"><small>{progress.completed} / {progress.total} inspected</small><progress value={progress.completed} max={progress.total || 1} aria-label="Inspection progress" /></div></div>
      </header>
      <div className="inspection-readiness">
        <span>{apparatus.pm_health ? apparatus.pm_health.status === 'red' ? 'PM due' : apparatus.pm_health.status === 'yellow' ? 'PM due soon' : 'PM current' : 'PM baseline unavailable'}</span>
        <span>{progress.issues} reported {progress.issues === 1 ? 'issue' : 'issues'}</span>
      </div>
      {autosaveState === 'failed' && <p role="alert" className="inspection-alert">Changes could not be saved on this device. Keep this page open until the inspection is submitted successfully.</p>}
      {submissionError && <p role="alert" className="inspection-alert">{submissionError}</p>}
      {showNotReady && !readiness.ready && (
        <p role="alert" className="mt-2 rounded-lg border border-hub-warning/40 bg-hub-warning/5 p-3 text-sm text-hub-ink">
          <span className="font-semibold">Not submitted yet.</span> {readinessExplanation(readiness)}
        </p>
      )}
      <div>
        {hasLoadedAutosave && (
          <p className="inspection-restored">Restored from autosave</p>
        )}
        {autosaveReviewMessage && (
          <p role="alert" className="mt-2 rounded-lg border border-hub-warning/40 bg-hub-warning/5 p-3 text-sm text-hub-ink">
            {autosaveReviewMessage}
          </p>
        )}
        {priorDayInspectionSession && (
          <section role="alert" className="mt-3 rounded-xl border border-hub-warning/40 bg-hub-warning/5 p-4 text-sm text-hub-ink">
            <p className="font-semibold">A valid Fire Boat inspection from the prior duty date is still active.</p>
            <p className="mt-1">
              Continue and submit that server-issued contract, or explicitly close it before starting today’s inspection. Local browser storage loss cannot start a new duty date.
            </p>
            {isOffline ? (
              <p className="mt-3 font-medium">Reconnect before abandoning the prior inspection or starting today’s inspection.</p>
            ) : (
              <button
                type="button"
                onClick={handleAbandonPriorSession}
                disabled={abandoningPriorSession}
                className="mt-3 inline-flex min-h-11 items-center rounded-lg bg-hub-warning px-4 font-semibold text-white transition-colors hover:bg-hub-warning/90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus disabled:cursor-not-allowed disabled:opacity-60"
              >
                {abandoningPriorSession ? 'Starting Today\'s Inspection…' : 'Abandon Prior Inspection / Start Today’s Inspection'}
              </button>
            )}
            {abandonmentError && <p className="mt-3 font-medium text-hub-danger">{abandonmentError}</p>}
          </section>
        )}
      </div>

      {serviceNotices.length > 0 && (
        <section aria-labelledby="service-notices-heading" className={`mb-8 rounded-xl border p-4 text-hub-ink ${isOutOfService ? 'border-hub-danger/40 bg-hub-danger/5' : 'border-hub-warning/40 bg-hub-warning/5'}`}>
          <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
            <div>
              <p className={`text-xs font-bold uppercase tracking-wider ${isOutOfService ? 'text-hub-danger' : 'text-hub-warning'}`}>Fleet awareness</p>
              <h2 id="service-notices-heading" className="mt-1 font-heading text-lg font-bold">
                {isOutOfService ? 'Unit out of service' : `Open service ${serviceNotices.length === 1 ? 'notice' : 'notices'} for this unit`}
              </h2>
            </div>
            {apparatus.status && <span className={`w-fit rounded-full bg-hub-surface px-3 py-1 text-xs font-bold ring-1 ${isOutOfService ? 'ring-hub-danger/40' : 'ring-hub-warning/40'}`}>Operational status: {apparatus.status}</span>}
          </div>
          <ul className="mt-3 space-y-2">
            {serviceNotices.map((notice) => (
              <li key={notice.id} className={`rounded-lg bg-hub-surface p-3 ring-1 ${isOutOfService ? 'ring-hub-danger/25' : 'ring-hub-warning/25'}`}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <strong>{notice.status === 'scheduled' ? `${notice.service_type || notice.title} · ${notice.ticket_number}` : `${notice.ticket_number} · ${notice.title}`}</strong>
                  <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${isOutOfService ? 'bg-hub-danger/10 text-hub-danger' : 'bg-hub-warning/10 text-hub-warning'}`}>
                    {notice.status === 'scheduled' ? 'Service scheduled' : notice.status.replaceAll('_', ' ')}
                  </span>
                </div>
                {notice.status === 'scheduled' && notice.scheduled_for && (
                  <p className="mt-1 text-sm font-semibold">
                    Scheduled{notice.scheduled_location ? ` at ${notice.scheduled_location}` : ''} · {formatServiceDateTime(notice.scheduled_for)}
                  </p>
                )}
                {notice.expected_return_at && <p className="mt-1 text-xs">Expected return: {formatServiceDateTime(notice.expected_return_at)}</p>}
                {notice.current_public_response && <p className="mt-1 text-sm">{notice.current_public_response}</p>}
              </li>
            ))}
          </ul>
          {isOutOfService ? (
            <p className="mt-3 text-sm font-bold">Refer to {serviceNotices[0].ticket_number}. Follow established Fleet and officer direction before operation.</p>
          ) : (
            <p className="mt-3 text-xs">Continue the inspection and report observed conditions. A ticket does not by itself change the unit operational status.</p>
          )}
        </section>
      )}

      {serviceNoticesUnavailable && (
        <p role="status" className="mb-6 rounded-xl border border-hub-border bg-hub-surface-muted p-3 text-sm text-hub-ink-secondary">
          Live Fleet service notices are temporarily unavailable. You can continue this inspection.
        </p>
      )}

      <nav className="mb-4 flex flex-wrap gap-2 text-sm" aria-label="Inspection workspace">
        <button type="button" className="px-3 font-semibold" aria-current={currentStep === 'compartments' ? 'page' : undefined} onClick={() => changeStep('compartments')}><Truck size={20} aria-hidden="true" />Apparatus</button>
        <button type="button" className="px-3" aria-label="Member / Vehicle Info" aria-current={currentStep === 'officer' ? 'page' : undefined} onClick={() => changeStep('officer')}><Users size={20} aria-hidden="true" />Member</button>
        <button type="button" className="px-3" aria-label={isV2Checklist ? 'Checklist details' : 'Readings · Optional'} aria-current={currentStep === (isV2Checklist ? 'details' : 'meter') ? 'page' : undefined} onClick={() => changeStep(isV2Checklist ? 'details' : 'meter')}><Gauge size={20} aria-hidden="true" /><span>{isV2Checklist ? 'Details' : <>Readings <small>Optional</small></>}</span></button>
        {!isV2Checklist && checklist.fields.length > 0 && <button type="button" className="px-3" aria-label="Checklist details" aria-current={currentStep === 'details' ? 'page' : undefined} onClick={() => changeStep('details')}><ClipboardList size={20} aria-hidden="true" />Details</button>}
      </nav>

      {currentStep === 'officer' && (
        <OfficerStep
          continueLabel={continueLabel}
          initialData={officerInfo}
          onSubmit={handleOfficerSubmit}
          onChange={handleOfficerChange}
        />
      )}

      {currentStep === 'meter' && (
        <MeterStep
          continueLabel={continueLabel}
          formRef={meterFormRef}
          maxMiles={checklist.fields.some(field => field.id === 'mileage') ? 999999999 : 2147483647}
          apparatusId={apparatus.id}
          initialData={meterData}
          pmHealth={apparatus.pm_health}
          previousHours={apparatus.current_engine_hours ?? null}
          previousMiles={apparatus.current_miles ?? null}
          onSubmit={handleMeterSubmit}
          onChange={handleMetersChange}
          onBack={goBack}
        />
      )}

      {currentStep === 'details' && (
        <ChecklistFieldsStep
          continueLabel={continueLabel}
          checklist={checklist}
          initialFieldValues={fieldValues}
          initialScheduledTasks={scheduledTasks}
          onFieldsChange={handleFieldsChange}
          onTasksChange={setScheduledTasks}
          onSubmit={handleChecklistDetailsSubmit}
          onBack={goBack}
        />
      )}

      {currentStep === 'compartments' && (
        <CompartmentStep
          actionLabel={readiness.label}
          checklistType={checklist.checklist_type}
          compartments={compartments}
          findings={checklist.open_findings}
          onChange={setCompartments}
          onSubmit={handleCompartmentsSubmit}
          onBack={() => setCurrentStep('officer')}
          backLabel="Member / Vehicle Info"
        />
      )}

      {currentStep === 'submit' && !readiness.ready && !showNotReady && (
        <div role="alert" className="mb-4 rounded-lg border border-hub-warning/40 bg-hub-warning/5 p-3 text-sm text-hub-ink">
          <p><span className="font-semibold">Not ready to submit.</span> {readinessExplanation(readiness)}</p>
          <button
            type="button"
            onClick={() => changeStep(readiness.nextStep)}
            className="mt-2 inline-flex min-h-11 items-center rounded-lg border border-hub-border bg-hub-surface px-4 font-semibold text-hub-ink hover:bg-hub-surface-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus"
          >
            {readiness.label}
          </button>
        </div>
      )}

      {currentStep === 'submit' && (
        <SubmitStep
          officerInfo={officerInfo}
          compartments={compartments}
          checklist={checklist}
          fieldValues={fieldValues}
          meters={meterData}
          signature={signature}
          onSignatureChange={setSignature}
          scheduledTasks={scheduledTasks}
          onSubmit={handleSubmit}
          onBack={goBack}
          submitting={submitting}
        />
      )}
    </div>
  );
}
