import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EmsApiError } from '../../../../utils/emsApi';
import SupplyDataTab from './SupplyDataTab';
import { fetchItem, fetchOverview, fetchUnit } from './api.ts';
import { itemReport, LABEL, overview, sparseOverview, stockRow, unitReport } from './testData.ts';

vi.mock('./api.ts', () => ({ fetchOverview: vi.fn(), fetchItem: vi.fn(), fetchUnit: vi.fn() }));

const overviewMock = vi.mocked(fetchOverview);
const itemMock = vi.mocked(fetchItem);
const unitMock = vi.mocked(fetchUnit);

const REQUEST_UUID = '6f1f2c1e-1111-4222-8333-444455556666';
const THIRTY_DAYS = { period: '30d', from: null, to: null };

function Probe() {
  const location = useLocation();
  return <p data-testid="location">{location.search}</p>;
}

function renderTab(entry = '/daily/captain-5?tab=supply-data', props: { canOpenRequests?: boolean } = {}) {
  const onForbidden = vi.fn();
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="*" element={<><SupplyDataTab canOpenRequests={props.canOpenRequests ?? true} onForbidden={onForbidden} /><Probe /></>} />
      </Routes>
    </MemoryRouter>,
  );
  return { onForbidden, user: userEvent.setup() };
}

const search = () => screen.getByTestId('location').textContent ?? '';

beforeEach(() => {
  overviewMock.mockReset().mockResolvedValue(overview());
  itemMock.mockReset().mockResolvedValue(itemReport());
  unitMock.mockReset().mockResolvedValue(unitReport());
});

describe('Supply Data tab: what matters now', () => {
  it('loads from the overview endpoint and shows the four indicators from attention', async () => {
    renderTab();
    expect(await screen.findByRole('heading', { name: 'Supply Data', level: 2 })).toBeInTheDocument();
    expect(overviewMock).toHaveBeenCalledWith(THIRTY_DAYS);

    const section = screen.getByTestId('supply-data-attention');
    expect(within(section).getByRole('button', { name: 'Below minimum: 3 supplies. Show these supplies.' })).toHaveTextContent('3');
    expect(within(section).getByRole('button', { name: 'Zero on hand: 1 supply. Show these supplies.' })).toHaveTextContent('1');
    expect(within(section).getByRole('button', { name: 'Estimated — not yet counted: 44 supplies. Show these supplies.' })).toHaveTextContent('44');
    expect(within(section).getByRole('button', { name: 'Needs reorder: 4 supplies. Show these supplies.' })).toHaveTextContent('4');
    expect(screen.getByTestId('attention-stale')).toHaveTextContent('50 supplies have not been counted recently.');
  });

  it('opens the Stock list already filtered when an indicator is tapped', async () => {
    const { user } = renderTab();
    await user.click(await screen.findByRole('button', { name: /^Below minimum: 3 supplies/ }));

    expect(search()).toContain('explore=stock');
    expect(search()).toContain('stock=below_minimum');
    const list = screen.getByRole('list', { name: 'Stock' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    expect(within(list).getByText('Gauze 4x4')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Below minimum \(1\)/ })).toHaveAttribute('aria-pressed', 'true');
  });
});

