import { useState } from 'react';
import { Compartment, ItemStatus } from '../types';

interface CompartmentStepProps {
  compartments: Compartment[];
  onSubmit: (compartments: Compartment[]) => void;
  onBack: () => void;
}

export default function CompartmentStep({ compartments, onSubmit, onBack }: CompartmentStepProps) {
  const [currentCompartmentIndex, setCurrentCompartmentIndex] = useState(0);
  const [compartmentData, setCompartmentData] = useState<Compartment[]>(compartments);

  const currentCompartment = compartmentData[currentCompartmentIndex];
  const isLastCompartment = currentCompartmentIndex === compartmentData.length - 1;

  const handleItemStatusChange = (itemId: string, status: ItemStatus) => {
    setCompartmentData(prev =>
      prev.map((comp, compIndex) =>
        compIndex === currentCompartmentIndex
          ? {
              ...comp,
              items: comp.items.map(item =>
                item.id === itemId ? { ...item, status } : item
              )
            }
          : comp
      )
    );
  };

  const handleItemNotesChange = (itemId: string, notes: string) => {
    setCompartmentData(prev =>
      prev.map((comp, compIndex) =>
        compIndex === currentCompartmentIndex
          ? {
              ...comp,
              items: comp.items.map(item =>
                item.id === itemId ? { ...item, notes } : item
              )
            }
          : comp
      )
    );
  };

  const handleNext = () => {
    if (isLastCompartment) {
      onSubmit(compartmentData);
    } else {
      setCurrentCompartmentIndex(prev => prev + 1);
    }
  };

  const handlePrevious = () => {
    if (currentCompartmentIndex > 0) {
      setCurrentCompartmentIndex(prev => prev - 1);
    } else {
      onBack();
    }
  };

  const getStatusColor = (status: ItemStatus) => {
    switch (status) {
      case 'Present': return 'text-green-600 bg-green-50 border-green-200';
      case 'Missing': return 'text-red-600 bg-red-50 border-red-200';
      case 'Damaged': return 'text-yellow-600 bg-yellow-50 border-yellow-200';
      default: return 'text-gray-600 bg-gray-50 border-gray-200';
    }
  };

  return (
    <div className="max-w-2xl mx-auto">
      <div className="mb-6">
        <h2 className="text-xl font-semibold text-gray-900 mb-2">
          Compartment Inspection
        </h2>
        <p className="text-gray-600">
          Compartment {currentCompartmentIndex + 1} of {compartmentData.length}: {currentCompartment.name}
        </p>
      </div>

      {/* Progress bar */}
      <div className="mb-6">
        <div className="w-full bg-gray-200 rounded-full h-2">
          <div
            className="bg-blue-600 h-2 rounded-full transition-all duration-300"
            style={{ width: `${((currentCompartmentIndex + 1) / compartmentData.length) * 100}%` }}
          />
        </div>
      </div>

      <div className="space-y-4 mb-8">
        {currentCompartment.items.map((item) => (
          <div key={item.id} className="border border-gray-200 rounded-lg p-4">
            <div className="flex items-start justify-between mb-3">
              <h3 className="text-lg font-medium text-gray-900">{item.name}</h3>
              <div className="flex space-x-2">
                {(['Present', 'Missing', 'Damaged'] as ItemStatus[]).map((status) => (
                  <button
                    key={status}
                    onClick={() => handleItemStatusChange(item.id, status)}
                    className={`px-3 py-1 text-sm rounded border transition-colors ${
                      item.status === status
                        ? getStatusColor(status)
                        : 'text-gray-600 bg-white border-gray-300 hover:bg-gray-50'
                    }`}
                  >
                    {status}
                  </button>
                ))}
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Notes (optional)
              </label>
              <textarea
                value={item.notes || ''}
                onChange={(e) => handleItemNotesChange(item.id, e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                rows={2}
                placeholder="Add any notes about this item..."
              />
            </div>
          </div>
        ))}
      </div>

      <div className="flex justify-between">
        <button
          onClick={handlePrevious}
          className="px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
        >
          {currentCompartmentIndex === 0 ? 'Back to Officer Info' : 'Previous Compartment'}
        </button>

        <button
          onClick={handleNext}
          className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
        >
          {isLastCompartment ? 'Review & Submit' : 'Next Compartment'}
        </button>
      </div>
    </div>
  );
}