import { useState } from 'react';
import { OfficerInfo, Rank, Shift } from '../types';

interface OfficerStepProps {
  initialData: OfficerInfo;
  onSubmit: (data: OfficerInfo) => void;
}

const RANKS: Rank[] = ['Chief', 'Deputy Chief', 'Captain', 'Lieutenant', 'Sergeant', 'Corporal', 'Firefighter'];
const SHIFTS: Shift[] = ['A', 'B', 'C'];

export default function OfficerStep({ initialData, onSubmit }: OfficerStepProps) {
  const [formData, setFormData] = useState<OfficerInfo>(initialData);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(formData);
  };

  const handleChange = (field: keyof OfficerInfo, value: string) => {
    setFormData(prev => ({ ...prev, [field]: value }));
  };

  return (
    <div className="max-w-md mx-auto">
      <h2 className="text-xl font-semibold text-gray-900 mb-6 text-center">Officer Information</h2>

      <form onSubmit={handleSubmit} className="space-y-6">
        <div>
          <label htmlFor="name" className="block text-sm font-medium text-gray-700 mb-2">
            Full Name
          </label>
          <input
            type="text"
            id="name"
            value={formData.name}
            onChange={(e) => handleChange('name', e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            required
          />
        </div>

        <div>
          <label htmlFor="rank" className="block text-sm font-medium text-gray-700 mb-2">
            Rank
          </label>
          <select
            id="rank"
            value={formData.rank}
            onChange={(e) => handleChange('rank', e.target.value as Rank)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          >
            {RANKS.map(rank => (
              <option key={rank} value={rank}>{rank}</option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="shift" className="block text-sm font-medium text-gray-700 mb-2">
            Shift
          </label>
          <select
            id="shift"
            value={formData.shift}
            onChange={(e) => handleChange('shift', e.target.value as Shift)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          >
            {SHIFTS.map(shift => (
              <option key={shift} value={shift}>Shift {shift}</option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="unitNumber" className="block text-sm font-medium text-gray-700 mb-2">
            Unit Number
          </label>
          <input
            type="text"
            id="unitNumber"
            value={formData.unitNumber}
            onChange={(e) => handleChange('unitNumber', e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            placeholder="e.g., E1, L1, R1"
            required
          />
          <p className="text-sm text-gray-500 mt-1">
            Enter the unit number as it appears on the apparatus
          </p>
        </div>

        <div className="pt-4">
          <button
            type="submit"
            className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 font-medium"
          >
            Continue to Inspection
          </button>
        </div>
      </form>
    </div>
  );
}