import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Station, InventorySubmissionItem } from '../types';
import { ApiClient } from '../utils/api';

interface InventoryItem {
  id: string;
  name: string;
  unit: string;
  max_quantity: number;
}

interface InventoryCategory {
  id: string;
  name: string;
  items: InventoryItem[];
}

// Station supply list based on STATION SUPPLY LIST 122025.xlsx
const DEFAULT_CATEGORIES: InventoryCategory[] = [
  {
    id: 'garbage_paper',
    name: 'Garbage & Paper Goods',
    items: [
      { id: 'gallon_55', name: '55 Gallon Trash Bags (case)', unit: 'case', max_quantity: 10 },
      { id: 'gallon_33', name: '33 Gallon Trash Bags (case)', unit: 'case', max_quantity: 10 },
      { id: 'paper_towels', name: 'Paper Towels (rolls)', unit: 'rolls', max_quantity: 50 },
      { id: 'toilet_paper', name: 'Toilet Paper (rolls)', unit: 'rolls', max_quantity: 100 },
      { id: 'facial_tissue', name: 'Facial Tissues (boxes)', unit: 'boxes', max_quantity: 20 },
      { id: 'plastic_gloves', name: 'Plastic Gloves (boxes)', unit: 'boxes', max_quantity: 15 },
      { id: 'plastic_bags', name: 'Plastic Bags (case)', unit: 'case', max_quantity: 10 },
    ],
  },
  {
    id: 'floors',
    name: 'Floors',
    items: [
      { id: 'mop_heads', name: 'Mop Heads', unit: 'each', max_quantity: 10 },
      { id: 'floor_brooms', name: 'Floor Brooms', unit: 'each', max_quantity: 5 },
      { id: 'dust_mops', name: 'Dust Mops', unit: 'each', max_quantity: 5 },
      { id: 'floor_stand_mop', name: 'Floor Stand Mop Bucket', unit: 'each', max_quantity: 3 },
      { id: 'wet_floor_signs', name: 'Wet Floor Signs', unit: 'each', max_quantity: 4 },
      { id: 'floor_cleaner', name: 'Floor Cleaner (gallon)', unit: 'gallon', max_quantity: 10 },
      { id: 'vacuum_bags', name: 'Vacuum Bags', unit: 'boxes', max_quantity: 10 },
    ],
  },
  {
    id: 'laundry',
    name: 'Laundry',
    items: [
      { id: 'laundry_detergent', name: 'Laundry Detergent (gallon)', unit: 'gallon', max_quantity: 8 },
      { id: 'bleach', name: 'Bleach (gallon)', unit: 'gallon', max_quantity: 6 },
      { id: 'fabric_softener', name: 'Fabric Softener (gallon)', unit: 'gallon', max_quantity: 6 },
      { id: 'laundry_bags', name: 'Laundry Bags', unit: 'each', max_quantity: 20 },
      { id: 'lint_rollers', name: 'Lint Rollers', unit: 'rolls', max_quantity: 15 },
    ],
  },
  {
    id: 'bathroom_cleaners',
    name: 'Bathroom & Cleaners',
    items: [
      { id: 'toilet_bowl_cleaner', name: 'Toilet Bowl Cleaner (gallon)', unit: 'gallon', max_quantity: 6 },
      { id: 'bathroom_cleaner', name: 'Bathroom Cleaner (gallon)', unit: 'gallon', max_quantity: 8 },
      { id: 'glass_cleaner', name: 'Glass Cleaner (gallon)', unit: 'gallon', max_quantity: 6 },
      { id: 'disinfectant_wipes', name: 'Disinfectant Wipes (canisters)', unit: 'canisters', max_quantity: 20 },
      { id: 'hand_soap', name: 'Hand Soap (gallons)', unit: 'gallon', max_quantity: 10 },
      { id: 'air_freshener', name: 'Air Freshener (cans)', unit: 'cans', max_quantity: 15 },
      { id: 'urinal_mats', name: 'Urinal Mats', unit: 'each', max_quantity: 10 },
    ],
  },
  {
    id: 'kitchen',
    name: 'Kitchen',
    items: [
      { id: 'dish_soap', name: 'Dish Soap (gallon)', unit: 'gallon', max_quantity: 6 },
      { id: 'dishwasher_detergent', name: 'Dishwasher Detergent (case)', unit: 'case', max_quantity: 8 },
      { id: 'sponges', name: 'Sponges', unit: 'each', max_quantity: 15 },
      { id: 'scrub_brushes', name: 'Scrub Brushes', unit: 'each', max_quantity: 8 },
      { id: 'aluminum_foil', name: 'Aluminum Foil (rolls)', unit: 'rolls', max_quantity: 6 },
      { id: 'plastic_wrap', name: 'Plastic Wrap (rolls)', unit: 'rolls', max_quantity: 6 },
      { id: 'paper_plates', name: 'Paper Plates (packs)', unit: 'packs', max_quantity: 15 },
      { id: 'plastic_cups', name: 'Plastic Cups (packs)', unit: 'packs', max_quantity: 15 },
      { id: 'napkins', name: 'Napkins (packs)', unit: 'packs', max_quantity: 20 },
      { id: 'coffee_filters', name: 'Coffee Filters', unit: 'boxes', max_quantity: 10 },
    ],
  },
];

