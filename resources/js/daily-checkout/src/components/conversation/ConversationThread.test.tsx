import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchSubmissionConversation, postSubmissionReply } from '../../utils/conversationApi';
import ConversationThread from './ConversationThread';

const fetchMock = vi.fn();
const reply = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const sentBody = (call: number) => JSON.parse(String((fetchMock.mock.calls[call][1] as RequestInit).body)) as Record<string, unknown>;

const staffMessage = { id: 1, body: 'Plumber booked for Friday.', own: false, from_staff: true, author: 'Support Services', at: '2026-11-05T14:00:00Z' };
const ownMessage = { id: 2, body: 'Friday works.', own: true, from_staff: false, author: 'You', at: '2026-11-05T15:00:00Z' };

function renderThread() {
  return render(
    <ConversationThread
      id="station-request-7"
      load={() => fetchSubmissionConversation('station_request', 7)}
      send={(body, clientMessageId) => postSubmissionReply('station_request', 7, body, clientMessageId)}
    />,
  );
}

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('ConversationThread', () => {
  it('shows staff replies and sends a member reply', async () => {
    fetchMock.mockResolvedValueOnce(reply(200, { data: { can_reply: true, messages: [staffMessage] } }));
    fetchMock.mockResolvedValueOnce(reply(201, { data: { can_reply: true, messages: [staffMessage, ownMessage] } }));
    renderThread();

    expect(await screen.findByText('Plumber booked for Friday.')).toBeTruthy();
    expect(screen.getByText(/Support Services/)).toBeTruthy();
    expect(String(fetchMock.mock.calls[0][0])).toContain('/api/public/submission-conversations/station_request/7');

    await userEvent.type(screen.getByLabelText('Reply'), 'Friday works.');
    await userEvent.click(screen.getByRole('button', { name: 'Send reply' }));

    expect(await screen.findByText('Friday works.')).toBeTruthy();
    expect((fetchMock.mock.calls[1][1] as RequestInit).method).toBe('POST');
    expect(sentBody(1).body).toBe('Friday works.');
    expect((screen.getByLabelText('Reply') as HTMLTextAreaElement).value).toBe('');
  });

  it('keeps the text and reuses the same message id when a send gets no answer', async () => {
    fetchMock.mockResolvedValueOnce(reply(200, { data: { can_reply: true, messages: [] } }));
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
    fetchMock.mockResolvedValueOnce(reply(201, { data: { can_reply: true, messages: [ownMessage] } }));
    renderThread();

    await userEvent.type(await screen.findByLabelText('Reply'), 'Friday works.');
    await userEvent.click(screen.getByRole('button', { name: 'Send reply' }));
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect((screen.getByLabelText('Reply') as HTMLTextAreaElement).value).toBe('Friday works.');

    await userEvent.click(screen.getByRole('button', { name: 'Send reply' }));
    await waitFor(() => expect(screen.getByText('Friday works.', { selector: 'p' })).toBeTruthy());
    expect(sentBody(2).client_message_id).toBe(sentBody(1).client_message_id);
  });

  it('shows no reply box when the server says the conversation is closed', async () => {
    fetchMock.mockResolvedValueOnce(reply(200, { data: { can_reply: false, messages: [staffMessage] } }));
    renderThread();

    expect(await screen.findByText('Plumber booked for Friday.')).toBeTruthy();
    expect(screen.queryByLabelText('Reply')).toBeNull();
  });
});
