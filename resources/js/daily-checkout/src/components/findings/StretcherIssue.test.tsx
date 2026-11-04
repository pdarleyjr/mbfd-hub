import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Apparatus } from '../../types';
import { hasUnsavedWork, resetUnsavedWork } from '../../pwa/draftBusy.ts';
import { ApiClient } from '../../utils/api';
import StretcherIssueForm from './StretcherIssueForm';
import StretcherIssuePage from './StretcherIssuePage';
import { stretcherPhotoProblem } from './stretcherForm';

const fetchMock = vi.fn();
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const unit = (id: number, name: string, slug: string): Apparatus => ({ id, name, slug, type: 'rescue', vehicle_number: null });
const rescueOne = unit(7, 'Rescue 1', 'rescue-1');
const rescueTwo = unit(8, 'Rescue 2', 'rescue-2');
const engineOne = unit(3, 'Engine 1', 'engine-1');

const reply = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const sentForm = (call = 0) => (fetchMock.mock.calls[call][1] as RequestInit).body as FormData;
const created = (extra: Record<string, unknown> = {}) => reply(201, { data: { issue_id: 90, attached: false, message: 'Sent to Captain 5.', ...extra } });

function renderForm(onChangeUnit = vi.fn()) {
  render(<MemoryRouter><StretcherIssueForm apparatus={rescueOne} onChangeUnit={onChangeUnit} /></MemoryRouter>);

  return onChangeUnit;
}

async function fillValid(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('radio', { name: 'Battery' }));
  await user.type(screen.getByLabelText(/What is wrong\?/), 'Battery will not hold a charge');
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

