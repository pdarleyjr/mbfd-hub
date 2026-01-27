console.log('[PushWidget] script loaded');

function pushNotificationWidget() {
    return {
        VAPID_PUBLIC_KEY: '',
        elements: {},

        initWidget() {
            const widgetContainer = this.$el;
            this.VAPID_PUBLIC_KEY = widgetContainer.dataset.vapidKey || '';

            // Cache DOM elements
            this.elements = {
                loading: widgetContainer.querySelector('#push-loading'),
                iosPrompt: widgetContainer.querySelector('#ios-prompt'),
                notSupported: widgetContainer.querySelector('#not-supported'),
                permissionDenied: widgetContainer.querySelector('#permission-denied'),
                subscribeSection: widgetContainer.querySelector('#subscribe-section'),
                subscribedSection: widgetContainer.querySelector('#subscribed-section'),
                errorSection: widgetContainer.querySelector('#error-section'),
                errorMessage: widgetContainer.querySelector('#error-message'),
                subscribeBtn: widgetContainer.querySelector('#subscribe-btn'),
                unsubscribeBtn: widgetContainer.querySelector('#unsubscribe-btn'),
            };

            // Setup event listeners
            if (this.elements.subscribeBtn) {
                this.elements.subscribeBtn.addEventListener('click', () => this.subscribe());
            }
            if (this.elements.unsubscribeBtn) {
                this.elements.unsubscribeBtn.addEventListener('click', () => this.unsubscribe());
            }

            // Initialize - Register service worker first, then check status
            (async () => {
                console.log('[PushWidget] Initializing push notification widget...');
                await this.registerServiceWorker();
                this.checkStatus();
            })();
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
            this.hideAll();

            // Check basic support
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                console.log('[PushWidget] Push not supported in this browser');
                this.show(this.elements.notSupported);
                return;
            }

            // iOS specific handling
            if (this.isIOS() && !this.isStandalone()) {
                console.log('[PushWidget] iOS device not in standalone mode');
                this.show(this.elements.iosPrompt);
                return;
            }

            // Check permission
            if (Notification.permission === 'denied') {
                console.log('[PushWidget] Notification permission denied');
                this.show(this.elements.permissionDenied);
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
                    this.show(this.elements.subscribedSection);
                } else {
                    console.log('[PushWidget] User not subscribed, show subscribe button');
                    this.show(this.elements.subscribeSection);
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
                this.hideAll();
                this.show(this.elements.errorSection);
                this.elements.errorMessage.textContent = 'Could not register service worker: ' + error.message;
            }
        },

        async subscribe() {
            try {
                console.log('[PushWidget] Starting subscription process...');
                this.elements.subscribeBtn.disabled = true;
                this.elements.subscribeBtn.textContent = 'Enabling...';

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
                this.hideAll();
                this.show(this.elements.subscribedSection);
            } catch (error) {
                console.error('[PushWidget] Subscription error:', error);
                this.hideAll();
                this.show(this.elements.errorSection);
                this.elements.errorMessage.textContent = 'Failed to enable notifications: ' + error.message;
            } finally {
                if (this.elements.subscribeBtn) {
                    this.elements.subscribeBtn.disabled = false;
                    this.elements.subscribeBtn.textContent = 'Enable Push Notifications';
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
                this.hideAll();
                this.show(this.elements.subscribeSection);
            } catch (error) {
                console.error('[PushWidget] Unsubscribe error:', error);
            }
        }
    };
}

// Export to global scope for Alpine.js
window.pushNotificationWidget = pushNotificationWidget;
