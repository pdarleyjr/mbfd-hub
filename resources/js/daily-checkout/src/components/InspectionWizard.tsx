import { Fragment, useState, useEffect, useRef } from 'react';
import { useParams, useNavigate } from 'react-router';
import { Apparatus, ApparatusServiceTicketSummary, OfficerInfo, ChecklistData, ChecklistFieldValue, Compartment, Defect, MeterData, InspectionSubmission, ScheduledChecklistTaskResult } from '../types';
import { ApiClient } from '../utils/api';
import { getQueuedSubmission, getQueuedSubmissionForApparatusAndChecklistVersion, onDailyCheckoutQueueChanged, queueSubmission, submitQueuedInspection } from '../utils/dailyCheckoutSubmissionQueue';
import type { DailyCheckoutQueuedSubmission } from '../lib/db';
import { createClientSubmissionId, saveInspectionProgress, loadInspectionProgress, clearInspectionProgress } from '../utils/storage';
import { useOffline } from '../hooks/useOffline';
import OfficerStep from './OfficerStep';
import ChecklistFieldsStep from './ChecklistFieldsStep';
import MeterStep from './MeterStep';
import CompartmentStep from './CompartmentStep';
import SubmitStep from './SubmitStep';
import PreviousPageButton from './PreviousPageButton';

type Step = 'officer' | 'meter' | 'details' | 'compartments' | 'submit';

