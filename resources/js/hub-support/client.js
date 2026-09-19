import { startHubIssueDiagnosticsCollector } from './diagnostics.js';

export function issueContext() {
    return {
        page_path: location.pathname,
        route_name: document.querySelector('[data-hub-issue-route-name]')?.dataset.hubIssueRouteName || null,
        referrer_path: document.referrer ? new URL(document.referrer).origin === location.origin ? new URL(document.referrer).pathname : null : null,
        client_metadata: {
            userAgent: navigator.userAgent,
            viewport: { width: innerWidth, height: innerHeight },
            screen: { width: screen.width, height: screen.height },
            devicePixelRatio: devicePixelRatio,
            language: navigator.language,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            online: navigator.onLine,
            standalone: matchMedia('(display-mode: standalone)').matches,
        },
        diagnostics: startHubIssueDiagnosticsCollector().snapshot(),
    };
}

export async function sendIssue({ description, attachments, clientSubmissionId }) {
    const payload = new FormData();
    payload.set('client_submission_id', clientSubmissionId);
    payload.set('description', description);
    const context = issueContext();
    payload.set('page_path', context.page_path);
    if (context.route_name) payload.set('route_name', context.route_name);
    if (context.referrer_path) payload.set('referrer_path', context.referrer_path);
    payload.set('client_metadata', JSON.stringify(context.client_metadata));
    payload.set('diagnostics', JSON.stringify(context.diagnostics));
    for (const file of attachments) payload.append('attachments[]', file);

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const xsrf = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice(11);
    const response = await fetch('/support/issues', {
        method: 'POST', body: payload, credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            ...(!csrf && xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        },
    });
    if (!response.ok) throw new Error('Unable to send report');

    return response.json();
}
