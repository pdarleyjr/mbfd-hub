const MAX_EVENTS = 25;
const MAX_BYTES = 65_536;

export function normalizeSameOriginPath(value, origin = window.location.origin) {
    try {
        const url = new URL(value, origin);
        return url.origin === origin ? url.pathname : null;
    } catch {
        return null;
    }
}

function redact(value, max = 512) {
    return String(value).slice(0, max * 2)
        .replace(/\b(?:authorization\s*[:=]\s*)?bearer\s+\S+/gi, '[redacted]')
        .replace(/\bauthorization\s*[:=]\s*(?:[a-z][a-z0-9_-]*\s+)?[^\s,;]+/gi, '[redacted]')
        .replace(/\b(?:set-)?cookie\s*[:=]\s*[^\r\n]+/gi, '[redacted]')
        .replace(/\b(?:password|passwd|pwd|api[_-]?key|session[_-]?id|cookie|csrf(?:[_-]?token)?|authorization|token)\s*[:=]\s*[^\s,;]+/gi, '[redacted]')
        .replace(/\beyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/g, '[redacted]')
        .replace(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi, '[redacted]')
        .replace(/(?<![A-Za-z0-9_])((?:https?:\/\/[^\s?#]+|\/[^\s?#]+))(?:\?[^\s#]*)?(?:#[^\s]*)?/gi, '$1')
        .slice(0, max);
}

function serializedByteLength(value) {
    return new TextEncoder().encode(JSON.stringify(value)).byteLength;
}

export function sanitizeDiagnosticValue(value, origin = globalThis.location?.origin || 'http://localhost') {
    if (!value || typeof value !== 'object') return {};
    const safe = {};
    if (['error', 'rejection', 'request'].includes(value.type)) safe.type = value.type;
    for (const key of ['timestamp', 'name', 'message', 'stack']) {
        if (typeof value[key] === 'string') safe[key] = redact(value[key], key === 'stack' ? 2048 : 512);
    }
    for (const key of ['path', 'source']) {
        if (typeof value[key] === 'string') safe[key] = normalizeSameOriginPath(value[key], origin);
    }
    if (safe.type === 'request') {
        if (/^(GET|POST|PUT|PATCH|DELETE|HEAD)$/.test(value.method)) safe.method = value.method;
        if (Number.isInteger(value.status) && (value.status === 0 || value.status >= 400 && value.status <= 599)) safe.status = value.status;
        if (Number.isFinite(value.duration)) safe.duration = Math.max(0, Math.min(120000, Math.round(value.duration)));
    } else {
        for (const key of ['line', 'column']) {
            if (Number.isInteger(value[key])) safe[key] = Math.max(0, Math.min(1000000, value[key]));
        }
    }
    return safe;
}

export class DiagnosticRingBuffer {
    constructor({ maxEvents = MAX_EVENTS, maxBytes = MAX_BYTES } = {}) {
        this.maxEvents = maxEvents;
        this.maxBytes = maxBytes;
        this.events = [];
    }

    push(event) {
        try {
            const safe = sanitizeDiagnosticValue(event);
            if (!safe.type) return;
            this.events.push(safe);
            while (this.events.length > this.maxEvents || serializedByteLength(this.snapshot()) > this.maxBytes) {
                this.events.shift();
            }
        } catch {
            // Diagnostics must not break the host application.
        }
    }

    snapshot() {
        return { events: [...this.events] };
    }
}

let collector;

export function installHubIssueDiagnosticsCollector(target = window) {
    const buffer = new DiagnosticRingBuffer();

    try {
        target.addEventListener('error', (event) => {
            buffer.push({
                type: 'error', timestamp: new Date().toISOString(), name: event.error?.name || 'Error',
                message: event.message || event.error?.message || 'Application error',
                source: event.filename, line: event.lineno, column: event.colno, stack: event.error?.stack,
            });
        });
        target.addEventListener('unhandledrejection', (event) => {
            buffer.push({
                type: 'rejection', timestamp: new Date().toISOString(),
                name: event.reason?.name || 'Unhandled rejection',
                message: event.reason?.message || String(event.reason || 'Promise rejected'),
                stack: event.reason?.stack,
            });
        });

        const originalFetch = target.fetch;
        target.fetch = function (input, options) {
            const RequestConstructor = target.Request || globalThis.Request;
            const request = RequestConstructor && input instanceof RequestConstructor ? input : null;
            const path = normalizeSameOriginPath(request ? request.url : input, target.location?.origin);
            const method = String(options?.method || (request ? request.method : 'GET')).toUpperCase();
            const started = target.performance?.now?.() ?? Date.now();
            return originalFetch.apply(this, arguments).then((response) => {
                if (path && !response.ok) buffer.push({
                    type: 'request', timestamp: new Date().toISOString(), method, path,
                    status: response.status, duration: (target.performance?.now?.() ?? Date.now()) - started,
                });
                return response;
            }, (error) => {
                if (path) buffer.push({
                    type: 'request', timestamp: new Date().toISOString(), method, path,
                    status: 0, duration: (target.performance?.now?.() ?? Date.now()) - started,
                });
                throw error;
            });
        };

        const Xhr = target.XMLHttpRequest;
        const originalOpen = Xhr.prototype.open;
        const originalSend = Xhr.prototype.send;
        Xhr.prototype.open = function (method, url) {
            this.__hubIssueRequest = { method: String(method).toUpperCase(), path: normalizeSameOriginPath(url, target.location?.origin) };
            return originalOpen.apply(this, arguments);
        };
        Xhr.prototype.send = function () {
            const request = this.__hubIssueRequest;
            if (request?.path) {
                const started = target.performance?.now?.() ?? Date.now();
                this.addEventListener('loadend', () => {
                    if (this.status >= 400 || this.status === 0) buffer.push({
                        type: 'request', timestamp: new Date().toISOString(), method: request.method,
                        path: request.path, status: this.status, duration: (target.performance?.now?.() ?? Date.now()) - started,
                    });
                }, { once: true });
            }
            return originalSend.apply(this, arguments);
        };
    } catch {
        // The reporter remains usable even if instrumentation is unavailable.
    }

    return buffer;
}

export function startHubIssueDiagnosticsCollector() {
    if (collector) return collector;
    collector = installHubIssueDiagnosticsCollector();

    return collector;
}
