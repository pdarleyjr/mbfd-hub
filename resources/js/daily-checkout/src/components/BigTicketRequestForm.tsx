import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Station, BigTicketRoomType, BigTicketRequestFormData } from '../types';
import { ApiClient } from '../utils/api';

const ROOM_TYPES: { value: BigTicketRoomType; label: string; description: string }[] = [
  { value: 'kitchen', label: 'Kitchen', description: 'Appliances, cookware, dining supplies' },
  { value: 'common_areas', label: 'Common Areas', description: 'Furniture, TVs, recreational equipment' },
  { value: 'dorms', label: 'Dorms', description: 'Beds, mattresses, lockers, furniture' },
  { value: 'apparatus_bay', label: 'Apparatus Bay', description: 'Heavy equipment, tools, gear storage' },
  { value: 'watch_office', label: 'Watch Office', description: 'Communication equipment, office furniture' },
];

const CURATED_ITEMS = [
  { id: 'refrigerator', name: 'Refrigerator', category: 'Appliances' },
  { id: 'microwave', name: 'Microwave', category: 'Appliances' },
  { id: 'dishwasher', name: 'Dishwasher', category: 'Appliances' },
  { id: 'stove_oven', name: 'Stove/Oven', category: 'Appliances' },
  { id: 'freezer', name: 'Freezer', category: 'Appliances' },
  { id: 'coffee_maker', name: 'Coffee Maker', category: 'Appliances' },
  { id: 'toaster', name: 'Toaster', category: 'Appliances' },
  { id: 'mattress_single', name: 'Single Mattress', category: 'Furniture' },
  { id: 'mattress_queen', name: 'Queen Mattress', category: 'Furniture' },
  { id: 'bed_frame', name: 'Bed Frame', category: 'Furniture' },
  { id: 'locker', name: 'Locker', category: 'Furniture' },
  { id: 'desk', name: 'Desk', category: 'Furniture' },
  { id: 'chair', name: 'Chair', category: 'Furniture' },
  { id: 'table', name: 'Table', category: 'Furniture' },
  { id: 'sofa', name: 'Sofa', category: 'Furniture' },
  { id: 'tv', name: 'TV/Monitor', category: 'Electronics' },
  { id: 'computer', name: 'Computer/Workstation', category: 'Electronics' },
  { id: 'printer', name: 'Printer', category: 'Electronics' },
  { id: 'fan', name: 'Fan', category: 'Climate Control' },
  { id: 'heater', name: 'Heater', category: 'Climate Control' },
  { id: 'ac_unit', name: 'AC Unit', category: 'Climate Control' },
];

const ITEMS_BY_CATEGORY = CURATED_ITEMS.reduce((acc, item) => {
  if (!acc[item.category]) acc[item.category] = [];
  acc[item.category].push(item);
  return acc;
}, {} as Record<string, typeof CURATED_ITEMS>);

