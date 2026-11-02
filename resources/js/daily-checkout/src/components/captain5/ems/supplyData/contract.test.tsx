import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { call } from '../../../../utils/emsApi';
import SupplyDataTab from './SupplyDataTab';
import { fetchItem, fetchOverview, fetchUnit } from './api.ts';
import { CONTRACT_ITEM, CONTRACT_OVERVIEW, CONTRACT_UNIT } from './contractExamples.ts';
import { notCountedRecently } from './StockList';
import { overview } from './testData.ts';

/**
 * Contract v2 (docs/ems/supply-data-contract.md): the examples in contractExamples.ts are typed (so tsc fails when the types drift from the
 * contract) and pass through the real client and the real screen here. Only the shared `call` is replaced, with the standard envelope's `data`.
 */
vi.mock('../../../../utils/emsApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../../../utils/emsApi')>()),
  call: vi.fn(),
}));

const callMock = vi.mocked(call);
const answer = (data: unknown) => ({ data: data as never, meta: {} });
const SELECTION = { period: '30d' as const, from: null, to: null };

beforeEach(() => { callMock.mockReset(); });

describe('the client accepts the contract examples unchanged', () => {
  it('overview', async () => {
    callMock.mockResolvedValue(answer(CONTRACT_OVERVIEW));
    expect(await fetchOverview(SELECTION)).toEqual(CONTRACT_OVERVIEW);
    expect(callMock).toHaveBeenCalledWith('/supply-data?period=30d&limit=50');
  });

  it('one supply', async () => {
    callMock.mockResolvedValue(answer(CONTRACT_ITEM));
    expect(await fetchItem(12, SELECTION)).toEqual(CONTRACT_ITEM);
  });

  it('one unit', async () => {
    callMock.mockResolvedValue(answer(CONTRACT_UNIT));
    expect(await fetchUnit('unit:45', SELECTION)).toEqual(CONTRACT_UNIT);
  });

  it('a unit page for the unassigned key', async () => {
    const unassigned = { ...CONTRACT_UNIT, unit: { unit_key: 'unassigned', apparatus_id: null, label: 'Unit no longer on file', labels: [], kind: 'unassigned' as const } };
    callMock.mockResolvedValue(answer(unassigned));
    expect((await fetchUnit('unassigned', SELECTION)).unit.kind).toBe('unassigned');
    expect(callMock).toHaveBeenCalledWith('/supply-data/units/unassigned?period=30d&limit=50');
  });
});

