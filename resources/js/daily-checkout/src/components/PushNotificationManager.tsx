import React, { useState, useEffect } from 'react';

interface PushState {
  isSupported: boolean;
  isIOS: boolean;
  isStandalone: boolean;
  permission: NotificationPermission | 'unsupported';
  isSubscribed: boolean;
  loading: boolean;
  error: string | null;
}

const PushNotificationManager: React.FC = () => {
  const [state, setState] = useState<PushState>({
    isSupported: false,
    isIOS: false,
    isStandalone: false,
    permission: 'unsupported',
    isSubscribed: false,
    loading: true,
    error: null,
  });

  useEffect(() => {
    checkPushSupport();
  }, []);

  const checkPushSupport = async () => {
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const isStandalone = 
      (window.navigator as any).standalone === true ||
      window.matchMedia('(display-mode: standalone)').matches;
    
    const isSupported = 'serviceWorker' in navigator && 'PushManager' in window;
    const permission = isSupported ? Notification.permission : 'unsupported';

    setState(prev => ({
      ...prev,
      isSupported,
      isIOS,
      isStandalone,
      permission,
      loading: false,
    }));

    if (isSupported && permission === 'granted') {
      checkSubscription();
    }
  };

  const checkSubscription = async () => {
    try {
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.getSubscription();
      setState(prev => ({ ...prev, isSubscribed: !!subscription }));
    } catch (err) {
      console.error('Error checking subscription:', err);
    }
  };

  const subscribeToPush = async () => {
    setState(prev => ({ ...prev, loading: true, error: null }));
    
    try {
      // Get VAPID public key from server
      const vapidResponse = await fetch('/api/push/vapid-public-key');
      const { vapid_public_key } = await vapidResponse.json();
      
      if (!vapid_public_key) {
        throw new Error('VAPID key not configured');
      }

      const registration = await navigator.serviceWorker.ready;
      
      // Subscribe to push
      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapid_public_key),
      });

      // Send subscription to server
      const response = await fetch('/api/push-subscriptions', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify(subscription.toJSON()),
      });

      if (!response.ok) {
        throw new Error('Failed to save subscription');
      }

      setState(prev => ({
        ...prev,
        isSubscribed: true,
        permission: 'granted',
        loading: false,
      }));
    } catch (err: any) {
      setState(prev => ({
        ...prev,
        error: err.message || 'Failed to enable notifications',
        loading: false,
      }));
    }
  };

  const urlBase64ToUint8Array = (base64String: string): Uint8Array => {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  };

  // iOS not in standalone mode - show Add to Home Screen prompt
  if (state.isIOS && !state.isStandalone) {
    return (
      <div className="bg-amber-100 border-l-4 border-amber-500 p-4 mb-4 rounded">
        <div className="flex items-start">
          <div className="flex-shrink-0">
            <svg className="h-5 w-5 text-amber-500" viewBox="0 0 20 20" fill="currentColor">
              <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
            </svg>
          </div>
          <div className="ml-3">
            <p className="text-sm text-amber-700 font-medium">
              Enable Critical Alerts
            </p>
            <p className="mt-1 text-sm text-amber-600">
              To receive critical equipment alerts, tap the{' '}
              <svg className="inline h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                <path d="M15 8a1 1 0 01-1 1h-3v3a1 1 0 11-2 0V9H6a1 1 0 110-2h3V4a1 1 0 112 0v3h3a1 1 0 011 1z" />
              </svg>{' '}
              Share icon and select <strong>"Add to Home Screen"</strong>.
            </p>
          </div>
        </div>
      </div>
    );
  }

  // Not supported
  if (!state.isSupported) {
    return null;
  }

  // Already subscribed
  if (state.isSubscribed) {
    return (
      <div className="bg-green-100 border-l-4 border-green-500 p-4 mb-4 rounded">
        <div className="flex items-center">
          <svg className="h-5 w-5 text-green-500 mr-2" viewBox="0 0 20 20" fill="currentColor">
            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
          </svg>
          <span className="text-sm text-green-700">Push notifications enabled</span>
        </div>
      </div>
    );
  }

  // Show enable button
  if (state.permission === 'default' || (state.isStandalone && state.permission !== 'denied')) {
    return (
      <div className="bg-blue-100 border-l-4 border-blue-500 p-4 mb-4 rounded">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm font-medium text-blue-700">Enable Push Notifications</p>
            <p className="text-xs text-blue-600 mt-1">Get alerts for equipment issues and low stock</p>
          </div>
          <button
            onClick={subscribeToPush}
            disabled={state.loading}
            className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium disabled:opacity-50"
          >
            {state.loading ? 'Enabling...' : 'Enable'}
          </button>
        </div>
        {state.error && (
          <p className="text-xs text-red-600 mt-2">{state.error}</p>
        )}
      </div>
    );
  }

  // Permission denied
  if (state.permission === 'denied') {
    return (
      <div className="bg-gray-100 border-l-4 border-gray-400 p-4 mb-4 rounded">
        <p className="text-sm text-gray-600">
          Notifications blocked. Enable them in your browser settings to receive alerts.
        </p>
      </div>
    );
  }

  return null;
};

export default PushNotificationManager;
