import { configure, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EmsApiError } from '../utils/emsApi';
import Captain5Page from './Captain5Page';
import { fetchOverview } from './captain5/ems/supplyData/api.ts';
import { overview } from './captain5/ems/supplyData/testData.ts';
import { fetchEmsMeta } from '../utils/emsApi';

vi.mock('../utils/captain5Api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../utils/captain5Api')>()),
  fetchWorkspace: vi.fn().mockResolvedValue({
    access: { authority_label: 'Captain 5' },
    on_duty: { duty_date: '2026-11-02', shift: 'A', source: 'telestaff', resolved: true, name: 'Pat Captain', rank: 'Capt.' },
    duty_date: '2026-11-02',
    counts: { open: 0, needs_acknowledgement: 0, ordered: 0, ready_for_pickup: 0, overdue: 0, awaiting_verification: 0, significant: 0, rescue: 0, medical: 0 },
    rescue_dailies: [], issues: [], cleared_issues: [], rescue_trucks: [], rescue_truck_changes: [], handoff: {}, generated_at: '2026-11-02T14:00:00-05:00',
  }),
  fetchMessagesUnread: vi.fn().mockResolvedValue(0),
}));
vi.mock('../utils/emsApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../utils/emsApi')>()),
  fetchEmsMeta: vi.fn(),
  fetchRequestTotal: vi.fn().mockResolvedValue(2),
}));
vi.mock('../utils/narcoticsApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../utils/narcoticsApi')>()),
  fetchNarcoticsMeta: vi.fn().mockResolvedValue({ permissions: { review: false }, pending_count: 0 }),
}));
vi.mock('./captain5/ems/supplyData/api.ts', () => ({ fetchOverview: vi.fn(), fetchItem: vi.fn(), fetchUnit: vi.fn() }));
vi.mock('./DailyPageNav', () => ({ default: () => null }));
vi.mock('./acting/DutyNote', () => ({ default: () => null }));
vi.mock('./captain5/IssueSheet', () => ({ default: () => null }));
vi.mock('./captain5/InspectionSheet', () => ({ default: () => null }));
vi.mock('./captain5/TodayTab', () => ({ default: () => <p>Today tab</p> }));
vi.mock('./captain5/ems/forms/SubmittedFormsTab', () => ({ default: ({ requestId }: { requestId: string | null }) => <p>Forms tab {requestId}</p> }));
vi.mock('./captain5/ems/master/MasterInventoryTab', () => ({ default: () => <p>Master inventory tab</p> }));

// The tab is a lazy chunk, so the first time it is opened Vite has to load and transform it.
configure({ asyncUtilTimeout: 15_000 });

const metaMock = vi.mocked(fetchEmsMeta);
const overviewMock = vi.mocked(fetchOverview);

const META = { permissions: { view: true, act: true, configure: false }, inventory: { read: true, edit: true }, statuses: [], target_kinds: [], units: [], close_reasons: [] };

function Probe() {
  const location = useLocation();
  return <p data-testid="location">{location.search}</p>;
}

function renderPage(entry: string) {
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes><Route path="*" element={<><Captain5Page /><Probe /></>} /></Routes>
    </MemoryRouter>,
  );
}

const tabNames = () => within(screen.getByRole('tablist', { name: 'Captain 5 workspace' })).getAllByRole('tab').map((tab) => tab.textContent?.replace(/\d+\s*(forms waiting|open issues|unread messages)?$/, '').trim());

beforeEach(() => {
  metaMock.mockReset().mockResolvedValue(META as never);
  overviewMock.mockReset().mockResolvedValue(overview());
});

describe('Captain 5 page: Supply Data tab', () => {
  it('registers the tab right after the medical inventory tabs, with the exact label', async () => {
    renderPage('/daily/captain-5');
    await screen.findByRole('tab', { name: 'Supply Data' });
    const names = tabNames();
    const supply = names.indexOf('Master Supply Room Inventory');
    expect(names.indexOf('Submitted Inventory Forms')).toBeGreaterThan(-1);
    expect(supply).toBeGreaterThan(-1);
    expect(names[supply + 1]).toBe('Supply Data');
  });

  it('opens from a deep link, loads, and leaves the other EMS tab ids alone', async () => {
    renderPage('/daily/captain-5?tab=supply-data&period=90d');
    expect(await screen.findByRole('tab', { name: 'Supply Data' })).toHaveAttribute('aria-selected', 'true');
    expect(await screen.findByTestId('supply-data-attention')).toBeInTheDocument();
    expect(overviewMock).toHaveBeenCalledWith({ period: '90d', from: null, to: null });
  });

  it('clears its own address parameters when another tab is chosen', async () => {
    const user = userEvent.setup();
    renderPage('/daily/captain-5?tab=supply-data&period=90d&explore=stock&stock=zero');
    await screen.findByTestId('supply-data-attention');
    await user.click(screen.getByRole('tab', { name: 'Master Supply Room Inventory' }));
    const search = screen.getByTestId('location').textContent ?? '';
    expect(search).toBe('?tab=supply');
  });

  it('has no Supply Data tab for an administrator who has only the master inventory', async () => {
    metaMock.mockResolvedValue({ ...META, permissions: { view: false, act: false, configure: false } } as never);
    renderPage('/daily/captain-5');
    await screen.findByRole('tab', { name: 'Master Supply Room Inventory' });
    expect(screen.queryByRole('tab', { name: 'Supply Data' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Submitted Inventory Forms' })).not.toBeInTheDocument();
  });

  it('has no EMS tabs at all when the EMS API refuses the person', async () => {
    metaMock.mockReset().mockRejectedValue(new EmsApiError('Your account does not have access to do that.', 403));
    renderPage('/daily/captain-5');
    await screen.findByRole('tab', { name: 'Today' });
    await waitFor(() => expect(metaMock).toHaveBeenCalled());
    expect(screen.queryByRole('tab', { name: 'Supply Data' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Master Supply Room Inventory' })).not.toBeInTheDocument();
  });

  it('hides every EMS tab, Supply Data included, when its own call answers 403, like the other EMS tabs', async () => {
    overviewMock.mockReset().mockRejectedValue(new EmsApiError('Your account does not have access to do that.', 403));
    renderPage('/daily/captain-5?tab=supply-data');
    await screen.findByRole('tab', { name: 'Supply Data' });
    await waitFor(() => expect(overviewMock).toHaveBeenCalled());
    await waitFor(() => expect(screen.queryByRole('tab', { name: 'Supply Data' })).not.toBeInTheDocument());
    expect(screen.queryByRole('tab', { name: 'Master Supply Room Inventory' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Submitted Inventory Forms' })).not.toBeInTheDocument();
    expect(await screen.findByText('Today tab')).toBeInTheDocument();
  });

  it('opens a request from a unit in the Submitted Inventory Forms tab', async () => {
    const { fetchUnit } = await import('./captain5/ems/supplyData/api.ts');
    const { unitReport } = await import('./captain5/ems/supplyData/testData.ts');
    vi.mocked(fetchUnit).mockResolvedValue(unitReport());
    const user = userEvent.setup();
    renderPage('/daily/captain-5?tab=supply-data&unit=unit:45');
    await user.click(await screen.findByRole('button', { name: /MIR-000004/ }));
    expect(await screen.findByRole('tab', { name: /Submitted Inventory Forms/ })).toHaveAttribute('aria-selected', 'true');
    expect(await screen.findByText(/Forms tab 6f1f2c1e-1111-4222-8333-444455556666/)).toBeInTheDocument();
  });
});
