import { startHubIssueDiagnosticsCollector } from './diagnostics.js';
import { sendIssue } from './client.js';
import './widget.css';

startHubIssueDiagnosticsCollector();

function mount() {
    const root = document.querySelector('[data-hub-issue-widget]');
    if (!root || root.dataset.mounted) return;
    root.dataset.mounted = 'true';

    const trigger = root.querySelector('[data-hub-issue-trigger]');
    const dialog = root.querySelector('[data-hub-issue-dialog]');
    const form = root.querySelector('form');
    const description = root.querySelector('textarea');
    const fileInput = root.querySelector('input[type="file"]');
    const dropzone = root.querySelector('[data-hub-issue-files]');
    const selected = root.querySelector('[data-hub-issue-selected]');
    const error = root.querySelector('[data-hub-issue-error]');
    const confirmation = root.querySelector('[data-hub-issue-confirmation]');
    const reference = root.querySelector('[data-hub-issue-reference]');
    const send = root.querySelector('[data-hub-issue-send]');
    const attachments = [];
    let submissionId = crypto.randomUUID();

    function resetAfterSuccess() {
        form.reset();
        attachments.length = 0;
        selected.textContent = '';
        send.textContent = 'Send';
        form.hidden = false;
        confirmation.hidden = true;
    }

    function updateFiles(files) {
        for (const file of files) {
            if (attachments.length >= 2) break;
            if (['image/png', 'image/jpeg', 'application/pdf'].includes(file.type) && file.size <= 10 * 1024 * 1024) {
                attachments.push(file);
            }
        }
        selected.textContent = attachments.map((file) => file.name).join(', ');
    }

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        dialog.showModal();
        description.focus();
    });
    dialog.addEventListener('close', () => {
        trigger.focus();
        if (!confirmation.hidden) resetAfterSuccess();
    });
    root.querySelectorAll('[data-hub-issue-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
    fileInput.addEventListener('change', () => updateFiles(fileInput.files));
    dropzone.addEventListener('dragover', (event) => event.preventDefault());
    dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        updateFiles(event.dataTransfer.files);
    });
    dialog.addEventListener('paste', (event) => {
        const files = Array.from(event.clipboardData?.files || []);
        if (files.length) updateFiles(files);
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!description.reportValidity()) return;
        error.hidden = true;
        send.disabled = true;
        try {
            const result = await sendIssue({ description: description.value, attachments, clientSubmissionId: submissionId });
            form.hidden = true;
            confirmation.hidden = false;
            reference.textContent = result.report.reference;
            confirmation.focus();
            submissionId = crypto.randomUUID();
        } catch {
            error.hidden = false;
            send.textContent = 'Try Again';
        } finally {
            send.disabled = false;
        }
    });
    root.querySelector('[data-hub-issue-done]').addEventListener('click', () => {
        dialog.close();
    });
}

document.addEventListener('DOMContentLoaded', mount);
document.addEventListener('livewire:navigated', mount);
if (document.readyState !== 'loading') mount();
