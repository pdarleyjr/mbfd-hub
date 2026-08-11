let dispose: (() => void) | undefined;

async function mountConference(): Promise<void> {
    const root = document.getElementById('video-conferencing-root');
    if (!root || root.dataset.mounted === 'true') return;

    root.dataset.mounted = 'true';
    const module = await import('./mount');
    dispose = module.mountConferenceApp(root);
}

function unmountConference(): void {
    dispose?.();
    dispose = undefined;
    const root = document.getElementById('video-conferencing-root');
    if (root) delete root.dataset.mounted;
}

void mountConference();
document.addEventListener('livewire:navigated', () => void mountConference());
document.addEventListener('livewire:navigating', unmountConference);
