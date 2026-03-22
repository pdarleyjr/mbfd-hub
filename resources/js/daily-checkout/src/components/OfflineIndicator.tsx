import { useEffect, useState } from 'react';
import { useOffline } from '../hooks/useOffline';
import { getSubmissionQueue } from '../utils/storage';

export default function OfflineIndicator() {
  const isOffline = useOffline();
  const [queueCount, setQueueCount] = useState(0);
  const [showToast, setShowToast] = useState(false);
  const [toastMessage, setToastMessage] = useState('');

  useEffect(() => {
    // Update queue count
    const updateQueue = () => {
      setQueueCount(getSubmissionQueue().length);
    };
    
    updateQueue();
    const interval = setInterval(updateQueue, 1000);
    
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    if (isOffline) {
      setToastMessage('You are offline. Changes will be saved locally.');
      setShowToast(true);
    } else if (!isOffline && queueCount > 0) {
      setToastMessage(`Back online! Syncing ${queueCount} pending submission${queueCount > 1 ? 's' : ''}...`);
      setShowToast(true);
      
      // Auto hide after 5 seconds
      setTimeout(() => setShowToast(false), 5000);
    }
  }, [isOffline, queueCount]);

  if (!showToast && !isOffline) return null;

  return (
    <>
      {/* Offline Banner */}
      {isOffline && (
        <div className="fixed top-0 left-0 right-0 z-50 bg-yellow-500 text-white px-4 py-2 text-center text-sm font-medium shadow-lg">
          <span className="inline-block mr-2">⚠️</span>
          Offline Mode - Changes will be saved locally
          {queueCount > 0 && (
            <span className="ml-2 inline-block bg-yellow-600 px-2 py-0.5 rounded-full text-xs">
              {queueCount} pending
            </span>
          )}
        </div>
      )}

      {/* Toast Notification */}
      {showToast && (
        <div 
          className="fixed bottom-4 left-4 right-4 md:left-auto md:right-4 md:max-w-sm bg-gray-900 text-white px-4 py-3 rounded-lg shadow-lg z-50 animate-slide-up"
          role="alert"
        >
          <div className="flex items-start">
            <span className="mr-2">{isOffline ? '⚠️' : '✓'}</span>
            <p className="flex-1">{toastMessage}</p>
            <button
              onClick={() => setShowToast(false)}
              className="ml-2 text-gray-400 hover:text-white"
              aria-label="Close notification"
            >
              ✕
            </button>
          </div>
        </div>
      )}
    </>
  );
}