export default function BigTicketRequestForm() {
  const navigate = useNavigate();
  const [step, setStep] = useState(1);
  const [stations, setStations] = useState<Station[]>([]);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  
  const [formData, setFormData] = useState<BigTicketRequestFormData>({
    station_id: 0,
    room_type: 'kitchen',
    items: [],
    other_item: '',
    notes: '',
  });

  useEffect(() => {
    const fetchStations = async () => {
      try {
        const data = await ApiClient.getStations();
        setStations(data);
        if (data.length === 1) {
          setFormData(prev => ({ ...prev, station_id: data[0].id }));
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load stations');
      } finally {
        setLoading(false);
      }
    };
    fetchStations();
  }, []);

  const handleStationSelect = (stationId: number) => {
    setFormData(prev => ({ ...prev, station_id: stationId }));
    setStep(2);
  };

  const handleRoomSelect = (roomType: BigTicketRoomType) => {
    setFormData((prev: BigTicketRequestFormData) => ({ ...prev, room_type: roomType }));
    setStep(3);
  };

  const handleItemToggle = (itemId: string) => {
    setFormData(prev => ({
      ...prev,
      items: prev.items.includes(itemId)
        ? prev.items.filter(id => id !== itemId)
        : [...prev.items, itemId],
    }));
  };

  const handleSubmit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await ApiClient.submitBigTicketRequest(formData);
      navigate('/forms-hub/success', { state: { message: 'Big ticket request submitted successfully!' } });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to submit request');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-64">
        <div className="text-lg">Loading stations...</div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <div className="bg-orange-600 text-white py-4 px-4">
        <div className="max-w-2xl mx-auto">
          <button
            onClick={() => step > 1 ? setStep(step - 1) : navigate('/forms-hub')}
            className="flex items-center text-orange-100 hover:text-white mb-2"
          >
            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            Back
          </button>
          <h1 className="text-xl font-bold">Big Ticket Item Request</h1>
          <p className="text-orange-100 text-sm">Step {step} of 4</p>
        </div>
      </div>

      {/* Progress Bar */}
      <div className="max-w-2xl mx-auto px-4 py-4">
        <div className="flex items-center justify-between">
          {[1, 2, 3, 4].map((s) => (
            <div
              key={s}
              className={`flex items-center ${s < 4 ? 'flex-1' : ''}`}
            >
              <div
                className={`w-8 h-8 rounded-full flex items-center justify-center font-medium ${
                  s <= step ? 'bg-orange-600 text-white' : 'bg-gray-200 text-gray-500'
                }`}
              >
                {s < step ? '✓' : s}
              </div>
              {s < 4 && (
                <div className={`flex-1 h-1 mx-2 ${s < step ? 'bg-orange-600' : 'bg-gray-200'}`} />
              )}
            </div>
          ))}
        </div>
      </div>

      <div className="max-w-2xl mx-auto px-4 pb-8">
        {error && (
          <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
            {error}
          </div>
        )}

        {/* Step 1: Select Station */}
        {step === 1 && (
          <div className="space-y-4">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Select a Station</h2>
            <div className="grid grid-cols-1 gap-3">
              {stations.map((station) => (
                <button
                  key={station.id}
                  onClick={() => handleStationSelect(station.id)}
                  className="p-4 bg-white border border-gray-200 rounded-lg hover:border-orange-400 hover:shadow-md transition-all text-left"
                >
                  <div className="font-medium text-gray-900">Station {station.station_number}</div>
                  <div className="text-sm text-gray-500">{station.name}</div>
                  <div className="text-sm text-gray-400">{station.address}, {station.city}</div>
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Step 2: Select Room */}
        {step === 2 && (
          <div className="space-y-4">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Select Room Type</h2>
            <div className="grid grid-cols-1 gap-3">
              {ROOM_TYPES.map((room) => (
                <button
                  key={room.value}
                  onClick={() => handleRoomSelect(room.value)}
                  className="p-4 bg-white border border-gray-200 rounded-lg hover:border-orange-400 hover:shadow-md transition-all text-left"
                >
                  <div className="font-medium text-gray-900">{room.label}</div>
                  <div className="text-sm text-gray-500">{room.description}</div>
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Step 3: Select Items */}
        {step === 3 && (
          <div className="space-y-4">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Select Items (Optional)</h2>
            <p className="text-sm text-gray-500 mb-4">Click to select items. You can select multiple items.</p>
            
            {Object.entries(ITEMS_BY_CATEGORY).map(([category, items]) => (
              <div key={category} className="mb-4">
                <h3 className="font-medium text-gray-700 mb-2">{category}</h3>
                <div className="grid grid-cols-2 gap-2">
                  {items.map((item) => (
                    <button
                      key={item.id}
                      onClick={() => handleItemToggle(item.id)}
                      className={`p-3 text-sm rounded-lg border transition-all ${
                        formData.items.includes(item.id)
                          ? 'bg-orange-100 border-orange-400 text-orange-800'
                          : 'bg-white border-gray-200 hover:border-gray-300 text-gray-700'
                      }`}
                    >
                      {item.name}
                    </button>
                  ))}
                </div>
              </div>
            ))}

            <button
              onClick={() => setStep(4)}
              className="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium"
            >
              Continue
            </button>
          </div>
        )}

        {/* Step 4: Additional Info & Submit */}
        {step === 4 && (
          <div className="space-y-4">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Additional Information (Optional)</h2>
            
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Other Item (if not listed above)
              </label>
              <input
                type="text"
                value={formData.other_item}
                onChange={(e) => setFormData(prev => ({ ...prev, other_item: e.target.value }))}
                placeholder="Enter item name..."
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Notes
              </label>
              <textarea
                value={formData.notes}
                onChange={(e) => setFormData(prev => ({ ...prev, notes: e.target.value }))}
                placeholder="Add any additional notes..."
                rows={4}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent"
              />
            </div>

            {/* Summary */}
            <div className="bg-gray-100 rounded-lg p-4">
              <h3 className="font-medium text-gray-900 mb-2">Request Summary</h3>
              <div className="text-sm text-gray-600 space-y-1">
                <p><span className="font-medium">Selected Items:</span> {formData.items.length > 0 ? formData.items.map(id => CURATED_ITEMS.find(i => i.id === id)?.name).join(', ') : 'None'}</p>
                {formData.other_item && <p><span className="font-medium">Other:</span> {formData.other_item}</p>}
              </div>
            </div>

            <div className="flex gap-3">
              <button
                onClick={() => setStep(3)}
                className="flex-1 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-medium"
              >
                Back
              </button>
              <button
                onClick={handleSubmit}
                disabled={submitting}
                className="flex-1 py-3 bg-orange-600 text-white rounded-lg hover:bg-orange-700 font-medium disabled:opacity-50"
              >
                {submitting ? 'Submitting...' : 'Submit Request'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}