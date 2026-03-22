import { Link, useSearchParams } from 'react-router-dom';
import { useEffect } from 'react';

export default function SuccessPage() {
  const [searchParams] = useSearchParams();
  const isQueued = searchParams.get('queued') === 'true';

  useEffect(() => {
    // Vibrate on success
    if ('vibrate' in navigator) {
      navigator.vibrate(200);
    }
  }, []);

  return (
    <div className="max-w-md mx-auto text-center">
      <div className="mb-8">
        <div className="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4 animate-checkmark">
          <svg
            className="w-12 h-12 text-green-600"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={3}
              d="M5 13l4 4L19 7"
            />
          </svg>
        </div>
        <h1 className="text-3xl font-bold text-gray-900 mb-2">
          {isQueued ? 'Inspection Queued!' : 'Inspection Submitted!'}
        </h1>
        <p className="text-gray-600">
          {isQueued 
            ? 'Your inspection will be submitted automatically when you\'re back online.'
            : 'Your daily checkout inspection has been successfully recorded.'
          }
        </p>
      </div>

      {isQueued && (
        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
          <p className="text-sm text-yellow-800">
            <strong>⚠️ Offline Mode</strong><br />
            Your inspection has been saved locally and will sync when you reconnect to the network.
          </p>
        </div>
      )}

      {!isQueued && (
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
          <p className="text-sm text-blue-800">
            <strong>What happens next?</strong><br />
            • Your inspection data has been saved to the system<br />
            • Any issues found will be tracked for follow-up<br />
            • Administrators will review the inspection results
          </p>
        </div>
      )}

      <div className="space-y-3">
        <Link
          to="/"
          className="block w-full px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 font-medium transition-colors touch-manipulation"
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