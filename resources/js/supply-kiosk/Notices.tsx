// On the ID screen: withdrawals (and undos) the Hub never recorded. They stay until someone says Captain 5 was told.
import { HUB_TIMEZONE } from '../shared/timezone.ts';
import type { QueuedWithdrawal } from './queue.ts';
import type { PendingUndo } from './undoLater.ts';

export interface Notice { key: string; kind: 'withdrawal' | 'undo'; uuid: string; text: string }

const timeFormat = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: HUB_TIMEZONE });

export function noticeTime(ms: number): string {
    try {
        return timeFormat.format(new Date(ms));
    } catch {
        return 'earlier';
    }
}

/** "That ID was not found." becomes "That ID was not found" so the sentence around it reads cleanly. */
const clause = (message: string) => message.trim().replace(/[.\s]+$/, '') || 'No reason was given';

export function withdrawalNotice(entry: QueuedWithdrawal): Notice {
    const who = entry.member_name || `employee ID ${entry.body.employee_id}`;
    return {
        key: `w:${entry.client_uuid}`, kind: 'withdrawal', uuid: entry.client_uuid,
        text: `A withdrawal from ${noticeTime(entry.queued_at)} by ${who} could not be recorded: ${clause(entry.refused?.message ?? '')}. Tell Captain 5.`,
    };
}

export function undoNotice(entry: PendingUndo): Notice {
    return {
        key: `u:${entry.uuid}`, kind: 'undo', uuid: entry.uuid,
        text: `An undo from ${noticeTime(entry.taken_at)} by ${entry.member_name || 'a member'} could not be done: ${clause(entry.failed?.message ?? '')}. Tell Captain 5.`,
    };
}

export function Notices({ notices, onClear }: { notices: Notice[]; onClear: (notice: Notice) => void }) {
    if (notices.length === 0) return null;
    return (
        <ul className="k-notices" aria-label="Not recorded" data-testid="notices">
            {notices.map(notice => (
                <li key={notice.key} className="k-notice">
                    <p role="alert">{notice.text}</p>
                    <button type="button" className="k-secondary" onClick={() => onClear(notice)}>Captain 5 was told</button>
                </li>
            ))}
        </ul>
    );
}