describe('StretcherIssueForm', () => {
  it('shows the unit read-only with a Change control and the seven problem chips', async () => {
    const user = userEvent.setup();
    const onChangeUnit = renderForm();

    expect(screen.getByTestId('stretcher-unit')).toHaveTextContent('Rescue 1');
    expect(screen.getAllByRole('radio').map(radio => radio.closest('label')?.textContent)).toEqual(
      ['Loading / unloading', 'Armrest', 'Battery', 'Charger', 'Restraint', 'Mechanical', 'Other'],
    );
    await user.click(screen.getByRole('button', { name: 'Change unit' }));
    expect(onChangeUnit).toHaveBeenCalledTimes(1);
  });

  it('explains the can\'t-be-used switch and the photo field', () => {
    renderForm();

    const unusable = screen.getByRole('switch', { name: "The stretcher can't be used right now" });
    expect(unusable).not.toBeChecked();
    expect(unusable).toHaveAccessibleDescription('Captain 5 and the EMS Captain are told right away.');
    const photo = screen.getByLabelText('Photo (optional)');
    expect(photo).toHaveAttribute('accept', 'image/*');
    expect(photo).toHaveAttribute('capture', 'environment');
  });

  it('asks for a problem and an explanation before anything is sent', async () => {
    const user = userEvent.setup();
    renderForm();

    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));
    expect(await screen.findByText('Choose the kind of problem.')).toBeInTheDocument();

    await user.click(screen.getByRole('radio', { name: 'Armrest' }));
    await user.type(screen.getByLabelText(/What is wrong\?/), 'ab');
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));
    expect(await screen.findByText('Write at least 3 characters about what is wrong.')).toBeInTheDocument();
    expect(screen.getByLabelText(/What is wrong\?/)).toHaveAttribute('aria-invalid', 'true');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('counts characters and stops at 1000', async () => {
    const user = userEvent.setup();
    renderForm();

    const box = screen.getByLabelText(/What is wrong\?/);
    expect(box).toHaveAttribute('maxlength', '1000');
    await user.type(box, 'Strap');
    expect(screen.getByText('5 of 1000 characters')).toBeInTheDocument();
  });

  it('posts multipart with every field, then shows the server message and the two next steps', async () => {
    const user = userEvent.setup();
    fetchMock.mockResolvedValueOnce(created({ message: 'Sent to Captain 5. The EMS Captain was told.' }));
    renderForm();

    await fillValid(user);
    await user.click(screen.getByRole('switch', { name: "The stretcher can't be used right now" }));
    const photo = new File(['x'], 'strap.jpg', { type: 'image/jpeg' });
    await user.upload(screen.getByLabelText('Photo (optional)'), photo);
    expect(await screen.findByRole('button', { name: 'Remove photo' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));

    const sent = await screen.findByTestId('stretcher-sent');
    expect(sent).toHaveTextContent('Sent to Captain 5. The EMS Captain was told.');
    expect(screen.getByRole('link', { name: 'Back to unit issues' })).toHaveAttribute('href', '/vehicle-inspections/rescue-1/issues');
    expect(screen.getByRole('button', { name: 'Report another' })).toBeInTheDocument();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toBe('/api/public/apparatuses/7/stretcher-issues');
    const init = fetchMock.mock.calls[0][1] as RequestInit;
    expect(init.method).toBe('POST');
    // The browser adds the multipart boundary, so no Content-Type is set here.
    expect(Object.keys(init.headers as Record<string, string>).map(name => name.toLowerCase())).not.toContain('content-type');
    const form = sentForm();
    expect([...form.keys()].sort()).toEqual(['client_submission_id', 'description', 'photo', 'problem', 'unusable']);
    expect(String(form.get('client_submission_id'))).toMatch(UUID);
    expect(form.get('problem')).toBe('battery');
    expect(form.get('description')).toBe('Battery will not hold a charge');
    expect(form.get('unusable')).toBe('1');
    expect((form.get('photo') as File).name).toBe('strap.jpg');
  });

  it('sends unusable 0 and no photo field when neither was chosen', async () => {
    const user = userEvent.setup();
    fetchMock.mockResolvedValueOnce(created());
    renderForm();

    await fillValid(user);
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));
    await screen.findByTestId('stretcher-sent');

    const form = sentForm();
    expect(form.get('unusable')).toBe('0');
    expect(form.has('photo')).toBe(false);
  });

  it('keeps everything after a dropped answer and retries with the same client_submission_id', async () => {
    const user = userEvent.setup();
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
    fetchMock.mockResolvedValueOnce(reply(200, { data: { issue_id: 90, attached: true, message: 'Already received.' } }));
    renderForm();

    await fillValid(user);
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));
    expect(await screen.findByTestId('stretcher-error')).toHaveTextContent('Nothing was confirmed');
    expect(screen.getByLabelText(/What is wrong\?/)).toHaveValue('Battery will not hold a charge');
    expect(screen.getByRole('radio', { name: 'Battery' })).toBeChecked();

    await user.click(screen.getByRole('button', { name: 'Retry: Send to Captain 5' }));
    expect(await screen.findByTestId('stretcher-sent')).toHaveTextContent('Already received.');

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(sentForm(1).get('client_submission_id')).toBe(sentForm(0).get('client_submission_id'));
  });

  it('uses a fresh client_submission_id for the next report', async () => {
    const user = userEvent.setup();
    fetchMock.mockResolvedValue(created());
    renderForm();

    await fillValid(user);
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));
    await screen.findByTestId('stretcher-sent');
    await user.click(screen.getByRole('button', { name: 'Report another' }));

    expect(screen.getByLabelText(/What is wrong\?/)).toHaveValue('');
    await fillValid(user);
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));
    await screen.findByTestId('stretcher-sent');

    expect(sentForm(1).get('client_submission_id')).not.toBe(sentForm(0).get('client_submission_id'));
  });

  it('says it cannot be sent offline and keeps the form as it was', async () => {
    const user = userEvent.setup();
    const online = vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(false);
    renderForm();

    expect(screen.getByTestId('stretcher-offline')).toHaveTextContent("can't be sent until you are back online");
    await fillValid(user);
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));

    expect(await screen.findByTestId('stretcher-error')).toHaveTextContent('Nothing was sent');
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByLabelText(/What is wrong\?/)).toHaveValue('Battery will not hold a charge');
    online.mockRestore();
  });

  it('shows the server message for a refused report (422 on the explanation) and keeps the text', async () => {
    const user = userEvent.setup();
    fetchMock.mockResolvedValueOnce(reply(422, { message: 'The description is not valid.', errors: { description: ['The description must not contain only symbols.'] } }));
    renderForm();

    await fillValid(user);
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));

    expect(await screen.findByText('The description must not contain only symbols.')).toBeInTheDocument();
    expect(screen.getByLabelText(/What is wrong\?/)).toHaveValue('Battery will not hold a charge');
  });

  it('refuses a photo in the wrong format and does not attach it', async () => {
    const user = userEvent.setup();
    renderForm();

    await user.upload(screen.getByLabelText('Photo (optional)'), new File(['x'], 'clip.gif', { type: 'image/gif' }));

    expect(await screen.findByText('Choose a JPEG, PNG, HEIC or WebP photo under 10 MB.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Remove photo' })).not.toBeInTheDocument();
  });

  it('holds the page open while the report is unsent and lets go once it is sent', async () => {
    const user = userEvent.setup();
    fetchMock.mockResolvedValueOnce(created());
    renderForm();
    expect(hasUnsavedWork()).toBe(false);

    await user.type(screen.getByLabelText(/What is wrong\?/), 'Latch sticks');
    expect(hasUnsavedWork()).toBe(true);

    await user.click(screen.getByRole('radio', { name: 'Mechanical' }));
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));
    await screen.findByTestId('stretcher-sent');
    expect(hasUnsavedWork()).toBe(false);
  });
});

