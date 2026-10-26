import { createRoot } from 'react-dom/client';
import MemberWaitingRoom from '../../../resources/js/video-conferencing/MemberWaitingRoom';
import type { ConferenceBootstrap } from '../../../resources/js/video-conferencing/types';

declare global {
    interface Window {
        lineupJoinAttempts: number;
        lineupKeepWaiting: boolean;
    }
}

window.lineupJoinAttempts = 0;
window.lineupKeepWaiting = false;
const mediaReady = !window.location.search.includes('microphone=denied');
const bootstrap = {
    display_name: 'Firefighter Test Member',
    timezone: 'America/New_York',
    server_time: '2026-08-12T06:55:00-04:00',
    status_poll_ms: 5000,
    endpoints: { lineup_status: '/member-lineup/status' },
    realtime: { key: '', host: '', port: 443, scheme: 'https', channel: 'video-conferencing.lineup' },
} as ConferenceBootstrap;

createRoot(document.getElementById('root')!).render(<MemberWaitingRoom
    bootstrap={bootstrap}
    observing
    mediaReady={mediaReady}
    previewActive={mediaReady}
    releasedReason={null}
    busy={false}
    lastJoinedSessionId={null}
    refreshToken={0}
    onJoin={async () => { window.lineupJoinAttempts++; return window.lineupKeepWaiting; }}
    onIdleRelease={() => undefined}
    onResumeMedia={() => undefined}
/>);
