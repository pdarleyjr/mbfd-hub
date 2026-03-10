import { useRef, useState } from 'react';
import SignatureCanvas from 'react-signature-canvas';
import { OfficerInfo, Compartment } from '../types';

interface SubmitStepProps {
  officerInfo: OfficerInfo;
  compartments: Compartment[];
  onSubmit: (signature: string | null) => void;
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
  const sigRef = useRef<SignatureCanvas | null>(null);
  const [sigError, setSigError] = useState(false);

  const totalItems = compartments.reduce((sum, comp) => sum + comp.items.length, 0);
  const issuesCount = compartments.reduce((sum, comp) =>
    sum + comp.items.filter(item => item.status !== 'Present').length, 0
  );

  const handleSubmit = () => {
    if (!sigRef.current || sigRef.current.isEmpty()) {
      setSigError(true);
      return;
    }
    setSigError(false);
    const sigData = sigRef.current.getTrimmedCanvas().toDataURL('image/png');
    onSubmit(sigData);
  };

  const clearSignature = () => {
    sigRef.current?.clear();
    setSigError(false);
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

      {issuesCount > 0 && (
        <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
          <p className="text-red-800 font-medium text-sm">
            ⚠️ This vehicle has {issuesCount} defect{issuesCount !== 1 ? 's' : ''}. 
            It will be automatically placed on <strong>HOLD (Out of Service)</strong> upon submission.
          </p>
        </div>
      )}

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

      {/* Officer Signature */}
      <div className="mb-8">
        <h3 className="text-lg font-medium text-gray-900 mb-2">Officer Signature</h3>
        <p className="text-sm text-gray-600 mb-3">Sign below to certify this inspection is accurate.</p>
        <div className={`border-2 rounded-lg bg-white ${sigError ? 'border-red-500' : 'border-gray-300'}`}>
          <SignatureCanvas
            ref={sigRef}
            penColor="black"
            canvasProps={{
              className: 'w-full',
              style: { width: '100%', height: '150px' }
            }}
          />
        </div>
        {sigError && (
          <p className="text-red-600 text-sm mt-1">Signature is required before submitting.</p>
        )}
        <button
          type="button"
          onClick={clearSignature}
          className="mt-2 text-sm text-blue-600 hover:text-blue-800 underline"
        >
          Clear Signature
        </button>
      </div>

      <div className="flex justify-between">
        <button
          onClick={onBack}
          disabled={submitting}
          className="px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-50"
        >
          Back to Compartments
        </button>

        <button
          onClick={handleSubmit}
          disabled={submitting}
          className="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 font-medium"
        >
          {submitting ? 'Submitting...' : 'Submit Inspection'}
        </button>
      </div>
    </div>
  );
}