describe('Supply Data tab: honest numbers', () => {
  it('shows 0 as 0 and null as a dash that is read as "Not enough data"', async () => {
    overviewMock.mockResolvedValue(overview({ attention: { below_minimum: 0, zero_stock: 0, estimated: 44, not_counted_recently: 0, needs_reorder: 0 } }));
    const { user } = renderTab();
    const zero = await screen.findByTestId('attention-zero_stock');
    expect(within(zero).getByText('0')).toBeInTheDocument();
    expect(screen.getByTestId('attention-stale')).toHaveTextContent('Every supply has been counted recently.');

    await user.click(screen.getByRole('button', { name: /^Fulfillment/ }));
    const body = screen.getByRole('region', { name: 'Fulfillment' });
    // corrected is a real zero; the typical time to reconcile cannot be determined.
    expect(within(body).getByText('Corrected').parentElement).toHaveTextContent('Corrected0');
    const median = within(body).getByText('Typical time to reconcile').parentElement as HTMLElement;
    expect(median).toHaveTextContent('Not enough data');
    expect(within(median).getByText('—')).toHaveAttribute('aria-hidden', 'true');
  });

  it('tags every estimated balance as Estimated and never calls it verified', async () => {
    const { user } = renderTab('/daily/captain-5?tab=supply-data&explore=stock');
    const list = await screen.findByRole('list', { name: 'Stock' });
    expect(within(list).getAllByTestId('estimated-tag')).toHaveLength(1);
    expect(within(within(list).getByText('Gauze 4x4').closest('li') as HTMLElement).getByTestId('estimated-tag')).toHaveTextContent('Estimated');

    itemMock.mockResolvedValue(itemReport({ item: { ...itemReport().item, item_id: 20, name: 'Gauze 4x4', count_unit: 'each' }, balance: { ...itemReport().balance, basis: 'estimate', on_hand: 5 } }));
    await user.click(within(list).getByText('Gauze 4x4'));
    const balance = await screen.findByTestId('item-balance');
    expect(within(balance).getByTestId('estimated-tag')).toBeInTheDocument();
    expect(within(balance).getByTestId('item-estimate-note')).toHaveTextContent(/not a verified count/i);
  });

  it('leaves out days of supply when the API cannot work it out', async () => {
    renderTab('/daily/captain-5?tab=supply-data&explore=stock');
    const list = await screen.findByRole('list', { name: 'Stock' });
    const gloves = within(list).getByText('Gloves, nitrile (L)').closest('li') as HTMLElement;
    expect(gloves).toHaveTextContent('About 47 days');
    const gauze = within(list).getByText('Gauze 4x4').closest('li') as HTMLElement;
    expect(gauze).not.toHaveTextContent(/days at the recent/);
  });

  it('looks intentional and honest with almost no distribution history', async () => {
    overviewMock.mockResolvedValue(sparseOverview());
    renderTab();
    expect(await screen.findByText('Nothing has been distributed in this period yet')).toBeInTheDocument();
    expect(screen.getByTestId('headline-events')).toHaveTextContent('0');
    expect(screen.getByTestId('headline-change')).toHaveTextContent('No earlier activity to compare');
    expect(screen.getByTestId('supply-data-label')).toHaveTextContent(LABEL);
    expect(screen.getByTestId('trend-thin')).toHaveTextContent('This is a real zero, not missing data.');
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.getByText('No supplies were distributed in this period')).toBeInTheDocument();
    const text = document.body.textContent ?? '';
    expect(text).not.toMatch(/NaN|undefined|\bnull\b/);
    expect(text).not.toMatch(/\b(used|consumed|consumption|usage)\b/i);
  });

  it('says the headline change in words, or that there is nothing to compare', async () => {
    renderTab();
    expect(await screen.findByTestId('headline-change')).toHaveTextContent('Up 33.3% from 9 in the previous period');
    expect(screen.getByTestId('supply-data-label')).toHaveTextContent(LABEL);
  });
});

