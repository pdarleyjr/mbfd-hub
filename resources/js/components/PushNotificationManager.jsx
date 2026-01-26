import React, { useState, useEffect } from 'react';

const PushNotificationManager = ({ vapidPublicKey }) => {
    const [permission, setPermission] = useState('default');
    const [isIOS, setIsIOS] = useState(false);
    const [isStandalone, setIsStandalone] = useState(false);
    const [isSubscribed, setIsSubscribed] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        // Detect iOS
        const iOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
        setIsIOS(iOS);

        // Detect standalone mode (installed PWA)
        const standalone = window.navigator.standalone === true || 
            window.matchMedia('(display-mode: standalone)').matches;
        setIsStandalone(standalone);

        // Check notification permission
        if ('Notification' in window) {
            setPermission(Notification.permission);
        }

        // Check if already subscribed
        checkSubscription();
    }, []);

    const checkSubscription = async () => {
        if ('serviceWorker' in navigator && 'PushManager' in window) {
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                setIsSubscribed(!!subscription);
            } catch (err) {
                console.error('Error checking subscription:', err);
            }
        }
    };

    const urlBase64ToUint8Array = (base64String) => {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    };

    const subscribe = async () => {
        setLoading(true);
        setError(null);

        try {
            // Register service worker if not already registered
            const registration = await navigator.serviceWorker.register('/sw.js');
            await navigator.serviceWorker.ready;

            // Request notification permission
            const permissionResult = await Notification.requestPermission();
            setPermission(permissionResult);

            if (permissionResult !== 'granted') {
                throw new Error('Notification permission denied');
            }

            // Subscribe to push
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
            });

            // Send subscription to server
            const response = await fetch('/api/push-subscriptions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify(subscription.toJSON()),
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Failed to save subscription');
            }

            setIsSubscribed(true);
        } catch (err) {
            console.error('Subscription error:', err);
            setError(err.message);
        } finally {
            setLoading(false);
        }
    };

    // iOS not in standalone mode - show Add to Home Screen prompt
    if (isIOS && !isStandalone) {
        return (
            <div className="bg-amber-100 border-l-4 border-amber-500 p-4 mb-4 rounded">
                <div className="flex items-start">
                    <div className="flex-shrink-0">
                        <svg className="h-5 w-5 text-amber-500" viewBox="0 0 20 20" fill="currentColor">
                            <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                        </svg>
                    </div>
                    <div className="ml-3">
                        <h3 className="text-sm font-medium text-amber-800">Enable Push Notifications</h3>
                        <p className="mt-1 text-sm text-amber-700">
                            To receive critical alerts on your iPhone/iPad:
                        </p>
                        <ol className="mt-2 text-sm text-amber-700 list-decimal list-inside">
                            <li>Tap the <strong>Share</strong> button (square with arrow)</li>
                            <li>Select <strong>"Add to Home Screen"</strong></li>
                            <li>Open the app from your Home Screen</li>
                            <li>Enable notifications when prompted</li>
                        </ol>
                    </div>
                </div>
            </div>
        );
    }

    // Already subscribed
    if (isSubscribed) {
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

    // Permission denied
    if (permission === 'denied') {
        return (
            <div className="bg-red-100 border-l-4 border-red-500 p-4 mb-4 rounded">
                <p className="text-sm text-red-700">
                    Notifications blocked. Please enable them in your browser settings to receive critical alerts.
                </p>
            </div>
        );
    }

    // Show subscribe button
    return (
        <div className="bg-blue-100 border-l-4 border-blue-500 p-4 mb-4 rounded">
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="text-sm font-medium text-blue-800">Push Notifications</h3>
                    <p className="text-sm text-blue-700">Enable to receive critical equipment and stock alerts.</p>
                </div>
                <button
                    onClick={subscribe}
                    disabled={loading}
                    className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium disabled:opacity-50"
                >
                    {loading ? 'Enabling...' : 'Enable'}
                </button>
            </div>
            {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
        </div>
    );
};

export default PushNotificationManager;