export default function InspectionWizard() {
  const { slug } = useParams<{ slug: string }>();
  const navigate = useNavigate();
  const isOffline = useOffline();

  const [apparatus, setApparatus] = useState<Apparatus | null>(null);
  const [checklist, setChecklist] = useState<ChecklistData | null>(null);
  const [currentStep, setCurrentStep] = useState<Step>('officer');
  const [officerInfo, setOfficerInfo] = useState<OfficerInfo>({
    name: '',
    rank: 'Firefighter',
    shift: 'A',
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
  const [hasLoadedAutosave, setHasLoadedAutosave] = useState(false);
  const [autosaveReviewMessage, setAutosaveReviewMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [serviceNotices, setServiceNotices] = useState<ApparatusServiceTicketSummary[]>([]);
  const [serviceNoticesUnavailable, setServiceNoticesUnavailable] = useState(false);
  const [queuedSubmissionBlocker, setQueuedSubmissionBlocker] = useState<DailyCheckoutQueuedSubmission | null>(null);
  const [queueRevision, setQueueRevision] = useState(0);
  const clientSubmissionIdRef = useRef<string | null>(null);

  useEffect(() => onDailyCheckoutQueueChanged(() => {
    setQueueRevision((revision) => revision + 1);
  }), []);

  useEffect(() => {
    const fetchData = async () => {
      if (!slug) return;

      try {
        setQueuedSubmissionBlocker(null);
        // For now, we'll fetch all apparatuses and find the one by slug
        const apparatuses = await ApiClient.getApparatuses();
        const foundApparatus = apparatuses.find(a => a.slug === slug);

        if (!foundApparatus) {
          throw new Error('Apparatus not found');
        }

        setApparatus(foundApparatus);
        setOfficerInfo(prev => ({ ...prev, unitNumber: foundApparatus.vehicle_number }));

        // Service status is intentionally secondary. A network or API failure
        // must never prevent the inspection checklist from loading.
        ApiClient.getApparatusServiceNotices(foundApparatus.id)
          .then((notices) => {
            setServiceNotices(notices);
            setServiceNoticesUnavailable(false);
          })
          .catch(() => {
            setServiceNotices([]);
            setServiceNoticesUnavailable(true);
          });

        const checklistData = await ApiClient.getChecklist(foundApparatus.id);
        setChecklist(checklistData);
        setCompartments(checklistData.compartments);
        setFieldValues(checklistData.fields.map((field) => ({
          id: field.id,
          value: field.id === checklistData.inspection_date_field_id
            ? checklistData.inspection_date ?? ''
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

        const queuedSubmission = await getQueuedSubmissionForApparatusAndChecklistVersion(
          foundApparatus.id,
          checklistData.checklist_version,
        );
        if (queuedSubmission) {
          setQueuedSubmissionBlocker(queuedSubmission);

          return;
        }

        // Load autosaved data if available
        if (!hasLoadedAutosave) {
          const saved = loadInspectionProgress(slug, checklistData.checklist_version);
          if (saved) {
            if (saved.checklist_version === checklistData.checklist_version) {
              setOfficerInfo(saved.officer);
              if (saved.meter) {
                setMeterData(saved.meter);
              }
              setCompartments(saved.compartments);
              if (checklistData.schema_version === 2) {
                if (saved.fieldValues) {
                  setFieldValues(saved.fieldValues);
                }
                if (saved.scheduledTasks) {
                  setScheduledTasks(saved.scheduledTasks);
                }
              }
              // Resume at the first incomplete current-contract step if officer info exists.
              if (saved.officer.name) {
                setCurrentStep(saved.compartments.some(c => c.items.some(i => i.status !== 'Present'))
                  ? 'compartments'
                  : checklistData.schema_version === 2
                    ? 'details'
                    : 'meter');
              }
              setHasLoadedAutosave(true);
            } else {
              setAutosaveReviewMessage('A previously saved inspection uses a different checklist version. It remains saved on this device and requires officer review; this form is using the current checklist.');
            }
          }
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load data');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [slug, hasLoadedAutosave, queueRevision]);

  // Autosave progress
  useEffect(() => {
    if (slug && apparatus && checklist && (currentStep === 'meter' || currentStep === 'details' || currentStep === 'compartments' || currentStep === 'submit')) {
      const saveData = {
        checklist_version: checklist.checklist_version,
        officer: officerInfo,
        meter: meterData,
        compartments,
        fieldValues,
        scheduledTasks,
      };
      saveInspectionProgress(slug, saveData);
    }
  }, [officerInfo, meterData, compartments, fieldValues, scheduledTasks, currentStep, slug, apparatus, checklist]);

  const handleOfficerSubmit = (info: OfficerInfo) => {
    setOfficerInfo(info);
    setCurrentStep(checklist?.schema_version === 2 ? 'details' : 'meter');
  };

  const handleMeterSubmit = (data: MeterData) => {
    setMeterData(data);
    setCurrentStep('compartments');
  };

  const handleChecklistDetailsSubmit = (
    updatedFieldValues: Array<{ id: string; value: ChecklistFieldValue }>,
    updatedScheduledTasks: ScheduledChecklistTaskResult[],
  ) => {
    setFieldValues(updatedFieldValues);
    setScheduledTasks(updatedScheduledTasks);
    setCurrentStep('compartments');
  };

  const handleCompartmentsSubmit = (updatedCompartments: Compartment[]) => {
    setCompartments(updatedCompartments);
    setCurrentStep('submit');
  };

  const handleSubmit = async (signature: string | null) => {
    if (!apparatus || !slug || !checklist) return;

    setSubmitting(true);
    try {
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
            notes: item.notes || null,
          })),
        })),
        defects,
        ...(checklist.schema_version === 2 ? {
          field_values: fieldValues,
          scheduled_tasks: scheduledTasks,
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

      if (isOffline) {
        // Vibrate to indicate queued
        if ('vibrate' in navigator) {
          navigator.vibrate([50, 100, 50]);
        }
        
        // Clear autosave
        clearInspectionProgress(slug, checklist.checklist_version);
        
        navigate('/success?queued=true', {
          state: { queuedSubmissionId: queueId },
        });
      } else {
        const submissionResult = await submitQueuedInspection(queueId);
        if (submissionResult !== 'submitted' && submissionResult !== 'pending_review') {
          throw new Error('The queued inspection could not be located for submission. Please try again.');
        }
        
        // Vibrate on success
        if ('vibrate' in navigator) {
          navigator.vibrate(200);
        }
        
        // Clear autosave
        clearInspectionProgress(slug, checklist.checklist_version);
        
        navigate(submissionResult === 'pending_review' ? '/success?review=pending' : '/success');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to submit inspection');
    } finally {
      setSubmitting(false);
    }
  };

  const goBack = () => {
    if (currentStep === 'meter') {
      setCurrentStep('officer');
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

    return (
      <div className="max-w-md mx-auto text-center p-8 bg-amber-50 rounded-xl ring-1 ring-amber-300">
        <p role="alert" className="text-amber-950 font-medium mb-3">
          {requiresOfficerReview
            ? 'A saved Daily Checkout using this checklist version needs officer review.'
            : 'A saved Daily Checkout using this checklist version is still awaiting synchronization.'}
        </p>
        <p className="text-amber-900 text-sm mb-6">
          Its original payload remains saved on this device. To prevent a duplicate or overwritten inspection, do not begin another checkout for {apparatus.name} until this record is submitted or reconciled.
        </p>
        <PreviousPageButton
          fallback="/vehicle-inspections"
          className="px-5 py-2.5 bg-amber-700 text-white rounded-lg hover:bg-amber-800 transition-colors touch-manipulation font-medium"
        >
          Back to vehicle inspections
        </PreviousPageButton>
      </div>
    );
  }

  if (error || !apparatus || !checklist) {
    return (
      <div className="max-w-md mx-auto text-center p-8 bg-neutral-100 rounded-xl ring-1 ring-neutral-200/60">
        <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-50 mb-4">
          <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <p className="text-red-600 font-medium mb-2">Inspection Data Unavailable</p>
        <p className="text-neutral-500 text-sm mb-6">{error || 'Failed to load inspection data'}</p>
        <PreviousPageButton
          fallback="/vehicle-inspections"
          className="px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors touch-manipulation font-medium"
        >
          Back to previous page
        </PreviousPageButton>
      </div>
    );
  }

  const isV2Checklist = checklist.schema_version === 2;
  const progressSteps: Array<{ step: Step; label: string }> = isV2Checklist
    ? [
        { step: 'officer', label: 'Officer' },
        { step: 'details', label: 'Details' },
        { step: 'compartments', label: 'Check' },
        { step: 'submit', label: 'Submit' },
      ]
    : [
        { step: 'officer', label: 'Officer' },
        { step: 'meter', label: 'Meters' },
        { step: 'compartments', label: 'Check' },
        { step: 'submit', label: 'Submit' },
      ];

  const isStepCompleted = (step: Step) => {
    const currentIndex = progressSteps.findIndex((entry) => entry.step === currentStep);
    const stepIndex = progressSteps.findIndex((entry) => entry.step === step);
    return stepIndex < currentIndex;
  };

  const isOutOfService = apparatus.status === 'Out of Service';
  const formatServiceDateTime = (value: string) => new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value));

  return (
    <div>
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-neutral-800 mb-2 font-heading">
          Daily Inspection: {apparatus.name}
        </h1>
        <p className="text-neutral-500">Unit: {apparatus.vehicle_number}</p>
        {hasLoadedAutosave && (
          <p className="text-sm text-sky-600 mt-1">📝 Restored from autosave</p>
        )}
        {autosaveReviewMessage && (
          <p role="alert" className="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
            {autosaveReviewMessage}
          </p>
        )}
      </div>

      {serviceNotices.length > 0 && (
        <section aria-labelledby="service-notices-heading" className={`mb-8 rounded-2xl border p-4 ${isOutOfService ? 'border-red-400 bg-red-50 text-red-950' : 'border-amber-300 bg-amber-50 text-amber-950'}`}>
          <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
            <div>
              <p className={`text-xs font-bold uppercase tracking-wider ${isOutOfService ? 'text-red-800' : 'text-amber-800'}`}>Fleet awareness</p>
              <h2 id="service-notices-heading" className="mt-1 font-heading text-lg font-bold">
                {isOutOfService ? 'Unit out of service' : `Open service ${serviceNotices.length === 1 ? 'notice' : 'notices'} for this unit`}
              </h2>
            </div>
            {apparatus.status && <span className={`w-fit rounded-full bg-white px-3 py-1 text-xs font-bold ring-1 ${isOutOfService ? 'ring-red-300' : 'ring-amber-300'}`}>Operational status: {apparatus.status}</span>}
          </div>
          <ul className="mt-3 space-y-2">
            {serviceNotices.map((notice) => (
              <li key={notice.id} className={`rounded-xl bg-white/80 p-3 ring-1 ${isOutOfService ? 'ring-red-200' : 'ring-amber-200'}`}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <strong>{notice.status === 'scheduled' ? `${notice.service_type || notice.title} · ${notice.ticket_number}` : `${notice.ticket_number} · ${notice.title}`}</strong>
                  <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${isOutOfService ? 'bg-red-100' : 'bg-amber-100'}`}>
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
        <p role="status" className="mb-6 rounded-xl border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-600">
          Live Fleet service notices are temporarily unavailable. You can continue this inspection.
        </p>
      )}

      {/* Progress indicator */}
      <div className="mb-8">
        <div className="flex items-center justify-center space-x-2 md:space-x-4">
          {progressSteps.map(({ step, label }, index) => (
            <Fragment key={step}>
              <div className={`flex items-center ${currentStep === step ? 'text-red-600' : 'text-neutral-400'}`}>
                <div className={`flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold ${
                  currentStep === step ? 'bg-red-600 text-white shadow-md'
                    : isStepCompleted(step) ? 'bg-teal-500 text-white'
                      : 'bg-neutral-200'
                }`}>
                  {isStepCompleted(step) ? '✓' : index + 1}
                </div>
                <span className="ml-2 hidden text-sm font-medium md:inline">{label}</span>
              </div>
              {index < progressSteps.length - 1 && (
                <div className={`h-0.5 w-6 md:w-10 ${
                  isStepCompleted(step) || currentStep === progressSteps[index + 1].step ? 'bg-red-600' : 'bg-neutral-200'
                }`} />
              )}
            </Fragment>
          ))}
        </div>
      </div>

      {currentStep === 'officer' && (
        <OfficerStep
          initialData={officerInfo}
          onSubmit={handleOfficerSubmit}
        />
      )}

      {currentStep === 'meter' && (
        <MeterStep
          apparatusId={apparatus.id}
          apparatusName={apparatus.name}
          vehicleNumber={apparatus.vehicle_number}
          initialData={meterData}
          previousHours={apparatus.current_engine_hours ?? null}
          previousMiles={apparatus.current_miles ?? null}
          onSubmit={handleMeterSubmit}
          onBack={goBack}
        />
      )}

      {currentStep === 'details' && (
        <ChecklistFieldsStep
          checklist={checklist}
          initialFieldValues={fieldValues}
          initialScheduledTasks={scheduledTasks}
          onSubmit={handleChecklistDetailsSubmit}
          onBack={goBack}
        />
      )}

      {currentStep === 'compartments' && (
        <CompartmentStep
          compartments={compartments}
          onSubmit={handleCompartmentsSubmit}
          onBack={goBack}
          backLabel={isV2Checklist ? 'Back to Checklist Details' : undefined}
        />
      )}

      {currentStep === 'submit' && (
        <SubmitStep
          officerInfo={officerInfo}
          compartments={compartments}
          onSubmit={handleSubmit}
          onBack={goBack}
          submitting={submitting}
        />
      )}
    </div>
  );
}