export default function StationInventoryForm() {
  const navigate = useNavigate();
  const [step, setStep] = useState(0);
  const [stations, setStations] = useState<Station[]>([]);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<{ id: number; pdfUrl?: string } | null>(null);
  
  const [selectedStation, setSelectedStation] = useState<number>(0);
  const [quantities, setQuantities] = useState<Record<string, number>>({});
  const [notes, setNotes] = useState('');

  useEffect(() => {
    const fetchStations = async () => {
      try {
        const data = await ApiClient.getStations();
        setStations(data);
        if (data.length === 1) {
          setSelectedStation(data[0].id);
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load stations');
      } finally {
        setLoading(false);
      }
    };
    fetchStations();
  }, []);

  const handleQuantityChange = (itemId: string, value: number, max: number) => {
    const numValue = Math.max(0, Math.min(max, value));
    setQuantities(prev => ({ ...prev, [itemId]: numValue }));
  };

  const handleSubmit = async () => {
    if (selectedStation === 0) {
      setError('Please select a station');
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      const items: InventorySubmissionItem[] = Object.entries(quantities)
        .filter(([_, qty]) => qty > 0)
        .map(([itemId, quantity]) => {
          // Find the category and item
          for (const category of DEFAULT_CATEGORIES) {
            const item = category.items.find(i => i.id === itemId);
            if (item) {
              return {
                category_id: category.id,
                item_id: itemId,
                quantity,
              };
            }
          }
          return { category_id: '', item_id: itemId, quantity };
        });

      if (items.length === 0) {
        // Allow submission with no items - just show success
        setSuccess({ id: 0 });
        return;
      }

      const result = await ApiClient.submitStationInventory(selectedStation, items, notes);
      setSuccess({ id: result.id });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to submit inventory');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDownloadPdf = async () => {
    if (success?.id) {
      try {
        const blob = await ApiClient.downloadInventoryPdf(success.id);
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `station-inventory-${selectedStation}.pdf`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
      } catch (err) {
        setError('Failed to download PDF');
      }
    }
  };

  const totalItems = Object.values(quantities).reduce((sum, qty) => sum + qty, 0);

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-64">
        <div className="text-lg">Loading stations...</div>
      </div>
    );
  }

  // Success screen
  if (success) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4">
        <div className="max-w-md w-full bg-white rounded-xl shadow-lg p-8 text-center">
          <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg className="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <h2 className="text-2xl font-bold text-gray-900 mb-2">Inventory Submitted!</h2>
          <p className="text-gray-600 mb-6">
            Your station inventory has been submitted successfully.
          </p>
          {success.id > 0 && (
            <button
              onClick={handleDownloadPdf}
              className="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium mb-3"
            >
              Download PDF
            </button>
          )}
          <button
            onClick={() => navigate('/forms-hub')}
            className="w-full py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-medium"
          >
            Back to Forms Hub
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <div className="bg-green-600 text-white py-4 px-4">
        <div className="max-w-4xl mx-auto">
          <button
            onClick={() => step > 0 ? setStep(step - 1) : navigate('/forms-hub')}
            className="flex items-center text-green-100 hover:text-white mb-2"
          >
            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            Back
          </button>
          <h1 className="text-xl font-bold">Station Inventory Form</h1>
          <p className="text-green-100 text-sm">Select quantities for each supply item</p>
        </div>
      </div>

      {/* Progress */}
      <div className="max-w-4xl mx-auto px-4 py-4">
        <div className="flex items-center justify-between mb-4">
          <span className="text-sm text-gray-600">
            Station {stations.find(s => s.id === selectedStation)?.station_number || 'Not selected'}
          </span>
          <span className="text-sm text-gray-600">
            {totalItems} items selected
          </span>
        </div>
        
        {/* Category Tabs */}
        <div className="flex flex-wrap gap-2 mb-4">
          <button
            onClick={() => setStep(0)}
            className={`px-4 py-2 rounded-lg text-sm font-medium ${
              step === 0 ? 'bg-green-600 text-white' : 'bg-white text-gray-700 border border-gray-200'
            }`}
          >
            Station
          </button>
          {DEFAULT_CATEGORIES.map((cat, idx) => (
            <button
              key={cat.id}
              onClick={() => setStep(idx + 1)}
              className={`px-4 py-2 rounded-lg text-sm font-medium ${
                step === idx + 1 ? 'bg-green-600 text-white' : 'bg-white text-gray-700 border border-gray-200'
              }`}
            >
              {cat.name}
            </button>
          ))}
          <button
            onClick={() => setStep(DEFAULT_CATEGORIES.length + 1)}
            className={`px-4 py-2 rounded-lg text-sm font-medium ${
              step === DEFAULT_CATEGORIES.length + 1 ? 'bg-green-600 text-white' : 'bg-white text-gray-700 border border-gray-200'
            }`}
          >
            Review
          </button>
        </div>
      </div>

      <div className="max-w-4xl mx-auto px-4 pb-8">
        {error && (
          <div className="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
            {error}
          </div>
        )}

        {/* Step 0: Select Station */}
        {step === 0 && (
          <div className="space-y-4">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Select Station</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {stations.map((station) => (
                <button
                  key={station.id}
                  onClick={() => setSelectedStation(station.id)}
                  className={`p-4 rounded-lg border text-left transition-all ${
                    selectedStation === station.id
                      ? 'bg-green-100 border-green-400'
                      : 'bg-white border-gray-200 hover:border-green-300'
                  }`}
                >
                  <div className="font-medium text-gray-900">Station {station.station_number}</div>
                  <div className="text-sm text-gray-500">{station.name}</div>
                </button>
              ))}
            </div>
            <button
              onClick={() => setStep(1)}
              disabled={selectedStation === 0}
              className="w-full py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium disabled:opacity-50"
            >
              Continue to Garbage & Paper Goods
            </button>
          </div>
        )}

        {/* Category Steps */}
        {DEFAULT_CATEGORIES.map((category, idx) => (
          step === idx + 1 && (
            <div key={category.id} className="space-y-4">
              <h2 className="text-lg font-semibold text-gray-900 mb-4">{category.name}</h2>
              <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                {category.items.map((item) => {
                  const currentQty = quantities[item.id] || 0;
                  return (
                    <div
                      key={item.id}
                      className="flex items-center justify-between p-4 border-b border-gray-100 last:border-b-0"
                    >
                      <div className="flex-1">
                        <div className="font-medium text-gray-900">{item.name}</div>
                        <div className="text-sm text-gray-500">Max: {item.max_quantity} {item.unit}</div>
                      </div>
                      <div className="flex items-center space-x-3">
                        <button
                          onClick={() => handleQuantityChange(item.id, currentQty - 1, item.max_quantity)}
                          className="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center hover:bg-gray-300"
                        >
                          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
                          </svg>
                        </button>
                        <span className="w-12 text-center font-medium">{currentQty}</span>
                        <button
                          onClick={() => handleQuantityChange(item.id, currentQty + 1, item.max_quantity)}
                          className="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center hover:bg-gray-300"
                        >
                          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                          </svg>
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
              <div className="flex gap-3">
                <button
                  onClick={() => setStep(idx)}
                  className="flex-1 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-medium"
                >
                  Previous
                </button>
                <button
                  onClick={() => setStep(idx + 2)}
                  className="flex-1 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium"
                >
                  {idx === DEFAULT_CATEGORIES.length - 1 ? 'Review' : 'Next'}
                </button>
              </div>
            </div>
          )
        ))}

        {/* Review Step */}
        {step === DEFAULT_CATEGORIES.length + 1 && (
          <div className="space-y-4">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Review & Submit</h2>
            
            {/* Summary by Category */}
            <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
              {DEFAULT_CATEGORIES.map((category) => {
                const categoryTotal = category.items.reduce((sum, item) => sum + (quantities[item.id] || 0), 0);
                if (categoryTotal === 0) return null;
                return (
                  <div key={category.id} className="p-4 border-b border-gray-100">
                    <h3 className="font-medium text-gray-900 mb-2">{category.name}</h3>
                    <div className="text-sm text-gray-600 space-y-1">
                      {category.items.filter(item => quantities[item.id] > 0).map(item => (
                        <div key={item.id} className="flex justify-between">
                          <span>{item.name}</span>
                          <span className="font-medium">{quantities[item.id]} {item.unit}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                );
              })}
              {totalItems === 0 && (
                <div className="p-8 text-center text-gray-500">
                  No items selected. You can still submit an empty inventory.
                </div>
              )}
            </div>

            {/* Notes */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Notes (Optional)
              </label>
              <textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Add any additional notes..."
                rows={3}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
              />
            </div>

            <div className="flex gap-3">
              <button
                onClick={() => setStep(DEFAULT_CATEGORIES.length)}
                className="flex-1 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-medium"
              >
                Previous
              </button>
              <button
                onClick={handleSubmit}
                disabled={submitting}
                className="flex-1 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium disabled:opacity-50"
              >
                {submitting ? 'Submitting...' : 'Submit Inventory'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}