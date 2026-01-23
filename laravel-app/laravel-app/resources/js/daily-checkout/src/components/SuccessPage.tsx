import { Link } from 'react-router-dom';

export default function SuccessPage() {
  return (
    <div className="max-w-md mx-auto text-center">
      <div className="mb-8">
        <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg
            className="w-8 h-8 text-green-600"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M5 13l4 4L19 7"
            />
          </svg>
        </div>
        <h1 className="text-2xl font-bold text-gray-900 mb-2">Inspection Submitted!</h1>
        <p className="text-gray-600">
          Your daily checkout inspection has been successfully recorded.
        </p>
      </div>

      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <p className="text-sm text-blue-800">
          <strong>What happens next?</strong><br />
          • Your inspection data has been saved to the system<br />
          • Any issues found will be tracked for follow-up<br />
          • Administrators will review the inspection results
        </p>
      </div>

      <div className="space-y-3">
        <Link
          to="/"
          className="block w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 font-medium"
        >
          Start Another Inspection
        </Link>

        <p className="text-sm text-gray-500">
          Thank you for keeping our equipment ready and safe!
        </p>
      </div>
    </div>
  );
}