describe('stretcherPhotoProblem', () => {
  it('accepts JPEG, PNG, HEIC and WebP under 10 MB, including a HEIC with no type', () => {
    expect(stretcherPhotoProblem({ name: 'a.jpg', type: 'image/jpeg', size: 100 })).toBeNull();
    expect(stretcherPhotoProblem({ name: 'a.png', type: 'image/png', size: 100 })).toBeNull();
    expect(stretcherPhotoProblem({ name: 'a.heic', type: 'image/heic', size: 100 })).toBeNull();
    expect(stretcherPhotoProblem({ name: 'a.webp', type: 'image/webp', size: 100 })).toBeNull();
    expect(stretcherPhotoProblem({ name: 'IMG_1.HEIC', type: '', size: 100 })).toBeNull();
  });

  it('refuses other formats and anything over 10 MB', () => {
    expect(stretcherPhotoProblem({ name: 'a.gif', type: 'image/gif', size: 100 })).not.toBeNull();
    expect(stretcherPhotoProblem({ name: 'a.pdf', type: 'application/pdf', size: 100 })).not.toBeNull();
    expect(stretcherPhotoProblem({ name: 'a.jpg', type: 'image/jpeg', size: 10 * 1024 * 1024 + 1 })).not.toBeNull();
  });
});

describe('StretcherIssuePage', () => {
  function renderPage(entry: string) {
    return render(
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route path="/forms-hub/stretcher-issue" element={<StretcherIssuePage />} />
          <Route path="/vehicle-inspections/:slug/stretcher-issue" element={<StretcherIssuePage />} />
        </Routes>
      </MemoryRouter>,
    );
  }

  beforeEach(() => {
    vi.spyOn(ApiClient, 'getApparatuses').mockResolvedValue([rescueOne, rescueTwo, engineOne]);
  });

  it('opens straight on the unit from the address', async () => {
    renderPage('/vehicle-inspections/rescue-2/stretcher-issue');

    expect(await screen.findByTestId('stretcher-unit')).toHaveTextContent('Rescue 2');
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
  });

  it('asks for the unit first from the Forms Hub, with a search', async () => {
    const user = userEvent.setup();
    renderPage('/forms-hub/stretcher-issue');

    const search = await screen.findByRole('searchbox');
    expect(screen.getAllByRole('button', { name: /^(Engine|Rescue) \d$/ })).toHaveLength(3);
    await user.type(search, 'rescue');
    expect(screen.getAllByRole('button', { name: /^Rescue \d$/ })).toHaveLength(2);
    expect(screen.queryByRole('button', { name: 'Engine 1' })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Rescue 2' }));
    expect(await screen.findByTestId('stretcher-unit')).toHaveTextContent('Rescue 2');
  });

  it('keeps what was typed when the unit is changed', async () => {
    const user = userEvent.setup();
    renderPage('/vehicle-inspections/rescue-1/stretcher-issue');

    await screen.findByTestId('stretcher-unit');
    await user.type(screen.getByLabelText(/What is wrong\?/), 'Armrest will not lock');
    await user.click(screen.getByRole('button', { name: 'Change unit' }));
    await user.click(await screen.findByRole('button', { name: 'Rescue 2' }));

    expect(screen.getByTestId('stretcher-unit')).toHaveTextContent('Rescue 2');
    expect(screen.getByLabelText(/What is wrong\?/)).toHaveValue('Armrest will not lock');
  });

  it('sends the report for the unit that was chosen last', async () => {
    const user = userEvent.setup();
    fetchMock.mockResolvedValueOnce(created());
    renderPage('/forms-hub/stretcher-issue');

    await user.click(await screen.findByRole('button', { name: 'Rescue 2' }));
    await user.click(screen.getByRole('radio', { name: 'Charger' }));
    await user.type(screen.getByLabelText(/What is wrong\?/), 'Charger light stays red');
    await user.click(screen.getByRole('button', { name: 'Send to Captain 5' }));

    await screen.findByTestId('stretcher-sent');
    expect(fetchMock.mock.calls[0][0]).toBe('/api/public/apparatuses/8/stretcher-issues');
  });

  it('falls back to the list when the unit in the address is not found', async () => {
    renderPage('/vehicle-inspections/nope/stretcher-issue');

    expect(await screen.findByText('That unit was not found. Choose it from the list.')).toBeInTheDocument();
    expect(screen.getByRole('searchbox')).toBeInTheDocument();
  });

  it('offers a retry when the unit list cannot be loaded', async () => {
    const user = userEvent.setup();
    vi.mocked(ApiClient.getApparatuses).mockRejectedValueOnce(new Error('Failed to fetch apparatuses'));
    renderPage('/forms-hub/stretcher-issue');

    expect(await screen.findByText('Units could not be loaded')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByRole('searchbox')).toBeInTheDocument());
  });
});
