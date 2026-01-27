document.addEventListener('DOMContentLoaded', function() {
    const widgetContainer = document.getElementById('push-notification-manager');
    if (!widgetContainer) return;

    const VAPID_PUBLIC_KEY = widgetContainer.dataset.vapidKey || '';

    const elements = {
        loading: document.getElementById('push-loading'),
        iosPrompt: document.getElementById('ios-prompt'),
        notSupported: document.getElementById('not-supported'),
        permissionDenied: document.getElementById('permission-denied'),
        subscribeSection: document.getElementById('subscribe-section'),
        subscribedSection: document.getElementById('subscribed-section'),
        errorSection: document.getElementById('error-section'),
        errorMessage: document.getElementById('error-message'),
        subscribeBtn: document.getElementById('subscribe-btn'),
        unsubscribeBtn: document.getElementById('unsubscribe-btn'),
    };

    function hideAll() {
        Object.values(elements).forEach(el => {
            if (el && el.classList) el.classList.add('hidden');
        });
    }

    function show(element) {
        if (element) element.classList.remove('hidden');
    }

    function isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    }

    function isStandalone() {
        return window.navigator.standalone === true ||
               window.matchMedia('(display-mode: standalone)').matches;
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    // Service Worker Registration
    async function registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            try {
                console.log('[PushWidget] Attempting to register service worker...');
                const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                console.log('[PushWidget] Service Worker registered successfully with scope:', registration.scope);
                return registration;
            } catch (error) {
                console.error('[PushWidget] Service Worker registration failed:', error);
                return null;
            }
        }
        console.log('[PushWidget] Service workers are not supported in this browser');
        return null;
    }

    async function checkStatus() {
        hideAll();

        // Check basic support
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.log('[PushWidget] Push not supported in this browser');
            show(elements.notSupported);
            return;
        }

        // iOS specific handling
        if (isIOS() && !isStandalone()) {
            console.log('[PushWidget] iOS device not in standalone mode');
            show(elements.iosPrompt);
            return;
        }

        // Check permission
        if (Notification.permission === 'denied') {
            console.log('[PushWidget] Notification permission denied');
            show(elements.permissionDenied);
            return;
        }

        // Check existing subscription with timeout fallback
        try {
            console.log('[PushWidget] Checking service worker ready status with 5s timeout...');
            // Wait for service worker with 5 second timeout
            const registration = await Promise.race([
                navigator.serviceWorker.ready,
                new Promise((_, reject) =>
                    setTimeout(() => reject(new Error('Service worker ready timeout')), 5000)
                )
            ]);

            console.log('[PushWidget] Service worker ready, checking subscription...');
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                console.log('[PushWidget] User is subscribed');
                show(elements.subscribedSection);
            } else {
                console.log('[PushWidget] User not subscribed, show subscribe button');
                show(elements.subscribeSection);
            }
        } catch (error) {
            console.log('[PushWidget] Service worker not ready or timeout, attempting registration...');
            // If service worker not registered, register it
            const newReg = await registerServiceWorker();
            if (newReg) {
                // Try again after registration
                console.log('[PushWidget] Service worker registered, retrying status check...');
                setTimeout(checkStatus, 500);
                return;
            }
            console.error('[PushWidget] Could not register service worker:', error);
            hideAll();
            show(elements.errorSection);
            elements.errorMessage.textContent = 'Could not register service worker: ' + error.message;
        }
    }

    async function subscribe() {
        try {
            console.log('[PushWidget] Starting subscription process...');
            elements.subscribeBtn.disabled = true;
            elements.subscribeBtn.textContent = 'Enabling...';

            const registration = await navigator.serviceWorker.ready;

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
            });

            console.log('[PushWidget] Push subscription created, sending to server...');

            // Send to server
            const response = await fetch('/api/push-subscriptions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                credentials: 'same-origin',
                body: JSON.stringify(subscription.toJSON())
            });

            if (!response.ok) {
                throw new Error('Failed to save subscription');
            }

            console.log('[PushWidget] Subscription saved successfully');
            hideAll();
            show(elements.subscribedSection);
        } catch (error) {
            console.error('[PushWidget] Subscription error:', error);
            hideAll();
            show(elements.errorSection);
            elements.errorMessage.textContent = 'Failed to enable notifications: ' + error.message;
        } finally {
            if (elements.subscribeBtn) {
                elements.subscribeBtn.disabled = false;
                elements.subscribeBtn.textContent = 'Enable Push Notifications';
            }
        }
    }

    async function unsubscribe() {
        try {
            console.log('[PushWidget] Unsubscribing...');
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                await subscription.unsubscribe();
                console.log('[PushWidget] Unsubscribed locally, notifying server...');

                // Notify server
                await fetch('/api/push-subscriptions', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ endpoint: subscription.endpoint })
                });
            }

            console.log('[PushWidget] Unsubscription complete');
            hideAll();
            show(elements.subscribeSection);
        } catch (error) {
            console.error('[PushWidget] Unsubscribe error:', error);
        }
    }

    // Event listeners
    if (elements.subscribeBtn) {
        elements.subscribeBtn.addEventListener('click', subscribe);
    }
    if (elements.unsubscribeBtn) {
        elements.unsubscribeBtn.addEventListener('click', unsubscribe);
    }

    // Initialize - Register service worker first, then check status
    (async function initialize() {
        console.log('[PushWidget] Initializing push notification widget...');
        await registerServiceWorker();
        checkStatus();
    })();
});