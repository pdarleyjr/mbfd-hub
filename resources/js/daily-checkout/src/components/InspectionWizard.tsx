import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Apparatus, OfficerInfo, ChecklistData, Compartment, Defect } from '../types';
import { ApiClient } from '../utils/api';
import { saveInspectionProgress, loadInspectionProgress, clearInspectionProgress, queueSubmission, getSubmissionQueue, removeFromQueue } from '../utils/storage';
import { useOffline } from '../hooks/useOffline';
import OfficerStep from './OfficerStep';
import CompartmentStep from './CompartmentStep';
import SubmitStep from './SubmitStep';

type Step = 'officer' | 'compartments' | 'submit';

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
  const [compartments, setCompartments] = useState<Compartment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [hasLoadedAutosave, setHasLoadedAutosave] = useState(false);

  useEffect(() => {
    const fetchData = async () => {
      if (!slug) return;

      try {
        // For now, we'll fetch all apparatuses and find the one by slug
        // In a real app, you'd have an endpoint to get apparatus by slug
        const apparatuses = await ApiClient.getApparatuses();
        const foundApparatus = apparatuses.find(a => a.slug === slug);

        if (!foundApparatus) {
          throw new Error('Apparatus not found');
        }

        setApparatus(foundApparatus);
        setOfficerInfo(prev => ({ ...prev, unitNumber: foundApparatus.vehicle_number }));

        const checklistData = await ApiClient.getChecklist(foundApparatus.id);
        setChecklist(checklistData);
        setCompartments(checklistData.compartments);

        // Load autosaved data if available
        if (!hasLoadedAutosave) {
          const saved = loadInspectionProgress(slug);
          if (saved) {
            setOfficerInfo(saved.officer);
            setCompartments(saved.compartments);
            setCurrentStep('compartments'); // Resume where they left off
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
    if (slug && apparatus && (currentStep === 'compartments' || currentStep === 'submit')) {
      const saveData = {
        officer: officerInfo,
        compartments,
      };
      saveInspectionProgress(slug, saveData);
    }
  }, [officerInfo, compartments, currentStep, slug, apparatus]);

  const handleOfficerSubmit = (info: OfficerInfo) => {
    setOfficerInfo(info);
    setCurrentStep('compartments');
  };

  const handleCompartmentsSubmit = (updatedCompartments: Compartment[]) => {
    setCompartments(updatedCompartments);
    setCurrentStep('submit');
  };

  const handleSubmit = async () => {
    if (!apparatus || !slug) return;

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

      const submission = {
        operator_name: officerInfo.name,
        rank: officerInfo.rank,
        shift: officerInfo.shift,
        unit_number: officerInfo.unitNumber,
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
    }
  };

  const goBack = () => {
    if (currentStep === 'compartments') {
      setCurrentStep('officer');
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
          {[1,2,3].map(i => (
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
        <button
          onClick={() => navigate('/')}
          className="px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors touch-manipulation font-medium"
        >
          Back to Forms
        </button>
      </div>
    );
  }

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

      {/* Progress indicator — larger circles */}
      <div className="mb-8">
        <div className="flex items-center justify-center space-x-4">
          <div className={`flex items-center ${currentStep === 'officer' ? 'text-red-600' : 'text-neutral-400'}`}>
            <div className={`w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold ${
              currentStep === 'officer' ? 'bg-red-600 text-white shadow-md' : 
              (currentStep === 'compartments' || currentStep === 'submit') ? 'bg-teal-500 text-white' : 'bg-neutral-200'
            }`}>
              {(currentStep === 'compartments' || currentStep === 'submit') ? '✓' : '1'}
            </div>
            <span className="ml-2 text-sm font-medium">Officer Info</span>
          </div>
          <div className={`w-10 h-0.5 ${currentStep === 'compartments' || currentStep === 'submit' ? 'bg-red-600' : 'bg-neutral-200'}`} />
          <div className={`flex items-center ${currentStep === 'compartments' ? 'text-red-600' : 'text-neutral-400'}`}>
            <div className={`w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold ${
              currentStep === 'compartments' ? 'bg-red-600 text-white shadow-md' :
              currentStep === 'submit' ? 'bg-teal-500 text-white' : 'bg-neutral-200'
            }`}>
              {currentStep === 'submit' ? '✓' : '2'}
            </div>
            <span className="ml-2 text-sm font-medium">Compartments</span>
          </div>
          <div className={`w-10 h-0.5 ${currentStep === 'submit' ? 'bg-red-600' : 'bg-neutral-200'}`} />
          <div className={`flex items-center ${currentStep === 'submit' ? 'text-red-600' : 'text-neutral-400'}`}>
            <div className={`w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold ${
              currentStep === 'submit' ? 'bg-red-600 text-white shadow-md' : 'bg-neutral-200'
            }`}>
              3
            </div>
            <span className="ml-2 text-sm font-medium">Submit</span>
          </div>
        </div>
      </div>

      {currentStep === 'officer' && (
        <OfficerStep
          initialData={officerInfo}
          onSubmit={handleOfficerSubmit}
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
          submitting={false}
        />
      )}
    </div>
  );
}