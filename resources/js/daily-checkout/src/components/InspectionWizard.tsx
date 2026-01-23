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
              item_name: item.name,
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
      <div className="flex justify-center items-center min-h-64">
        <div className="text-lg">Loading inspection data...</div>
      </div>
    );
  }

  if (error || !apparatus || !checklist) {
    return (
      <div className="text-center text-red-600 p-4">
        <p>Error: {error || 'Failed to load inspection data'}</p>
        <button
          onClick={() => navigate('/')}
          className="mt-4 px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 touch-manipulation"
        >
          Back to Apparatus List
        </button>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-2">
          Daily Inspection: {apparatus.name}
        </h1>
        <p className="text-gray-600">Unit: {apparatus.vehicle_number}</p>
        {hasLoadedAutosave && (
          <p className="text-sm text-blue-600 mt-1">📝 Restored from autosave</p>
        )}
      </div>

      {/* Progress indicator */}
      <div className="mb-8">
        <div className="flex items-center justify-center space-x-4">
          <div className={`flex items-center ${currentStep === 'officer' ? 'text-blue-600' : 'text-gray-400'}`}>
            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium ${
              currentStep === 'officer' ? 'bg-blue-600 text-white' : 'bg-gray-200'
            }`}>
              1
            </div>
            <span className="ml-2">Officer Info</span>
          </div>
          <div className={`w-8 h-0.5 ${currentStep === 'compartments' || currentStep === 'submit' ? 'bg-blue-600' : 'bg-gray-200'}`} />
          <div className={`flex items-center ${currentStep === 'compartments' ? 'text-blue-600' : 'text-gray-400'}`}>
            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium ${
              currentStep === 'compartments' ? 'bg-blue-600 text-white' : 'bg-gray-200'
            }`}>
              2
            </div>
            <span className="ml-2">Compartments</span>
          </div>
          <div className={`w-8 h-0.5 ${currentStep === 'submit' ? 'bg-blue-600' : 'bg-gray-200'}`} />
          <div className={`flex items-center ${currentStep === 'submit' ? 'text-blue-600' : 'text-gray-400'}`}>
            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium ${
              currentStep === 'submit' ? 'bg-blue-600 text-white' : 'bg-gray-200'
            }`}>
              3
            </div>
            <span className="ml-2">Submit</span>
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