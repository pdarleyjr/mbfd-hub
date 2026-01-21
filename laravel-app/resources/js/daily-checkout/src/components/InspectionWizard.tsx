import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ApiClient } from '../utils/api';

interface ApiApparatus {
  id: number;
  unit_id: string;
  make: string;
  model: string;
  year: number;
  status: string;
  mileage: number;
}

type Rank = 'Chief' | 'Deputy Chief' | 'Captain' | 'Lieutenant' | 'Sergeant' | 'Corporal' | 'Firefighter';
type Shift = 'A' | 'B' | 'C';

interface OfficerInfo {
  name: string;
  rank: Rank;
  shift: Shift;
  unitNumber: string;
}

type Step = 'officer' | 'submit';

export default function InspectionWizard() {
  const { slug } = useParams<{ slug: string }>();
  const navigate = useNavigate();

  const [apparatus, setApparatus] = useState<ApiApparatus | null>(null);
  const [currentStep, setCurrentStep] = useState<Step>('officer');
  const [officerInfo, setOfficerInfo] = useState<OfficerInfo>({
    name: '',
    rank: 'Firefighter',
    shift: 'A',
    unitNumber: '',
  });
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchData = async () => {
      if (!slug) return;

      try {
        const apparatusId = parseInt(slug, 10);
        const apparatuses = await ApiClient.getApparatuses();
        const foundApparatus = apparatuses.find((a: ApiApparatus) => a.id === apparatusId);

        if (!foundApparatus) {
          throw new Error('Apparatus not found');
        }

        setApparatus(foundApparatus);
        setOfficerInfo(prev => ({ ...prev, unitNumber: foundApparatus.unit_id }));
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load data');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [slug]);

  const handleSubmit = async () => {
    if (!apparatus || !officerInfo.name.trim()) {
      setError('Please fill in all required fields');
      return;
    }

    setSubmitting(true);
    try {
      await ApiClient.submitInspection(apparatus.id, {
        officer: officerInfo,
        compartments: [],
      });
      navigate('/success');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to submit inspection');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-blue-900 to-blue-700 flex items-center justify-center">
        <div className="text-white text-xl">Loading inspection data...</div>
      </div>
    );
  }

  if (error || !apparatus) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-blue-900 to-blue-700 flex items-center justify-center">
        <div className="text-center text-white p-4">
          <p className="text-red-300 mb-4">{error || 'Failed to load apparatus data'}</p>
          <button
            onClick={() => navigate('/')}
            className="px-6 py-3 bg-white text-blue-900 rounded-lg font-semibold hover:bg-blue-100"
          >
            Back to Apparatus List
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-900 to-blue-700">
      <div className="max-w-2xl mx-auto py-12 px-4">
        {/* Header */}
        <div className="text-center mb-8">
          <div className="inline-block bg-red-600 text-white px-6 py-2 rounded-lg mb-4">
            <span className="text-2xl font-bold">{apparatus.unit_id}</span>
          </div>
          <h1 className="text-3xl font-bold text-white mb-2">Daily Inspection</h1>
          <p className="text-blue-200">{apparatus.year} {apparatus.make} {apparatus.model}</p>
        </div>

        {/* Form */}
        <div className="bg-white rounded-xl shadow-xl p-8">
          <h2 className="text-xl font-semibold text-gray-900 mb-6">User Information</h2>
          
          <div className="space-y-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Name *</label>
              <input
                type="text"
                value={officerInfo.name}
                onChange={(e) => setOfficerInfo(prev => ({ ...prev, name: e.target.value }))}
                className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="Enter your name"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Rank</label>
              <select
                value={officerInfo.rank}
                onChange={(e) => setOfficerInfo(prev => ({ ...prev, rank: e.target.value as Rank }))}
                className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              >
                <option value="Chief">Chief</option>
                <option value="Deputy Chief">Deputy Chief</option>
                <option value="Captain">Captain</option>
                <option value="Lieutenant">Lieutenant</option>
                <option value="Sergeant">Sergeant</option>
                <option value="Corporal">Corporal</option>
                <option value="Firefighter">Firefighter</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Shift</label>
              <select
                value={officerInfo.shift}
                onChange={(e) => setOfficerInfo(prev => ({ ...prev, shift: e.target.value as Shift }))}
                className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              >
                <option value="A">Shift A</option>
                <option value="B">Shift B</option>
                <option value="C">Shift C</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Unit Number</label>
              <input
                type="text"
                value={officerInfo.unitNumber}
                readOnly
                className="w-full px-4 py-3 border border-gray-200 rounded-lg bg-gray-50 text-gray-600"
              />
            </div>
          </div>

          {error && (
            <div className="mt-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm">
              {error}
            </div>
          )}

          <div className="mt-8 flex gap-4">
            <button
              onClick={() => navigate('/')}
              className="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300"
            >
              Cancel
            </button>
            <button
              onClick={handleSubmit}
              disabled={submitting || !officerInfo.name.trim()}
              className="flex-1 px-6 py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {submitting ? 'Submitting...' : 'Complete Inspection'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}