{{--
    Employee <-> Logistics message composer behavior (docs/employee-logistics-messaging.md section 5).
    Render once, unconditionally, on any page or widget that can show a composer: a composer that
    appears later through a Livewire update relies on this function already being defined.
    Inline on purpose: no lazily loaded chunk can go missing across a release.
--}}
@once
<script>
    window.mbfdEmployeeMessageComposer = window.mbfdEmployeeMessageComposer || function (config) {
        const SEND_TIMEOUT_MS = 30000;
        const newRequestId = () => (window.crypto && typeof window.crypto.randomUUID === 'function')
            ? window.crypto.randomUUID()
            : 'msg-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
        const busyRegistry = () => {
            if (!(window.__mbfdBusy instanceof Set)) window.__mbfdBusy = new Set();
            return window.__mbfdBusy;
        };

        return {
            body: '',
            subject: '',
            sending: false,
            error: '',
            status: '',
            requestId: null,
            attemptedKey: null,
            maxLength: config.maxLength || 5000,
            busyReason: 'employee-message:' + newRequestId(),

            init() {
                this.$watch('body', (value) => { if (value !== '') this.status = ''; this.syncBusy(); });
                this.$watch('subject', () => this.syncBusy());
                this.$watch('sending', () => this.syncBusy());
                if (config.autofocus) this.$nextTick(() => this.$refs.body && this.$refs.body.focus());
            },

            destroy() {
                this.release();
            },

            get hasDraft() {
                return this.body.trim() !== '' || this.subject.trim() !== '';
            },

            get remaining() {
                return this.maxLength - this.body.length;
            },

            syncBusy() {
                const registry = busyRegistry();
                const busy = this.sending || this.hasDraft;
                if (busy === registry.has(this.busyReason)) return;
                if (busy) registry.add(this.busyReason); else registry.delete(this.busyReason);
                window.dispatchEvent(new CustomEvent('mbfd:busy-change', { detail: { reasons: Array.from(registry) } }));
            },

            release() {
                const registry = busyRegistry();
                if (registry.delete(this.busyReason)) {
                    window.dispatchEvent(new CustomEvent('mbfd:busy-change', { detail: { reasons: Array.from(registry) } }));
                }
            },

            async send() {
                if (this.sending) return;
                this.error = '';
                const body = this.body;
                if (body.trim() === '') {
                    this.error = 'Type a message before sending.';
                    this.$refs.body && this.$refs.body.focus();
                    return;
                }
                if (body.length > this.maxLength) {
                    this.error = 'Messages can be up to ' + this.maxLength.toLocaleString() + ' characters.';
                    return;
                }
                if (navigator.onLine === false) {
                    this.error = "You're offline. Your message is still here. Send it when you're connected.";
                    return;
                }

                // One request id per exact message: a retry of the same text can never post twice,
                // and edited text after a failure is a new, deliberate message.
                const key = this.subject + '\u0000' + body;
                if (this.requestId === null || this.attemptedKey !== key) {
                    this.requestId = newRequestId();
                    this.attemptedKey = key;
                }

                this.sending = true;
                this.status = 'Sending…';
                let timer = null;
                try {
                    const timeout = new Promise((resolve) => { timer = setTimeout(() => resolve({ ok: false, timedOut: true }), SEND_TIMEOUT_MS); });
                    // A reply names the conversation it was typed for, so the server can refuse a switch.
                    const call = config.withSubject
                        ? this.$wire.call(config.method, body, this.subject, this.requestId)
                        : (config.threadId === null || config.threadId === undefined
                            ? this.$wire.call(config.method, body, this.requestId)
                            : this.$wire.call(config.method, body, this.requestId, config.threadId));
                    const result = await Promise.race([call, timeout]);

                    if (result && result.ok === true) {
                        this.body = '';
                        this.subject = '';
                        this.requestId = null;
                        this.attemptedKey = null;
                        this.status = 'Message sent.';
                    } else if (result && result.timedOut) {
                        this.status = '';
                        this.error = 'This is taking longer than expected. Your message is still here. If it does not appear in the conversation shortly, check your connection and press Send again. Sending the same text again will not post it twice.';
                    } else {
                        this.status = '';
                        this.error = (result && typeof result.message === 'string' && result.message !== '')
                            ? result.message
                            : 'Your message was not sent. It is still here. Try again.';
                    }
                } catch (exception) {
                    this.status = '';
                    this.error = 'Your message was not sent. It is still here. Check your connection and try again.';
                } finally {
                    if (timer !== null) clearTimeout(timer);
                    this.sending = false;
                    this.syncBusy();
                }
            },

            onKeydown(event) {
                if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                    event.preventDefault();
                    this.send();
                }
            },
        };
    };

    // Switching conversations replaces the reply box. Ask before discarding typed text.
    window.mbfdEmployeeMessagesConfirmLeave = window.mbfdEmployeeMessagesConfirmLeave || function (event) {
        const registry = window.__mbfdBusy instanceof Set ? window.__mbfdBusy : null;
        const hasDraft = registry !== null && Array.from(registry).some((reason) => String(reason).startsWith('employee-message:'));
        if (hasDraft && !window.confirm('You have an unsent message. Leave it and open this conversation?')) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
        }
    };
</script>
@endonce
