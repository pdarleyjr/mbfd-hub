import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { IssueSummary } from '../../types/issueHistory.ts';
import { hasUnsavedWork, resetUnsavedWork } from '../../pwa/draftBusy.ts';
import { FINDINGS_CHANGED_EVENT } from './findingsChanged.ts';
import IssueCard from './IssueCard';
import MemberCorrectionSheet from './MemberCorrectionSheet';

const fetchMock = vi.fn();
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const reply = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const sentBody = (call = 0) => JSON.parse(String((fetchMock.mock.calls[call][1] as RequestInit).body)) as Record<string, unknown>;

function issue(extra: Partial<IssueSummary> = {}): IssueSummary {
  return {
    id: 42,
    compartment: 'Driver side',
    item: 'Flashlight',
    compartment_id: null,
    item_id: null,
    issue_type: 'missing',
    severity: 'routine',
    description: 'Not in its holder.',
    reporter_name: 'Pat Lee',
    first_reported_at: null,
    action_status: 'open',
    action_label: 'Open',
    closure_kind: null,
    latest_action: null,
    last_observation: null,
    last_observed_at: null,
    service_status: null,
    recurrence_of_defect_id: null,
    history_count: 0,
    member_correction: { eligible: true, closes_immediately: false, pending: null },
    owner: 'logistics',
    ...extra,
  };
}

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
  resetUnsavedWork();
});

afterEach(() => {
  vi.unstubAllGlobals();
  resetUnsavedWork();
});