describe('Supply Data tab: the trend chart', () => {
  it('has a text alternative and a numbers table', async () => {
    const { user } = renderTab();
    const chart = await screen.findByRole('img', { name: 'Distribution events by week' });
    expect(chart).toHaveAccessibleDescription('12 distribution events over 4 weeks. The most was 5, in the week of Oct 18.');

    const toggle = screen.getByRole('button', { name: 'Show numbers' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await user.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    const table = screen.getByRole('table', { name: 'Distribution events by week' });
    expect(within(table).getAllByRole('row')).toHaveLength(5);
    expect(within(table).getByRole('row', { name: /Week of Oct 18\s*5\s*3/ })).toBeInTheDocument();
    expect(within(table).getByRole('row', { name: /Week of Oct 11\s*0\s*0/ })).toBeInTheDocument();
  });
});

describe('Supply Data tab: period', () => {
  it('asks again with the chosen period and keeps it in the address', async () => {
    const { user } = renderTab();
    await screen.findByRole('heading', { name: 'Supply Data' });
    await user.click(screen.getByRole('button', { name: 'Last 90 days' }));
    await waitFor(() => expect(overviewMock).toHaveBeenLastCalledWith({ period: '90d', from: null, to: null }));
    expect(search()).toContain('period=90d');
    expect(await screen.findByRole('button', { name: 'Last 90 days' })).toHaveAttribute('aria-pressed', 'true');

    await user.click(screen.getByRole('button', { name: 'Last 30 days' }));
    await waitFor(() => expect(overviewMock).toHaveBeenLastCalledWith(THIRTY_DAYS));
    expect(search()).not.toContain('period=');
  });

  it('asks for a bounded custom range only after the dates are valid and applied', async () => {
    const { user } = renderTab();
    await screen.findByRole('heading', { name: 'Supply Data' });
    await user.click(screen.getByRole('button', { name: 'Custom' }));
    const form = await screen.findByRole('form', { name: 'Custom date range' });
    const from = within(form).getByLabelText('From');
    const to = within(form).getByLabelText('To');
    expect(to).toHaveAttribute('max');

    const callsBefore = overviewMock.mock.calls.length;
    fireEvent.change(from, { target: { value: '2020-03-01' } });
    fireEvent.change(to, { target: { value: '2020-02-01' } });
    await user.click(within(form).getByRole('button', { name: 'Show these dates' }));
    expect(within(form).getByRole('alert')).toHaveTextContent('The end date cannot be before the start date.');
    expect(overviewMock.mock.calls.length).toBe(callsBefore);

    fireEvent.change(to, { target: { value: '2020-04-01' } });
    await user.click(within(form).getByRole('button', { name: 'Show these dates' }));
    await waitFor(() => expect(overviewMock).toHaveBeenLastCalledWith({ period: 'custom', from: '2020-03-01', to: '2020-04-01' }));
    expect(search()).toContain('period=custom');
    expect(search()).toContain('from=2020-03-01');
    expect(search()).toContain('to=2020-04-01');
  });

  it('refuses a range that ends in the future or runs over 400 days, without asking the server', async () => {
    const { user } = renderTab('/daily/captain-5?tab=supply-data&period=custom&from=2020-01-01&to=2020-02-01');
    const form = await screen.findByRole('form', { name: 'Custom date range' });
    const callsBefore = overviewMock.mock.calls.length;
    fireEvent.change(within(form).getByLabelText('To'), { target: { value: '2099-01-01' } });
    await user.click(within(form).getByRole('button', { name: 'Show these dates' }));
    expect(within(form).getByRole('alert')).toHaveTextContent('The end date cannot be in the future.');
    fireEvent.change(within(form).getByLabelText('To'), { target: { value: '2021-06-01' } });
    await user.click(within(form).getByRole('button', { name: 'Show these dates' }));
    expect(within(form).getByRole('alert')).toHaveTextContent('Choose a range of 400 days or fewer.');
    expect(overviewMock.mock.calls.length).toBe(callsBefore);
  });

  it('offers the workbook for the same period as a plain download link', async () => {
    renderTab('/daily/captain-5?tab=supply-data&period=90d');
    const link = await screen.findByTestId('supply-data-export');
    expect(link).toHaveAttribute('href', '/ems/api/supply-data/export.xlsx?period=90d');
    expect(link).toHaveAttribute('download');
    expect(link).toHaveTextContent('Export to Excel');
  });
});

describe('Supply Data tab: drill-downs', () => {
  it('opens a supply and Back returns to the overview with the same period, without asking again', async () => {
    const { user } = renderTab('/daily/captain-5?tab=supply-data&period=90d&explore=stock');
    const list = await screen.findByRole('list', { name: 'Stock' });
    const row = within(list).getByText('Gloves, nitrile (L)').closest('button') as HTMLElement;
    await user.click(row);

    expect(itemMock).toHaveBeenCalledWith(12, { period: '90d', from: null, to: null });
    expect(await screen.findByRole('heading', { name: 'Gloves, nitrile (L)', level: 2 })).toHaveFocus();
    expect(search()).toContain('item=12');
    expect(search()).toContain('period=90d');
    expect(screen.queryByRole('heading', { name: 'Supply Data' })).not.toBeInTheDocument();
    expect(within(screen.getByTestId('supply-data-detail')).getByTestId('supply-data-label')).toHaveTextContent(LABEL);

    // What the drill-down shows: balance with basis, distribution, receiving units with share numbers, restocks, counts, movements.
    const balance = screen.getByTestId('item-balance');
    expect(within(balance).getByTestId('item-on-hand')).toHaveTextContent('14 boxes');
    expect(within(balance).getByText('Minimum').parentElement).toHaveTextContent('No minimum set');
    expect(within(balance).getByText('Taken out since the count').parentElement).toHaveTextContent('Taken out since the count0');
    expect(screen.getByTestId('item-distribution')).toHaveTextContent('2 in the previous period');
    expect(within(screen.getByTestId('item-units')).getByText('55.6%')).toBeInTheDocument();
    expect(screen.getByTestId('item-replenishment')).toHaveTextContent('Order received');
    expect(screen.getByTestId('item-counts')).toHaveTextContent('Compared with an estimate');

    await user.click(screen.getByRole('button', { name: 'Back to Supply Data' }));
    expect(await screen.findByRole('heading', { name: 'Supply Data', level: 2 })).toBeInTheDocument();
    expect(search()).not.toContain('item=');
    expect(search()).toContain('period=90d');
    expect(screen.getByRole('button', { name: 'Last 90 days' })).toHaveAttribute('aria-pressed', 'true');
    expect(overviewMock).toHaveBeenCalledTimes(1);
    expect(row).toHaveFocus();
  });

  it('opens a unit with its patterns and request history, and each request opens in Submitted Inventory Forms', async () => {
    const { user } = renderTab('/daily/captain-5?tab=supply-data&explore=unit');
    const list = await screen.findByRole('list', { name: 'Units' });
    expect(within(list).getByText('No requests in this period')).toBeInTheDocument();
    await user.click(within(list).getByText('RESCUE R2'));

    expect(unitMock).toHaveBeenCalledWith('unit:45', THIRTY_DAYS);
    expect(await screen.findByRole('heading', { name: 'RESCUE R2', level: 2 })).toBeInTheDocument();
    expect(search()).toContain('unit=unit%3A45');
    expect(screen.getByTestId('unit-top-requested')).toHaveTextContent('Gauze 4x4');
    expect(screen.getByTestId('unit-top-distributed')).toHaveTextContent('Gloves, nitrile (L)');
    expect(screen.getByRole('img', { name: 'Distribution events by week' })).toBeInTheDocument();

    await user.click(within(screen.getByTestId('unit-requests')).getByRole('button', { name: /MIR-000004/ }));
    expect(search()).toContain('tab=rescue');
    expect(search()).toContain(`request=${REQUEST_UUID}`);
    expect(search()).not.toContain('unit=');
    expect(search()).not.toContain('explore=');
  });

  it('shows the request history as plain rows for someone who cannot open requests', async () => {
    renderTab('/daily/captain-5?tab=supply-data&unit=unit:45', { canOpenRequests: false });
    const requests = await screen.findByTestId('unit-requests');
    expect(within(requests).getByText('MIR-000004')).toBeInTheDocument();
    expect(within(requests).queryByRole('button')).not.toBeInTheDocument();
  });

  it('goes back to the unit when a supply was opened from it', async () => {
    const { user } = renderTab('/daily/captain-5?tab=supply-data&unit=unit:45');
    await user.click(await screen.findByRole('button', { name: /Gloves, nitrile \(L\)/ }));
    expect(await screen.findByRole('heading', { name: 'Gloves, nitrile (L)', level: 2 })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Back to unit' }));
    expect(await screen.findByRole('heading', { name: 'RESCUE R2', level: 2 })).toBeInTheDocument();
    expect(search()).toContain('unit=');
    expect(search()).not.toContain('item=');
  });
});

describe('Supply Data tab: failures', () => {
  it('shows an error with Retry, then the data once Retry works', async () => {
    overviewMock.mockReset()
      .mockRejectedValueOnce(new EmsApiError('The server had a problem and nothing was saved. Try again in a moment.', 500))
      .mockResolvedValue(overview());
    const { user } = renderTab();
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Supply Data could not be loaded');
    expect(alert).toHaveTextContent('The server had a problem');
    // The period picker stays usable while the data is unavailable.
    expect(screen.getByRole('button', { name: 'Last 90 days' })).toBeInTheDocument();

    await user.click(within(alert).getByRole('button', { name: 'Retry' }));
    expect(await screen.findByTestId('supply-data-attention')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(overviewMock).toHaveBeenCalledTimes(2);
  });

  it('shows a drill-down error with Retry and a Back button', async () => {
    itemMock.mockReset().mockRejectedValueOnce(new EmsApiError('That record could not be found.', 404)).mockResolvedValue(itemReport());
    const { user } = renderTab('/daily/captain-5?tab=supply-data&item=12');
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('This supply could not be loaded');
    expect(screen.getByRole('button', { name: 'Back to Supply Data' })).toBeInTheDocument();
    await user.click(within(alert).getByRole('button', { name: 'Retry' }));
    expect(await screen.findByRole('heading', { name: 'Gloves, nitrile (L)', level: 2 })).toBeInTheDocument();
  });

  it('hands a 403 to the page, which hides the EMS tabs, and shows no error of its own', async () => {
    overviewMock.mockReset().mockRejectedValue(new EmsApiError('Your account does not have access to do that.', 403));
    const { onForbidden } = renderTab();
    await waitFor(() => expect(onForbidden).toHaveBeenCalledTimes(1));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('hands a 403 from a drill-down to the page as well', async () => {
    itemMock.mockReset().mockRejectedValue(new EmsApiError('No', 403));
    const { onForbidden } = renderTab('/daily/captain-5?tab=supply-data&item=12');
    await waitFor(() => expect(onForbidden).toHaveBeenCalledTimes(1));
  });
});

describe('Supply Data tab: fields the API sends beyond the contract', () => {
  it('reads the counted number as counted and lists extra data-quality counts only when above zero', async () => {
    overviewMock.mockResolvedValue(overview({
      count_accuracy: { comparable_counts: 1, coverage_pct: 2.5, note: null, items: [{ item_id: 12, name: 'Gloves, nitrile (L)', counted: 14, expected: 15, variance: -1, variance_pct: -6.7, counted_at: '2026-10-27T09:00:00-04:00' }] },
      data_quality: { unattributed_movements: 0, estimated_items: 44, unresolved_conversions: 0, notes: [], negative_balances: 2, items_without_minimum: 0 },
      scope_notes: ['The unit filter narrows requests and distribution.'],
    }));
    const { user } = renderTab();
    expect(await screen.findByText('The unit filter narrows requests and distribution.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /^Count accuracy/ }));
    expect(screen.getByRole('list', { name: 'Counted against expected' })).toHaveTextContent('Counted 14 of an expected 15, off by 1 short');
    await user.click(screen.getByRole('button', { name: /^Data quality/ }));
    const extras = screen.getByTestId('quality-extras');
    expect(extras).toHaveTextContent('Balances below zero2');
    expect(extras).not.toHaveTextContent('no minimum');
  });

  it('opens the unit that holds requests whose truck is no longer on file', async () => {
    unitMock.mockResolvedValue(unitReport({ unit: { unit_key: 'unassigned', apparatus_id: null, label: 'Unit no longer on file', kind: 'unassigned' } }));
    renderTab('/daily/captain-5?tab=supply-data&unit=unassigned');
    expect(await screen.findByRole('heading', { name: 'Unit no longer on file', level: 2 })).toBeInTheDocument();
    expect(unitMock).toHaveBeenCalledWith('unassigned', THIRTY_DAYS);
  });
});

describe('Supply Data tab: disclosure and stock', () => {
  it('keeps Fulfillment, Count accuracy and Data quality closed until asked for', async () => {
    const { user } = renderTab();
    await screen.findByTestId('supply-data-insights');
    for (const name of ['Fulfillment', 'Count accuracy', 'Data quality']) {
      const button = screen.getByRole('button', { name: new RegExp(`^${name}`) });
      expect(button).toHaveAttribute('aria-expanded', 'false');
    }
    await user.click(screen.getByRole('button', { name: /^Count accuracy/ }));
    expect(screen.getByRole('region', { name: 'Count accuracy' })).toHaveTextContent('No physical count has a verified baseline');
    await user.click(screen.getByRole('button', { name: /^Data quality/ }));
    expect(screen.getByRole('region', { name: 'Data quality' })).toHaveTextContent('44 balances are estimates until counted.');
  });

  it('sorts the oldest counts first, never-counted supplies at the top', async () => {
    const { user } = renderTab();
    await user.click(await screen.findByRole('button', { name: 'Show oldest counts first' }));
    const rows = within(screen.getByRole('list', { name: 'Stock' })).getAllByRole('listitem');
    expect(rows[0]).toHaveTextContent('Bandage rolls');
    expect(rows[0]).toHaveTextContent('Never counted');
  });

  it('filters and searches the stock list', async () => {
    const { user } = renderTab('/daily/captain-5?tab=supply-data&explore=stock');
    await screen.findByRole('list', { name: 'Stock' });
    await user.click(screen.getByRole('button', { name: /^Estimated \(1\)/ }));
    expect(within(screen.getByRole('list', { name: 'Stock' })).getAllByRole('listitem')).toHaveLength(1);
    await user.click(screen.getByRole('button', { name: /^All \(4\)/ }));
    fireEvent.change(screen.getByLabelText('Search supplies'), { target: { value: 'epinephrine' } });
    expect(within(screen.getByRole('list', { name: 'Stock' })).getAllByRole('listitem')).toHaveLength(1);
    expect(screen.getByTestId('stock-count')).toHaveTextContent('1 supply');
  });

  it('lists supplies by distribution events, not raw quantity', async () => {
    renderTab();
    const list = await screen.findByTestId('by-supply-list');
    const rows = within(list).getAllByRole('listitem');
    expect(rows[0]).toHaveTextContent('Gloves, nitrile (L)');
    expect(rows[0]).toHaveTextContent('5 distribution events');
    expect(rows[0]).toHaveTextContent('Up from 2');
    expect(rows[1]).toHaveTextContent('New this period');
  });
});

describe('Supply Data tab: stock rows', () => {
  it('shows a zero on-hand as 0 and a never-counted supply as Not counted', async () => {
    overviewMock.mockResolvedValue(overview({ stock: [stockRow({ item_id: 31, name: 'Epinephrine cases', on_hand: 0, status: 'zero', days_of_supply: null }), stockRow({ item_id: 40, name: 'Bandage rolls', on_hand: null, basis: 'none', status: 'unknown', last_counted_at: null })] }));
    renderTab('/daily/captain-5?tab=supply-data&explore=stock');
    const list = await screen.findByRole('list', { name: 'Stock' });
    const zero = within(list).getByText('Epinephrine cases').closest('li') as HTMLElement;
    expect(within(zero).getByTestId('stock-on-hand')).toHaveTextContent('0 boxes');
    expect(within(zero).getByText('Zero on hand')).toBeInTheDocument();
    const none = within(list).getByText('Bandage rolls').closest('li') as HTMLElement;
    expect(none).toHaveTextContent('Not counted');
  });
});
