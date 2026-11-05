import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { StationRequestSummary } from '../../types';
import * as resolveApi from '../../utils/stationRequestResolveApi';
import { StationRequestResolveError } from '../../utils/stationRequestResolveApi';
import { isBusy } from '../../../../pwa/busy.ts';
import StationRequestResolveSheet from './StationRequestResolveSheet';
import StationRequestSheet from './StationRequestSheet';

function request(overrides: Record<string, unknown> = {}): StationRequestSummary {
  return {
    id: 7,
    request_number: 'SR-2026-000007',
    station_id: 1,
    request_type: 'repair_service',
    title: 'Kitchen sink leak',
    description: 'The sink drips constantly.',
    priority: 'normal',
    status: 'pending',
    is_open: true,
    created_at: '2026-11-03T12:00:00Z',
    updated_at: '2026-11-03T12:00:00Z',
    member_resolution: { eligible: true, note_required: false },
    ...overrides,
  } as StationRequestSummary;
}

function renderSheet(props: Partial<{ request: StationRequestSummary | null }> = {}) {
  const onClose = vi.fn();
  const onResolved = vi.fn();
  render(<StationRequestResolveSheet request={props.request === undefined ? request() : props.request} onClose={onClose} onResolved={onResolved} />);

  return { onClose, onResolved, user: userEvent.setup() };
}

const resolveMock = () => vi.spyOn(resolveApi, 'resolveStationRequest');

beforeEach(() => {
  vi.restoreAllMocks();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('Mark resolved form', () => {
  it('sends the chosen outcome and note, then says it was saved and refreshes the list', async () => {
    const send = resolveMock().mockResolvedValue({ statusLabel: 'Completed', message: 'Marked resolved. Thank you.' });
    const { user, onResolved } = renderSheet();

    await user.click(screen.getByRole('radio', { name: 'Fixed' }));
    await user.type(screen.getByLabelText(/Note \(optional\)/), 'Replaced the washer.');
    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));

    expect(send).toHaveBeenCalledTimes(1);
    expect(send).toHaveBeenCalledWith(7, expect.objectContaining({ outcome: 'fixed', note: 'Replaced the washer.', clientActionId: expect.stringMatching(/^[0-9a-f-]{36}$/) }));
    expect(await screen.findByText('Marked resolved')).toBeInTheDocument();
    expect(onResolved).toHaveBeenCalledTimes(1);
  });

  it('asks for an outcome before sending anything', async () => {
    const send = resolveMock();
    const { user } = renderSheet();

    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));

    expect(await screen.findByText('Choose what happened.')).toBeInTheDocument();
    expect(send).not.toHaveBeenCalled();
  });

  it('requires a note from a member who did not submit the request', async () => {
    const send = resolveMock().mockResolvedValue({ statusLabel: 'Completed', message: 'Marked resolved. Thank you.' });
    const { user } = renderSheet({ request: request({ member_resolution: { eligible: true, note_required: true } }) });

    expect(screen.getByLabelText(/What did you do\? \(required\)/)).toBeInTheDocument();
    await user.click(screen.getByRole('radio', { name: 'Fixed' }));
    await user.type(screen.getByRole('textbox'), 'ok');
    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));
    expect(await screen.findByText('Say what you did. A few words is enough.')).toBeInTheDocument();
    expect(send).not.toHaveBeenCalled();

    await user.type(screen.getByRole('textbox'), ' replaced the washer');
    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));
    await waitFor(() => expect(send).toHaveBeenCalledTimes(1));
  });

  it('keeps what was typed after a failure and retries with the same client action id', async () => {
    const send = resolveMock()
      .mockRejectedValueOnce(new StationRequestResolveError('The server could not be reached. Nothing was changed. Try again.', 0))
      .mockResolvedValueOnce({ statusLabel: 'Completed', message: 'Marked resolved. Thank you.' });
    const { user } = renderSheet();

    await user.click(screen.getByRole('radio', { name: 'Received' }));
    await user.type(screen.getByRole('textbox'), 'It arrived.');
    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));

    expect(await screen.findByTestId('station-request-resolve-error')).toHaveTextContent('The server could not be reached');
    expect(screen.getByRole('textbox')).toHaveValue('It arrived.');
    expect(screen.getByRole('radio', { name: 'Received' })).toBeChecked();

    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));
    expect(await screen.findByText('Marked resolved')).toBeInTheDocument();
    expect(send).toHaveBeenCalledTimes(2);
    expect(send.mock.calls[1][1].clientActionId).toBe(send.mock.calls[0][1].clientActionId);
  });

  it('shows the offline message and does not queue the action', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
    const { user } = renderSheet();

    await user.click(screen.getByRole('radio', { name: 'Fixed' }));
    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));

    expect(await screen.findByTestId('station-request-resolve-error')).toHaveTextContent("You're offline. Try again when connected.");
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('tells the member the request was already closed and refreshes the list', async () => {
    resolveMock().mockRejectedValue(new StationRequestResolveError('This request is already closed.', 409, 'REQUEST_CLOSED', {}, 'Completed'));
    const { user, onResolved } = renderSheet();

    await user.click(screen.getByRole('radio', { name: 'Fixed' }));
    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));

    expect(await screen.findByText('Already closed')).toBeInTheDocument();
    expect(screen.getByText('This request is already completed. Nothing was changed.')).toBeInTheDocument();
    expect(onResolved).toHaveBeenCalledTimes(1);
  });

  it('marks the app busy only while the save is in flight', async () => {
    let finish: (value: { statusLabel: string; message: string }) => void = () => undefined;
    resolveMock().mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
    const { user } = renderSheet();

    await user.click(screen.getByRole('radio', { name: 'Fixed' }));
    await user.click(screen.getByRole('button', { name: 'Mark resolved' }));
    await waitFor(() => expect(isBusy()).toBe(true));
    expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();

    finish({ statusLabel: 'Completed', message: 'Marked resolved. Thank you.' });
    await screen.findByText('Marked resolved');
    expect(isBusy()).toBe(false);
  });
});

describe('Mark resolved button on the request sheet', () => {
  const renderRequestSheet = (value: StationRequestSummary) => render(
    <StationRequestSheet request={value} onClose={() => undefined} returnFocusTo={() => null} onResolved={() => undefined} />,
  );

  it('is offered when the server says the member is eligible', () => {
    renderRequestSheet(request());
    expect(screen.getByTestId('station-request-mark-resolved')).toBeInTheDocument();
  });

  it('is hidden when the member is not eligible, the request is closed, or the payload has no answer', () => {
    const { unmount } = renderRequestSheet(request({ member_resolution: { eligible: false, note_required: false } }));
    expect(screen.queryByTestId('station-request-mark-resolved')).toBeNull();
    unmount();

    const closed = renderRequestSheet(request({ is_open: false, status: 'completed' }));
    expect(screen.queryByTestId('station-request-mark-resolved')).toBeNull();
    closed.unmount();

    renderRequestSheet(request({ member_resolution: undefined }));
    expect(screen.queryByTestId('station-request-mark-resolved')).toBeNull();
  });
});
