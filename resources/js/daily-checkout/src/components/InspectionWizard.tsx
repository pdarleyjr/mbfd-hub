import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router';
import { Apparatus, ApparatusServiceTicketSummary, OfficerInfo, ChecklistData, Compartment, Defect, MeterData, InspectionSubmission } from '../types';
import { ApiClient } from '../utils/api';
import { saveInspectionProgress, loadInspectionProgress, clearInspectionProgress, queueSubmission, getSubmissionQueue, removeFromQueue } from '../utils/storage';
import { useOffline } from '../hooks/useOffline';
import OfficerStep from './OfficerStep';
import MeterStep from './MeterStep';
import CompartmentStep from './CompartmentStep';
import SubmitStep from './SubmitStep';
import PreviousPageButton from './PreviousPageButton';

type Step = 'officer' | 'meter' | 'compartments' | 'submit';

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
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [hasLoadedAutosave, setHasLoadedAutosave] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [serviceNotices, setServiceNotices] = useState<ApparatusServiceTicketSummary[]>([]);
  const [serviceNoticesUnavailable, setServiceNoticesUnavailable] = useState(false);

  useEffect(() => {
    const fetchData = async () => {
      if (!slug) return;

      try {
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

        // Load autosaved data if available
        if (!hasLoadedAutosave) {
          const saved = loadInspectionProgress(slug);
          if (saved) {
            setOfficerInfo(saved.officer);
            if (saved.meter) {
              setMeterData(saved.meter);
            }
            setCompartments(saved.compartments);
            // Resume at meter step if officer info exists
            if (saved.officer.name) {
              setCurrentStep(saved.compartments.some(c => c.items.some(i => i.status !== 'Present')) ? 'compartments' : 'meter');
            }
            setHasLoadedAutosave(true);
          }
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load data');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [slug, hasLoadedAutosave]);

  // Auto-sync queued submissions when back online
  useEffect(() => {
    if (!isOffline) {
      const syncQueue = async () => {
        const queue = getSubmissionQueue();
        if (queue.length === 0) return;

        for (const item of queue) {
          try {
            await ApiClient.submitInspection(item.apparatusId, item.data);
            removeFromQueue(item.id);
            
            // Vibrate on successful sync
            if ('vibrate' in navigator) {
              navigator.vibrate(200);
            }
          } catch (error) {
            console.error('Failed to sync queued submission:', error);
            // Leave in queue to try again later
          }
        }
      };

      syncQueue();
    }
  }, [isOffline]);

  // Autosave progress
  useEffect(() => {
    if (slug && apparatus && (currentStep === 'meter' || currentStep === 'compartments' || currentStep === 'submit')) {
      const saveData = {
        officer: officerInfo,
        meter: meterData,
        compartments,
      };
      saveInspectionProgress(slug, saveData);
    }
  }, [officerInfo, meterData, compartments, currentStep, slug, apparatus]);

  const handleOfficerSubmit = (info: OfficerInfo) => {
    setOfficerInfo(info);
    setCurrentStep('meter');
  };

  const handleMeterSubmit = (data: MeterData) => {
    setMeterData(data);
    setCurrentStep('compartments');
  };

  const handleCompartmentsSubmit = (updatedCompartments: Compartment[]) => {
    setCompartments(updatedCompartments);
    setCurrentStep('submit');
  };

  const handleSubmit = async (signature: string | null) => {
    if (!apparatus || !slug) return;

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
            });
          }
        });
      });

      const submission: InspectionSubmission = {
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
        officer_signature: signature,
      };

      if (isOffline) {
        // Queue for later submission
        queueSubmission(apparatus.id, submission);
        
        // Vibrate to indicate queued
        if ('vibrate' in navigator) {
          navigator.vibrate([50, 100, 50]);
        }
        
        // Clear autosave
        clearInspectionProgress(slug);
        
        navigate('/success?queued=true');
      } else {
        // Submit immediately
        await ApiClient.submitInspection(apparatus.id, submission);
        
        // Vibrate on success
        if ('vibrate' in navigator) {
          navigator.vibrate(200);
        }
        
        // Clear autosave
        clearInspectionProgress(slug);
        
        navigate('/success');
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
    } else if (currentStep === 'compartments') {
      setCurrentStep('meter');
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

  // Get step number for progress indicator
  const getStepNumber = () => {
    switch (currentStep) {
      case 'officer': return 1;
      case 'meter': return 2;
      case 'compartments': return 3;
      case 'submit': return 4;
      default: return 1;
    }
  };

  const isStepCompleted = (step: Step) => {
    const order: Step[] = ['officer', 'meter', 'compartments', 'submit'];
    const currentIndex = order.indexOf(currentStep);
    const stepIndex = order.indexOf(step);
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

      {/* Progress indicator — 4 steps */}
      <div className="mb-8">
        <div className="flex items-center justify-center space-x-2 md:space-x-4">
          {/* Step 1: Officer Info */}
          <div className={`flex items-center ${currentStep === 'officer' ? 'text-red-600' : 'text-neutral-400'}`}>
            <div className={`w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold ${
              currentStep === 'officer' ? 'bg-red-600 text-white shadow-md' : 
              isStepCompleted('officer') ? 'bg-teal-500 text-white' : 'bg-neutral-200'
            }`}>
              {isStepCompleted('officer') ? '✓' : '1'}
            </div>
            <span className="ml-2 text-sm font-medium hidden md:inline">Officer</span>
          </div>
          <div className={`w-6 md:w-10 h-0.5 ${isStepCompleted('officer') || currentStep === 'meter' ? 'bg-red-600' : 'bg-neutral-200'}`} />
          
          {/* Step 2: Meter */}
          <div className={`flex items-center ${currentStep === 'meter' ? 'text-red-600' : 'text-neutral-400'}`}>
            <div className={`w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold ${
              currentStep === 'meter' ? 'bg-red-600 text-white shadow-md' :
              isStepCompleted('meter') ? 'bg-teal-500 text-white' : 'bg-neutral-200'
            }`}>
              {isStepCompleted('meter') ? '✓' : '2'}
            </div>
            <span className="ml-2 text-sm font-medium hidden md:inline">Meters</span>
          </div>
          <div className={`w-6 md:w-10 h-0.5 ${isStepCompleted('meter') || currentStep === 'compartments' ? 'bg-red-600' : 'bg-neutral-200'}`} />
          
          {/* Step 3: Compartments */}
          <div className={`flex items-center ${currentStep === 'compartments' ? 'text-red-600' : 'text-neutral-400'}`}>
            <div className={`w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold ${
              currentStep === 'compartments' ? 'bg-red-600 text-white shadow-md' :
              isStepCompleted('compartments') ? 'bg-teal-500 text-white' : 'bg-neutral-200'
            }`}>
              {isStepCompleted('compartments') ? '✓' : '3'}
            </div>
            <span className="ml-2 text-sm font-medium hidden md:inline">Check</span>
          </div>
          <div className={`w-6 md:w-10 h-0.5 ${isStepCompleted('compartments') || currentStep === 'submit' ? 'bg-red-600' : 'bg-neutral-200'}`} />
          
          {/* Step 4: Submit */}
          <div className={`flex items-center ${currentStep === 'submit' ? 'text-red-600' : 'text-neutral-400'}`}>
            <div className={`w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold ${
              currentStep === 'submit' ? 'bg-red-600 text-white shadow-md' : 'bg-neutral-200'
            }`}>
              4
            </div>
            <span className="ml-2 text-sm font-medium hidden md:inline">Submit</span>
          </div>
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

      {currentStep === 'compartments' && (
        <CompartmentStep
          compartments={compartments}
          onSubmit={handleCompartmentsSubmit}
          onBack={goBack}
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