describe('the screen shows the contract examples', () => {
  const open = (entry: string) => {
    const user = userEvent.setup();
    render(
      <MemoryRouter initialEntries={[entry]}>
        <Routes><Route path="*" element={<SupplyDataTab canOpenRequests onForbidden={() => undefined} />} /></Routes>
      </MemoryRouter>,
    );
    return user;
  };

  beforeEach(() => {
    callMock.mockImplementation(async (path: string) => {
      if (path.startsWith('/supply-data/items/')) return answer(CONTRACT_ITEM);
      if (path.startsWith('/supply-data/units/')) return answer(CONTRACT_UNIT);
      return answer(CONTRACT_OVERVIEW);
    });
  });

  it('the overview, with no near-minimum filter and the one real order unit', async () => {
    const user = open('/daily/captain-5?tab=supply-data&explore=stock');
    const list = await screen.findByRole('list', { name: 'Stock' });
    expect(screen.queryByRole('button', { name: /near minimum/i })).not.toBeInTheDocument();
    expect(within(list).getByText(/Needs reorder: order 6 cases/)).toBeInTheDocument();
    expect(screen.getByTestId('attention-below_minimum')).toHaveTextContent('3');
    expect(screen.getByRole('button', { name: /^Not counted in 10 days \(0\)/ })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /^Count accuracy/ }));
    expect(screen.getByRole('list', { name: 'Counted against expected' })).toHaveTextContent('Counted 85 of an expected 90, off by 5 short');
    await user.click(screen.getByRole('button', { name: /^Data quality/ }));
    expect(screen.getByTestId('quality-suspects')).toHaveTextContent('Disposable Razors: 341 cases, 341 times its order level');
    await user.click(screen.getByRole('button', { name: /^Fulfillment/ }));
    expect(screen.getByTestId('fulfillment-scope')).toHaveTextContent('7 forms were submitted in all');
  });

  it('one supply shows a single minimum / order level, never two copies', async () => {
    open('/daily/captain-5?tab=supply-data&item=12');
    const balance = await screen.findByTestId('item-balance');
    expect(within(balance).getByText('Minimum / order level').parentElement).toHaveTextContent('10 boxes');
    expect(within(balance).queryByText('Order level')).not.toBeInTheDocument();
    expect(within(balance).queryByText('Minimum')).not.toBeInTheDocument();
    expect(screen.getByTestId('item-distribution')).toHaveTextContent('Up 150% from 2 in the previous period');
  });

  it('one unit', async () => {
    open('/daily/captain-5?tab=supply-data&unit=unit:45');
    expect(await screen.findByRole('heading', { name: 'Rescue 2', level: 2 })).toBeInTheDocument();
    expect(screen.getByTestId('unit-top-requested')).toHaveTextContent('Gloves, nitrile (L)');
    expect(screen.getByTestId('unit-top-requested')).toHaveTextContent('4 on 2 requests');
  });

  it('review fix: the request-side total is "recorded as issued", and "distributed" is left to the ledger', async () => {
    const user = open('/daily/captain-5?tab=supply-data');
    await user.click(await screen.findByRole('button', { name: /^Fulfillment/ }));
    const region = screen.getByRole('region', { name: 'Fulfillment' });
    expect(region).toHaveTextContent('were recorded as issued on those requests');
    expect(region).toHaveTextContent('Recorded as issued is what the request forms say');
    expect(region).not.toHaveTextContent('were distributed');
  });

  it('review fix: the reorder and zero-on-hand explanations match what the backend does', async () => {
    open('/daily/captain-5?tab=supply-data');
    const attention = await screen.findByTestId('supply-data-attention');
    expect(attention).toHaveTextContent('Below the order level, so the advisor suggests an order');
    expect(attention).not.toHaveTextContent('At or under the order level');
    expect(attention).toHaveTextContent('counted, calculated or estimated at zero, or below zero');
    expect(attention).not.toHaveTextContent('Counted or calculated as none left');
  });

  it('review fix: a monthly unit page says Monthly pattern, a weekly one Weekly pattern', async () => {
    open('/daily/captain-5?tab=supply-data&unit=unit:45');
    expect(await screen.findByTestId('unit-pattern')).toHaveTextContent('Weekly pattern');
    callMock.mockImplementation(async (path: string) => path.startsWith('/supply-data/units/')
      ? answer({ ...CONTRACT_UNIT, trend: [{ month_start: '2026-10-01', requests: 1, distribution_events: 2 }] })
      : answer(CONTRACT_OVERVIEW));
    cleanup();
    open('/daily/captain-5?tab=supply-data&unit=unit:45');
    expect(await screen.findByTestId('unit-pattern')).toHaveTextContent('Monthly pattern');
  });

  it('review fix: a retired supply and a unit-narrowed page say what they are', async () => {
    callMock.mockImplementation(async (path: string) => path.startsWith('/supply-data/items/')
      ? answer({ ...CONTRACT_ITEM, item: { ...CONTRACT_ITEM.item, active: false }, scope_notes: ['Stock, days of supply and average per day are for the whole supply room.'] })
      : answer(CONTRACT_OVERVIEW));
    open('/daily/captain-5?tab=supply-data&item=12');
    expect(await screen.findByTestId('item-inactive-note')).toHaveTextContent('no longer in use');
    expect(screen.getByTestId('item-scope-note')).toHaveTextContent('whole supply room');
  });

  it('an estimate in the count history is tagged, never shown as counted', async () => {
    callMock.mockImplementation(async (path: string) => path.startsWith('/supply-data/items/') ? answer({ ...CONTRACT_ITEM, counts: [{ counted_at: '2026-10-01T08:00:00-04:00', verified: null, estimated: 341, expected: null, variance: null, variance_pct: null, basis_note: 'An estimate is not a count.' }] }) : answer(CONTRACT_OVERVIEW));
    open('/daily/captain-5?tab=supply-data&item=12');
    const counts = await screen.findByTestId('item-counts');
    expect(counts).toHaveTextContent('Estimate');
    expect(within(counts).getByTestId('estimated-tag')).toBeInTheDocument();
    expect(counts).not.toHaveTextContent('Counted 341');
  });
});

describe('the tap-through lists show exactly the rows each count adds up', () => {
  it('matches every "What matters now" count on a consistent answer', async () => {
    callMock.mockResolvedValue(answer(overview()));
    const user = userEvent.setup();
    render(
      <MemoryRouter initialEntries={['/daily/captain-5?tab=supply-data']}>
        <Routes><Route path="*" element={<SupplyDataTab canOpenRequests onForbidden={() => undefined} />} /></Routes>
      </MemoryRouter>,
    );
    const tiles: Array<[string, RegExp]> = [
      ['below_minimum', /^Below minimum: 1 supply/], ['zero_stock', /^Zero on hand: 1 supply/], ['estimated', /^Estimated — not yet counted: 1 supply/], ['needs_reorder', /^Needs reorder: 1 supply/],
    ];
    for (const [key, name] of tiles) {
      const expected = Number((await screen.findByTestId(`attention-${key}`)).textContent?.match(/^\d+/)?.[0]);
      await user.click(screen.getByRole('button', { name }));
      expect(within(screen.getByRole('list', { name: 'Stock' })).getAllByRole('listitem')).toHaveLength(expected);
      await user.click(screen.getByRole('button', { name: /^All \(/ }));
    }
    const stale = Number((await screen.findByTestId('attention-stale')).textContent?.match(/\d+/)?.[0]);
    await user.click(screen.getByRole('button', { name: /oldest count first/ }));
    expect(within(screen.getByRole('list', { name: 'Stock' })).getAllByRole('listitem')).toHaveLength(stale);
  });

  it('counts a supply as not counted recently with no physical count in 10 days, or none at all', () => {
    const now = '2026-11-02T14:00:00-05:00';
    expect(notCountedRecently({ last_counted_at: null }, now)).toBe(true);
    expect(notCountedRecently({ last_counted_at: '2026-10-22T14:00:00-05:00' }, now)).toBe(true);
    expect(notCountedRecently({ last_counted_at: '2026-10-23T14:00:01-05:00' }, now)).toBe(false);
    expect(notCountedRecently({ last_counted_at: '2026-11-01T09:00:00-05:00' }, now)).toBe(false);
  });
});
