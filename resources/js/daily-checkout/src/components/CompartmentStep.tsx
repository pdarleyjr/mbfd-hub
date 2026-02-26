import { useState, useRef, TouchEvent } from 'react';
import { Compartment, ItemStatus } from '../types';

interface CompartmentStepProps {
  compartments: Compartment[];
  onSubmit: (compartments: Compartment[]) => void;
  onBack: () => void;
}

export default function CompartmentStep({ compartments, onSubmit, onBack }: CompartmentStepProps) {
  const [currentCompartmentIndex, setCurrentCompartmentIndex] = useState(0);
  const [compartmentData, setCompartmentData] = useState<Compartment[]>(compartments);
  const touchStartX = useRef<number>(0);
  const touchEndX = useRef<number>(0);

  const currentCompartment = compartmentData[currentCompartmentIndex];
  const isLastCompartment = currentCompartmentIndex === compartmentData.length - 1;

  const vibrate = (pattern: number | number[] = 10) => {
    if ('vibrate' in navigator) {
      navigator.vibrate(pattern);
    }
  };

  const handleItemStatusChange = (itemId: string, status: ItemStatus) => {
    vibrate();
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

  const handleMarkAllPresent = () => {
    vibrate([10, 50, 10]);
    setCompartmentData(prev =>
      prev.map((comp, compIndex) =>
        compIndex === currentCompartmentIndex
          ? {
              ...comp,
              items: comp.items.map(item => ({ ...item, status: 'Present' as ItemStatus }))
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

  const handlePhotoCapture = (itemId: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onloadend = () => {
      const base64 = reader.result as string;
      setCompartmentData(prev =>
        prev.map((comp, compIndex) =>
          compIndex === currentCompartmentIndex
            ? {
                ...comp,
                items: comp.items.map(item =>
                  item.id === itemId ? { ...item, photo: base64 } : item
                )
              }
            : comp
        )
      );
    };
    reader.readAsDataURL(file);
  };

  const handleTouchStart = (e: TouchEvent) => {
    touchStartX.current = e.touches[0].clientX;
  };

  const handleTouchEnd = (e: TouchEvent) => {
    touchEndX.current = e.changedTouches[0].clientX;
    const diff = touchStartX.current - touchEndX.current;
    const threshold = 50;

    if (Math.abs(diff) > threshold) {
      if (diff > 0 && !isLastCompartment) {
        // Swipe left - next
        vibrate();
        setCurrentCompartmentIndex(prev => prev + 1);
      } else if (diff < 0 && currentCompartmentIndex > 0) {
        // Swipe right - previous
        vibrate();
        setCurrentCompartmentIndex(prev => prev - 1);
      }
    }
  };

  const handleNext = () => {
    vibrate();
    if (isLastCompartment) {
      onSubmit(compartmentData);
    } else {
      setCurrentCompartmentIndex(prev => prev + 1);
    }
  };

  const handlePrevious = () => {
    vibrate();
    if (currentCompartmentIndex > 0) {
      setCurrentCompartmentIndex(prev => prev - 1);
    } else {
      onBack();
    }
  };

  const getStatusButtonClass = (status: ItemStatus, isActive: boolean) => {
    const base = 'flex-1 py-4 px-3 text-lg font-semibold rounded-lg border-2 transition-all touch-manipulation';
    if (!isActive) return `${base} bg-white border-gray-300 text-gray-600 hover:bg-gray-50`;
    switch (status) {
      case 'Present': return `${base} bg-green-500 border-green-600 text-white`;
      case 'Missing': return `${base} bg-red-500 border-red-600 text-white`;
      case 'Damaged': return `${base} bg-yellow-500 border-yellow-600 text-white`;
    }
  };

  return (
    <div 
      className="max-w-2xl mx-auto"
      onTouchStart={handleTouchStart}
      onTouchEnd={handleTouchEnd}
    >
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

      {/* Mark All Present Button */}
      <button
        onClick={handleMarkAllPresent}
        className="w-full mb-6 py-3 px-4 bg-green-100 text-green-800 font-semibold rounded-lg border-2 border-green-300 hover:bg-green-200 transition-colors touch-manipulation"
        style={{ minHeight: 44 }}
        aria-label="Mark all items in this compartment as present"
      >
        ✓ Mark All Present
      </button>

      <div className="space-y-6 mb-8">
        {currentCompartment.items.map((item) => (
          <div key={item.id} className="border border-gray-200 rounded-lg p-4">
            <h3 className="text-lg font-medium text-gray-900 mb-4">{item.name}</h3>
            
            {/* Large Status Buttons */}
            <div className="flex gap-2 mb-4" role="group" aria-label={`Status for ${item.name}`}>
              <button
                onClick={() => handleItemStatusChange(item.id, 'Present')}
                className={getStatusButtonClass('Present', item.status === 'Present')}
                aria-pressed={item.status === 'Present'}
              >
                ✓ Present
              </button>
              <button
                onClick={() => handleItemStatusChange(item.id, 'Missing')}
                className={getStatusButtonClass('Missing', item.status === 'Missing')}
                aria-pressed={item.status === 'Missing'}
              >
                ✗ Missing
              </button>
              <button
                onClick={() => handleItemStatusChange(item.id, 'Damaged')}
                className={getStatusButtonClass('Damaged', item.status === 'Damaged')}
                aria-pressed={item.status === 'Damaged'}
              >
                ⚠ Damaged
              </button>
            </div>

            {/* Photo attachment */}
            <div className="mb-3">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Photo (optional)
              </label>
              <input
                type="file"
                accept="image/*"
                capture="environment"
                onChange={(e) => handlePhotoCapture(item.id, e)}
                className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
              />
              {item.photo && (
                <img 
                  src={item.photo} 
                  alt={`Photo of ${item.name}`}
                  className="mt-2 max-h-32 rounded-lg"
                />
              )}
            </div>

            {/* Notes */}
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
          className="px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 touch-manipulation"
          style={{ minWidth: 44, minHeight: 44 }}
        >
          {currentCompartmentIndex === 0 ? 'Back to Officer Info' : 'Previous Compartment'}
        </button>

        <button
          onClick={handleNext}
          className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 touch-manipulation"
          style={{ minWidth: 44, minHeight: 44 }}
        >
          {isLastCompartment ? 'Review & Submit' : 'Next Compartment'}
        </button>
      </div>
    </div>
  );
}