console.log('[PushWidget] script loaded');

function pushNotificationWidget() {
    return {
        VAPID_PUBLIC_KEY: '',
        elements: {},
        sections: {},
        controls: {},

        initWidget() {
            const widgetContainer = this.$el;
            this.VAPID_PUBLIC_KEY = widgetContainer.dataset.vapidKey || '';

            // Cache DOM elements - separate sections from controls
            this.sections = {
                loading: widgetContainer.querySelector('#push-loading'),
                iosPrompt: widgetContainer.querySelector('#ios-prompt'),
                notSupported: widgetContainer.querySelector('#not-supported'),
                permissionDenied: widgetContainer.querySelector('#permission-denied'),
                subscribeSection: widgetContainer.querySelector('#subscribe-section'),
                subscribedSection: widgetContainer.querySelector('#subscribed-section'),
                errorSection: widgetContainer.querySelector('#error-section'),
            };

            this.controls = {
                errorMessage: widgetContainer.querySelector('#error-message'),
                subscribeBtn: widgetContainer.querySelector('#subscribe-btn'),
                unsubscribeBtn: widgetContainer.querySelector('#unsubscribe-btn'),
                testNotificationBtn: widgetContainer.querySelector('#test-notification-btn'),
            };

            // Keep elements for backward compatibility
            this.elements = { ...this.sections, ...this.controls };

            // Setup event listeners
            if (this.controls.subscribeBtn) {
                this.controls.subscribeBtn.addEventListener('click', () => this.subscribe());
            }
            if (this.controls.unsubscribeBtn) {
                this.controls.unsubscribeBtn.addEventListener('click', () => this.unsubscribe());
            }
            if (this.controls.testNotificationBtn) {
                this.controls.testNotificationBtn.addEventListener('click', () => this.sendTestNotification());
            }

            // Initialize - Register service worker first, then check status
            (async () => {
                console.log('[PushWidget] Initializing push notification widget...');
                await this.registerServiceWorker();
                this.checkStatus();
            })();
        },

        hideAllSections() {
            Object.values(this.sections).forEach(el => {
                if (el && el.classList) el.classList.add('hidden');
            });
        },

        hideAll() {
            Object.values(this.elements).forEach(el => {
                if (el && el.classList) el.classList.add('hidden');
            });
        },

        show(element) {
            if (element) element.classList.remove('hidden');
        },

        isIOS() {
            return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        },

        isStandalone() {
            return window.navigator.standalone === true ||
                   window.matchMedia('(display-mode: standalone)').matches;
        },

        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        },

        // Service Worker Registration
        async registerServiceWorker() {
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
        },

        async checkStatus() {
            this.hideAllSections();

            // Check basic support
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                console.log('[PushWidget] Push not supported in this browser');
                this.show(this.sections.notSupported);
                return;
            }

            // iOS specific handling
            if (this.isIOS() && !this.isStandalone()) {
                console.log('[PushWidget] iOS device not in standalone mode');
                this.show(this.sections.iosPrompt);
                return;
            }

            // Check permission
            if (Notification.permission === 'denied') {
                console.log('[PushWidget] Notification permission denied');
                this.show(this.sections.permissionDenied);
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
                    this.show(this.sections.subscribedSection);
                } else {
                    console.log('[PushWidget] User not subscribed, show subscribe button');
                    this.show(this.sections.subscribeSection);
                }
            } catch (error) {
                console.log('[PushWidget] Service worker not ready or timeout, attempting registration...');
                // If service worker not registered, register it
                const newReg = await this.registerServiceWorker();
                if (newReg) {
                    // Try again after registration
                    console.log('[PushWidget] Service worker registered, retrying status check...');
                    setTimeout(() => this.checkStatus(), 500);
                    return;
                }
                console.error('[PushWidget] Could not register service worker:', error);
                this.hideAllSections();
                this.show(this.sections.errorSection);
                this.controls.errorMessage.textContent = 'Could not register service worker: ' + error.message;
            }
        },

        async subscribe() {
            try {
                console.log('[PushWidget] Starting subscription process...');
                this.controls.subscribeBtn.disabled = true;
                this.controls.subscribeBtn.textContent = 'Enabling...';

                // Request notification permission first (required for iOS and best practice)
                if (Notification.permission === 'default') {
                    console.log('[PushWidget] Requesting notification permission...');
                    const permission = await Notification.requestPermission();
                    console.log('[PushWidget] Permission result:', permission);
                    if (permission !== 'granted') {
                        throw new Error('Notification permission not granted: ' + permission);
                    }
                }

                if (Notification.permission !== 'granted') {
                    throw new Error('Notification permission not granted');
                }

                const registration = await navigator.serviceWorker.ready;

                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(this.VAPID_PUBLIC_KEY)
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
                this.hideAllSections();
                this.show(this.sections.subscribedSection);
            } catch (error) {
                console.error('[PushWidget] Subscription error:', error);
                this.hideAllSections();
                this.show(this.sections.errorSection);
                this.controls.errorMessage.textContent = 'Failed to enable notifications: ' + error.message;
            } finally {
                if (this.controls.subscribeBtn) {
                    this.controls.subscribeBtn.disabled = false;
                    this.controls.subscribeBtn.textContent = 'Enable Push Notifications';
                }
            }
        },

        async unsubscribe() {
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
                this.hideAllSections();
                this.show(this.sections.subscribeSection);
            } catch (error) {
                console.error('[PushWidget] Unsubscribe error:', error);
            }
        },

        async sendTestNotification() {
            try {
                console.log('[PushWidget] Sending test notification...');
                if (this.controls.testNotificationBtn) {
                    this.controls.testNotificationBtn.disabled = true;
                    this.controls.testNotificationBtn.textContent = 'Sending...';
                }

                const response = await fetch('/api/push/test', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    credentials: 'same-origin'
                });

                const data = await response.json();
                
                if (data.success) {
                    alert('Test notification sent! Check your device for the notification.');
                    console.log('[PushWidget] Test notification sent successfully');
                } else {
                    alert('Error: ' + (data.error || data.message || 'Failed to send test notification'));
                    console.error('[PushWidget] Test notification failed:', data);
                }
            } catch (error) {
                console.error('[PushWidget] Failed to send test notification:', error);
                alert('Failed to send test notification. Please try again.');
            } finally {
                if (this.controls.testNotificationBtn) {
                    this.controls.testNotificationBtn.disabled = false;
                    this.controls.testNotificationBtn.innerHTML = `
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        Send Test Notification
                    `;
                }
            }
        }
    };
}

// Export to global scope for Alpine.js
window.pushNotificationWidget = pushNotificationWidget;

// Also export sendTestNotification to global scope for onclick handler
window.sendTestNotification = function() {
    const widget = document.querySelector('#push-notification-manager');
    if (widget && widget._x_dataStack) {
        const widgetData = widget._x_dataStack[0];
        if (widgetData && widgetData.sendTestNotification) {
            widgetData.sendTestNotification();
        }
    }
};
