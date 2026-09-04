import { useState, useEffect } from 'react';
import { OfficerInfo, Rank, Shift, EmployeeOption } from '../types';
import { ApiClient } from '../utils/api';

interface OfficerStepProps {
  initialData: OfficerInfo;
  onSubmit: (data: OfficerInfo) => void;
}

const RANKS: Rank[] = ['Chief', 'Deputy Chief', 'Captain', 'Lieutenant', 'Sergeant', 'Corporal', 'Firefighter'];
const SHIFTS: Shift[] = ['A', 'B', 'C'];

export default function OfficerStep({ initialData, onSubmit }: OfficerStepProps) {
  const [formData, setFormData] = useState<OfficerInfo>(initialData);
  const [employees, setEmployees] = useState<EmployeeOption[]>([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [showDropdown, setShowDropdown] = useState(false);

  useEffect(() => {
    ApiClient.getEmployees()
      .then(setEmployees)
      .catch(() => {
        // Fallback: if employee list unavailable, keep text input behavior
      });
  }, []);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(formData);
  };

  const handleChange = (field: keyof OfficerInfo, value: string | number | undefined) => {
    setFormData(prev => ({ ...prev, [field]: value }));
  };

  const handleEmployeeSelect = (employee: EmployeeOption) => {
    setFormData(prev => ({
      ...prev,
      name: employee.name,
      rank: (employee.rank as Rank) || prev.rank,
      employeeId: employee.id,
    }));
    setSearchTerm(employee.name);
    setShowDropdown(false);
  };

  const filteredEmployees = employees.filter(emp =>
    emp.name.toLowerCase().includes(searchTerm.toLowerCase())
  );

  return (
    <div className="max-w-md mx-auto">
      <h2 className="text-xl font-semibold text-gray-900 mb-6 text-center">Officer Information</h2>

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="relative">
          <label htmlFor="name" className="block text-sm font-medium text-gray-700 mb-2">
            Full Name
          </label>
          <input
            type="text"
            id="name"
            value={showDropdown ? searchTerm : formData.name}
            onChange={(e) => {
              setSearchTerm(e.target.value);
              handleChange('name', e.target.value);
              handleChange('employeeId', undefined);
              setShowDropdown(true);
            }}
            onFocus={() => {
              setSearchTerm(formData.name);
              setShowDropdown(true);
            }}
            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            placeholder="Search by name..."
            required
            autoComplete="off"
          />
          {showDropdown && filteredEmployees.length > 0 && (
            <ul className="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-48 overflow-y-auto">
              {filteredEmployees.slice(0, 15).map(emp => (
                <li
                  key={emp.id}
                  onClick={() => handleEmployeeSelect(emp)}
                  className="px-3 py-2 cursor-pointer hover:bg-blue-50 text-sm border-b border-gray-100 last:border-0"
                >
                  <span className="font-medium">{emp.name}</span>
                  <span className="text-gray-500 ml-2">
                    {emp.rank || ''}
                  </span>
                </li>
              ))}
            </ul>
          )}
          {formData.employeeId && (
            <p className="text-xs text-green-600 mt-1">
              Selected: {formData.name}
            </p>
          )}
        </div>

        <div>
          <label htmlFor="rank" className="block text-sm font-medium text-gray-700 mb-2">
            Rank
          </label>
          <select
            id="rank"
            value={formData.rank}
            onChange={(e) => handleChange('rank', e.target.value)}
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
            onChange={(e) => handleChange('shift', e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          >
            {SHIFTS.map(shift => (
              <option key={shift} value={shift}>Shift {shift}</option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="unitNumber" className="block text-sm font-medium text-gray-700 mb-2">
            Apparatus Vehicle Number
          </label>
          <input
            type="text"
            id="unitNumber"
            value={formData.unitNumber}
            readOnly
            className="w-full min-h-[44px] px-3 py-2 border border-gray-300 bg-gray-50 rounded-md shadow-sm text-gray-700"
            required
          />
          <p className="text-sm text-gray-500 mt-1">
            This value is mapped from the selected apparatus and cannot be changed here.
          </p>
        </div>

        <div className="pt-4">
          <button
            type="submit"
            className="w-full min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 font-medium"
          >
            Continue to Inspection
          </button>
        </div>
      </form>
    </div>
  );
}
