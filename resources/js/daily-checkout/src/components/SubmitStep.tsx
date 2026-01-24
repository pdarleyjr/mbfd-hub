import { OfficerInfo, Compartment } from '../types';

interface SubmitStepProps {
  officerInfo: OfficerInfo;
  compartments: Compartment[];
  onSubmit: () => void;
  onBack: () => void;
  submitting: boolean;
}

export default function SubmitStep({
  officerInfo,
  compartments,
  onSubmit,
  onBack,
  submitting
}: SubmitStepProps) {
  const totalItems = compartments.reduce((sum, comp) => sum + comp.items.length, 0);
  const issuesCount = compartments.reduce((sum, comp) =>
    sum + comp.items.filter(item => item.status !== 'Present').length, 0
  );

  const handleSubmit = () => {
    if ('vibrate' in navigator) {
      navigator.vibrate(50);
    }
    onSubmit();
  };

  const handleBack = () => {
    if ('vibrate' in navigator) {
      navigator.vibrate(30);
    }
    onBack();
  };

  return (
    <div className="max-w-2xl mx-auto">
      <h2 className="text-xl font-semibold text-gray-900 mb-6 text-center">
        Review & Submit Inspection
      </h2>

      <div className="bg-gray-50 rounded-lg p-6 mb-6">
        <h3 className="text-lg font-medium text-gray-900 mb-4">Inspection Summary</h3>

        <div className="grid grid-cols-2 gap-4 mb-4">
          <div>
            <p className="text-sm text-gray-600">Officer</p>
            <p className="font-medium">{officerInfo.name}</p>
            <p className="text-sm text-gray-600">{officerInfo.rank} • Shift {officerInfo.shift}</p>
          </div>
          <div>
            <p className="text-sm text-gray-600">Unit Number</p>
            <p className="font-medium">{officerInfo.unitNumber}</p>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <p className="text-sm text-gray-600">Total Items Checked</p>
            <p className="font-medium">{totalItems}</p>
          </div>
          <div>
            <p className="text-sm text-gray-600">Items with Issues</p>
            <p className={`font-medium ${issuesCount > 0 ? 'text-red-600' : 'text-green-600'}`}>
              {issuesCount}
            </p>
          </div>
        </div>
      </div>

      <div className="space-y-4 mb-8">
        <h3 className="text-lg font-medium text-gray-900">Compartments Summary</h3>

        {compartments.map((compartment, index) => {
          const issuesInCompartment = compartment.items.filter(item => item.status !== 'Present').length;

          return (
            <div key={compartment.id} className="border border-gray-200 rounded-lg p-4">
              <div className="flex justify-between items-center mb-2">
                <h4 className="font-medium text-gray-900">
                  Compartment {index + 1}: {compartment.name}
                </h4>
                <span className={`text-sm px-2 py-1 rounded ${
                  issuesInCompartment > 0
                    ? 'bg-red-100 text-red-800'
                    : 'bg-green-100 text-green-800'
                }`}>
                  {issuesInCompartment} issue{issuesInCompartment !== 1 ? 's' : ''}
                </span>
              </div>

              {issuesInCompartment > 0 && (
                <div className="space-y-1">
                  {compartment.items
                    .filter(item => item.status !== 'Present')
                    .map(item => (
                      <div key={item.id} className="text-sm text-gray-600">
                        • {item.name}: <span className="font-medium text-red-600">{item.status}</span>
                        {item.notes && <span> - {item.notes}</span>}
                      </div>
                    ))}
                </div>
              )}
            </div>
          );
        })}
      </div>

      <div className="flex flex-col sm:flex-row gap-3 sm:justify-between">
        <button
          onClick={handleBack}
          disabled={submitting}
          className="w-full sm:w-auto min-h-[44px] px-6 py-3 text-gray-600 bg-white border-2 border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-50 font-medium transition-colors touch-manipulation"
          aria-label="Go back to compartments"
        >
          Back to Compartments
        </button>

        <button
          onClick={handleSubmit}
          disabled={submitting}
          className="w-full sm:w-auto min-h-[44px] px-8 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed font-semibold text-lg transition-colors shadow-md hover:shadow-lg touch-manipulation"
          aria-label="Submit inspection"
        >
          {submitting ? (
            <span className="flex items-center justify-center">
              <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              Submitting...
            </span>
          ) : (
            '✓ Submit Inspection'
          )}
        </button>
      </div>
    </div>
  );
}