describe('MemberCorrectionSheet', () => {
  it('says Report fixed and who verifies when the owner must check it', () => {
    render(<MemberCorrectionSheet issue={issue()} open onClose={vi.fn()} />);

    expect(screen.getByRole('button', { name: 'Report fixed' })).toBeInTheDocument();
    expect(screen.getByTestId('member-correction-verify')).toHaveTextContent('Support Services will verify this before it is closed.');
  });

  it('names Captain 5 for a Captain 5 issue and both when the owner is not known', () => {
    const { unmount } = render(<MemberCorrectionSheet issue={issue({ owner: 'captain5' })} open onClose={vi.fn()} />);
    expect(screen.getByTestId('member-correction-verify')).toHaveTextContent('Captain 5 will verify this before it is closed.');
    unmount();

    render(<MemberCorrectionSheet issue={issue({ owner: undefined })} open onClose={vi.fn()} />);
    expect(screen.getByTestId('member-correction-verify')).toHaveTextContent('Captain 5 or Support Services will verify this before it is closed.');
  });

  it('says Mark resolved when the report closes the issue at once', () => {
    render(<MemberCorrectionSheet issue={issue({ member_correction: { eligible: true, closes_immediately: true, pending: null } })} open onClose={vi.fn()} />);

    expect(screen.getByRole('button', { name: 'Mark resolved' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Report fixed' })).not.toBeInTheDocument();
    expect(screen.getByTestId('member-correction-verify')).toHaveTextContent('This closes the issue right away.');
  });

  it('offers the five choices and asks for one before anything is sent', async () => {
    const user = userEvent.setup();
    render(<MemberCorrectionSheet issue={issue()} open onClose={vi.fn()} />);

    expect(screen.getAllByRole('radio').map(radio => radio.closest('label')?.textContent)).toEqual(['Found', 'Replaced', 'Repaired', 'Fixed', 'No longer a problem']);
    await user.click(screen.getByRole('button', { name: 'Report fixed' }));

    expect(await screen.findByText('Choose what happened.')).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('sends one JSON request with the outcome, the trimmed note and a client_action_id, then shows the server message', async () => {
    const user = userEvent.setup();
    const heard = vi.fn();
    window.addEventListener(FINDINGS_CHANGED_EVENT, heard);
    fetchMock.mockResolvedValueOnce(reply(200, { data: { result: 'awaiting_verification', status_label: 'Correction reported', message: 'Thanks. Support Services will check it.' } }));
    const onClose = vi.fn();
    render(<MemberCorrectionSheet issue={issue()} open onClose={onClose} />);

    await user.click(screen.getByRole('radio', { name: 'Replaced' }));
    await user.type(screen.getByLabelText('Note (optional)'), '  New one from the supply room  ');
    await user.click(screen.getByRole('button', { name: 'Report fixed' }));

    expect(await screen.findByTestId('member-correction-done')).toHaveTextContent('Thanks. Support Services will check it.');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toBe('/api/public/apparatus-issues/42/member-correction');
    expect((fetchMock.mock.calls[0][1] as RequestInit).method).toBe('POST');
    const body = sentBody();
    expect(Object.keys(body).sort()).toEqual(['client_action_id', 'note', 'outcome']);
    expect(body.outcome).toBe('replaced');
    expect(body.note).toBe('New one from the supply room');
    expect(String(body.client_action_id)).toMatch(UUID);

    // The server's words reach the page straight away; the list is read when the member taps Done.
    expect(heard).toHaveBeenCalledTimes(1);
    expect((heard.mock.calls[0][0] as CustomEvent).detail).toEqual({ message: 'Thanks. Support Services will check it.', refresh: false });
    await user.click(screen.getByRole('button', { name: 'Done' }));
    expect(onClose).toHaveBeenCalledTimes(1);
    expect((heard.mock.calls[1][0] as CustomEvent).detail).toEqual({ refresh: true });
    window.removeEventListener(FINDINGS_CHANGED_EVENT, heard);
  });

  it('leaves the note out when it is empty', async () => {
    const user = userEvent.setup();
    fetchMock.mockResolvedValueOnce(reply(200, { data: { result: 'resolved', status_label: 'Resolved', message: 'Closed.' } }));
    render(<MemberCorrectionSheet issue={issue({ member_correction: { eligible: true, closes_immediately: true, pending: null } })} open onClose={vi.fn()} />);

    await user.click(screen.getByRole('radio', { name: 'No longer a problem' }));
    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));

    await screen.findByTestId('member-correction-done');
    expect(sentBody()).toMatchObject({ outcome: 'no_longer_deficient' });
    expect(sentBody()).not.toHaveProperty('note');
  });

  it('keeps the member\'s choices after a dropped answer and retries with the same client_action_id', async () => {
    const user = userEvent.setup();
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
    fetchMock.mockResolvedValueOnce(reply(200, { data: { result: 'awaiting_verification', status_label: 'Correction reported', message: 'Recorded.' } }));
    render(<MemberCorrectionSheet issue={issue()} open onClose={vi.fn()} />);

    await user.click(screen.getByRole('radio', { name: 'Repaired' }));
    await user.type(screen.getByLabelText('Note (optional)'), 'Taped the strap');
    await user.click(screen.getByRole('button', { name: 'Report fixed' }));

    expect(await screen.findByTestId('member-correction-error')).toHaveTextContent('Nothing was confirmed');
    expect(screen.getByRole('radio', { name: 'Repaired' })).toBeChecked();
    expect(screen.getByLabelText('Note (optional)')).toHaveValue('Taped the strap');

    await user.click(screen.getByRole('button', { name: 'Retry: Report fixed' }));
    await screen.findByTestId('member-correction-done');

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(sentBody(1).client_action_id).toBe(sentBody(0).client_action_id);
  });

  it('uses a new client_action_id each time the sheet is opened', async () => {
    const user = userEvent.setup();
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
    const first = render(<MemberCorrectionSheet issue={issue()} open onClose={vi.fn()} />);
    await user.click(screen.getByRole('radio', { name: 'Found' }));
    await user.click(screen.getByRole('button', { name: 'Report fixed' }));
    await screen.findByTestId('member-correction-error');
    first.unmount();

    render(<MemberCorrectionSheet issue={issue()} open onClose={vi.fn()} />);
    await user.click(screen.getByRole('radio', { name: 'Found' }));
    await user.click(screen.getByRole('button', { name: 'Report fixed' }));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

    expect(sentBody(1).client_action_id).not.toBe(sentBody(0).client_action_id);
  });

  it('explains a closed issue (409 ISSUE_CLOSED), stops offering the send and refreshes the list on Close', async () => {
    const user = userEvent.setup();
    const heard = vi.fn();
    window.addEventListener(FINDINGS_CHANGED_EVENT, heard);
    fetchMock.mockResolvedValueOnce(reply(409, { code: 'ISSUE_CLOSED', message: 'This issue is closed.' }));
    const onClose = vi.fn();
    render(<MemberCorrectionSheet issue={issue()} open onClose={onClose} />);

    await user.click(screen.getByRole('radio', { name: 'Fixed' }));
    await user.click(screen.getByRole('button', { name: 'Report fixed' }));

    expect(await screen.findByTestId('member-correction-error')).toHaveTextContent('Someone already closed this issue.');
    expect(screen.queryByRole('button', { name: /Report fixed/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).toHaveBeenCalledTimes(1);
    expect((heard.mock.calls[0][0] as CustomEvent).detail).toEqual({ refresh: true });
    window.removeEventListener(FINDINGS_CHANGED_EVENT, heard);
  });

  it('shows the server reason when the member is not allowed (403) and does not offer a retry', async () => {
    const user = userEvent.setup();
    fetchMock.mockResolvedValueOnce(reply(403, { message: 'Only members with an employee profile can do this.' }));
    render(<MemberCorrectionSheet issue={issue()} open onClose={vi.fn()} />);

    await user.click(screen.getByRole('radio', { name: 'Fixed' }));
    await user.click(screen.getByRole('button', { name: 'Report fixed' }));

    expect(await screen.findByTestId('member-correction-error')).toHaveTextContent('Only members with an employee profile can do this.');
    expect(screen.queryByRole('button', { name: /Report fixed/ })).not.toBeInTheDocument();
  });

  it('tells the member offline that nothing is queued, sends nothing and keeps the note', async () => {
    const user = userEvent.setup();
    const online = vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(false);
    render(<MemberCorrectionSheet issue={issue()} open onClose={vi.fn()} />);

    expect(screen.getByTestId('member-correction-offline')).toHaveTextContent('not saved for later');
    await user.click(screen.getByRole('radio', { name: 'Fixed' }));
    await user.type(screen.getByLabelText('Note (optional)'), 'Back in the holder');
    await user.click(screen.getByRole('button', { name: 'Report fixed' }));

    expect(await screen.findByTestId('member-correction-error')).toHaveTextContent('Nothing was sent');
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Note (optional)')).toHaveValue('Back in the holder');
    online.mockRestore();
  });

  it('holds the page open (busy) while a note is half written and lets go when the sheet closes', async () => {
    const user = userEvent.setup();
    const { unmount } = render(<MemberCorrectionSheet issue={issue()} open onClose={vi.fn()} />);
    expect(hasUnsavedWork()).toBe(false);

    await user.type(screen.getByLabelText('Note (optional)'), 'half a thought');
    expect(hasUnsavedWork()).toBe(true);

    unmount();
    expect(hasUnsavedWork()).toBe(false);
  });

  it('holds the page open while the request is in flight', async () => {
    const user = userEvent.setup();
    let finish: (response: Response) => void = () => undefined;
    fetchMock.mockReturnValueOnce(new Promise<Response>((resolve) => { finish = resolve; }));
    render(<MemberCorrectionSheet issue={issue()} open onClose={vi.fn()} />);

    await user.click(screen.getByRole('radio', { name: 'Fixed' }));
    await user.click(screen.getByRole('button', { name: 'Report fixed' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Saving...' })).toBeDisabled());
    expect(hasUnsavedWork()).toBe(true);

    finish(reply(200, { data: { result: 'resolved', status_label: 'Resolved', message: 'Closed.' } }));
    await screen.findByTestId('member-correction-done');
    expect(hasUnsavedWork()).toBe(false);
  });
});

describe('IssueCard member correction', () => {
  it('shows Mark fixed for an eligible issue and opens the sheet in one tap', async () => {
    const user = userEvent.setup();
    render(<IssueCard issue={issue()} />);

    await user.click(screen.getByRole('button', { name: 'Mark fixed' }));

    expect(await screen.findByTestId('member-correction-sheet')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Report fixed' })).toBeInTheDocument();
  });

  it('shows who handles the issue', () => {
    const { unmount } = render(<IssueCard issue={issue({ owner: 'captain5' })} />);
    expect(screen.getByText('Handled by Captain 5')).toBeInTheDocument();
    unmount();

    render(<IssueCard issue={issue({ owner: 'logistics' })} />);
    expect(screen.getByText('Handled by Support Services')).toBeInTheDocument();
  });

  it('shows the amber awaiting line instead of the button once a correction is reported', () => {
    render(<IssueCard issue={issue({ member_correction: { eligible: true, closes_immediately: false, pending: { at: '2026-11-03T14:00:00Z' } } })} />);

    expect(screen.getByRole('status')).toHaveTextContent('Correction reported, awaiting verification');
    expect(screen.queryByRole('button', { name: 'Mark fixed' })).not.toBeInTheDocument();
  });

  it('shows nothing extra when the member cannot act or the server sent nothing', () => {
    const { unmount } = render(<IssueCard issue={issue({ member_correction: { eligible: false, closes_immediately: false, pending: null }, owner: undefined })} />);
    expect(screen.queryByRole('button', { name: 'Mark fixed' })).not.toBeInTheDocument();
    expect(screen.queryByText(/Handled by/)).not.toBeInTheDocument();
    unmount();

    render(<IssueCard issue={issue({ member_correction: undefined })} />);
    expect(screen.queryByRole('button', { name: 'Mark fixed' })).not.toBeInTheDocument();
  });

  it('does not offer Mark fixed on a closed issue', () => {
    render(<IssueCard issue={issue({ closure_kind: 'resolved', action_status: 'resolved', action_label: 'Resolved' })} />);

    expect(screen.queryByRole('button', { name: 'Mark fixed' })).not.toBeInTheDocument();
  });
});
