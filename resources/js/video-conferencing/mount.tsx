import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ConferenceApp } from './ConferenceApp';
import type { ConferenceBootstrap } from './types';
import './video-conferencing.css';

export function mountConferenceApp(element: HTMLElement): () => void {
    const bootstrap = JSON.parse(element.dataset.bootstrap || '{}') as ConferenceBootstrap;
    const root = createRoot(element);
    root.render(<StrictMode><ConferenceApp bootstrap={bootstrap} /></StrictMode>);

    return () => root.unmount();
}
