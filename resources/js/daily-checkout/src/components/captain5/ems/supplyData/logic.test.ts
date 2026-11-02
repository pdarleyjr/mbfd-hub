import { beforeEach, describe, expect, it, vi } from 'vitest';
import { call } from '../../../../utils/emsApi';
import { fetchItem, fetchOverview, fetchUnit } from './api.ts';
import { changeText, daysOfSupplyText, formatNumber, quantityText, unitWord } from './format.ts';
import { customRangeProblem, exportHref, parseView, periodParams, periodQuery, selectionReady } from './params.ts';

vi.mock('../../../../utils/emsApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../../../utils/emsApi')>()),
  call: vi.fn(),
}));

const callMock = vi.mocked(call);

describe('supply data client', () => {
  beforeEach(() => { callMock.mockReset().mockResolvedValue({ data: {}, meta: {} }); });

  it('asks the overview endpoint for the period, with dates only for a custom range', async () => {
    await fetchOverview({ period: '90d', from: null, to: null });
    expect(callMock).toHaveBeenLastCalledWith('/supply-data?period=90d&limit=50');
    await fetchOverview({ period: 'custom', from: '2026-09-01', to: '2026-09-30' });
    expect(callMock).toHaveBeenLastCalledWith('/supply-data?period=custom&from=2026-09-01&to=2026-09-30&limit=50');
    // dates from an earlier custom choice never leak into a preset period
    await fetchOverview({ period: '12m', from: '2026-09-01', to: '2026-09-30' });
    expect(callMock).toHaveBeenLastCalledWith('/supply-data?period=12m&limit=50');
  });

  it('encodes the unit key and uses the item and unit routes', async () => {
    await fetchItem(12, { period: '30d', from: null, to: null });
    expect(callMock).toHaveBeenLastCalledWith('/supply-data/items/12?period=30d');
    await fetchUnit('unit:45', { period: '30d', from: null, to: null });
    expect(callMock).toHaveBeenLastCalledWith('/supply-data/units/unit%3A45?period=30d&limit=50');
    await fetchUnit('detail', { period: '30d', from: null, to: null });
    expect(callMock).toHaveBeenLastCalledWith('/supply-data/units/detail?period=30d&limit=50');
  });

  it('only ever reads: every call is a GET with no init', async () => {
    await fetchOverview({ period: '30d', from: null, to: null });
    await fetchItem(1, { period: '30d', from: null, to: null });
    await fetchUnit('unit:1', { period: '30d', from: null, to: null });
    for (const args of callMock.mock.calls) expect(args.length).toBe(1);
  });

  it('fills missing lists with empty ones so the screen never reads undefined', async () => {
    callMock.mockResolvedValue({ data: { label: 'x' }, meta: {} });
    const overview = await fetchOverview({ period: '30d', from: null, to: null });
    expect([overview.trend, overview.top_items, overview.units, overview.stock, overview.data_quality.notes, overview.count_accuracy.items]).toEqual([[], [], [], [], [], []]);
    const item = await fetchItem(1, { period: '30d', from: null, to: null });
    expect([item.trend, item.units, item.replenishment, item.counts, item.movements]).toEqual([[], [], [], [], []]);
    expect(item.movements_truncated).toBe(false);
  });

  it('refuses an answer that is not an object', async () => {
    callMock.mockResolvedValue({ data: undefined as never, meta: {} });
    await expect(fetchOverview({ period: '30d', from: null, to: null })).rejects.toThrow(/cannot read/);
  });
});

describe('address parameters', () => {
  it('reads period, explore, stock filter and drill-down, ignoring anything invalid', () => {
    const view = parseView(new URLSearchParams('period=90d&explore=unit&stock=zero&item=12&unit=unit:45'));
    expect(view).toEqual({ selection: { period: '90d', from: null, to: null }, explore: 'unit', stock: 'zero', itemId: 12, unitKey: 'unit:45' });
    const junk = parseView(new URLSearchParams('period=forever&explore=x&stock=y&item=-3&unit=../etc'));
    expect(junk).toEqual({ selection: { period: '30d', from: null, to: null }, explore: 'supply', stock: 'all', itemId: null, unitKey: null });
    expect(parseView(new URLSearchParams('unit=detail')).unitKey).toBe('detail');
    expect(parseView(new URLSearchParams('unit=unassigned')).unitKey).toBe('unassigned');
  });

  it('keeps custom dates only for a custom period and only when they are real dates', () => {
    expect(parseView(new URLSearchParams('period=custom&from=2026-09-01&to=2026-09-30')).selection).toEqual({ period: 'custom', from: '2026-09-01', to: '2026-09-30' });
    expect(parseView(new URLSearchParams('period=custom&from=2026-02-31&to=nope')).selection).toEqual({ period: 'custom', from: null, to: null });
    expect(parseView(new URLSearchParams('period=30d&from=2026-09-01&to=2026-09-30')).selection).toEqual({ period: '30d', from: null, to: null });
  });

  it('writes the default period as a clean address', () => {
    expect(periodParams({ period: '30d', from: null, to: null })).toEqual({ period: null, from: null, to: null });
    expect(periodParams({ period: 'custom', from: '2026-09-01', to: '2026-09-30' })).toEqual({ period: 'custom', from: '2026-09-01', to: '2026-09-30' });
    expect(periodQuery({ period: '12m', from: null, to: null }).toString()).toBe('period=12m');
  });

  it('checks a custom range the way the server will', () => {
    const today = '2026-11-02';
    expect(customRangeProblem('2026-09-01', '2026-09-30', today)).toBeNull();
    expect(customRangeProblem(null, '2026-09-30', today)).toMatch(/both/);
    expect(customRangeProblem('2026-09-30', '2026-09-01', today)).toMatch(/before/);
    expect(customRangeProblem('2026-09-01', '2026-11-03', today)).toMatch(/future/);
    expect(customRangeProblem('2025-09-01', '2026-11-02', today)).toMatch(/400 days/);
    expect(customRangeProblem('2025-10-30', '2026-11-02', today)).toBeNull();
    expect(selectionReady({ period: 'custom', from: null, to: null }, today)).toBe(false);
    expect(selectionReady({ period: '90d', from: null, to: null }, today)).toBe(true);
  });

  it('builds the export link from the filters in view', () => {
    expect(exportHref({ period: '30d', from: null, to: null })).toBe('/ems/api/supply-data/export.xlsx?period=30d');
    expect(exportHref({ period: 'custom', from: '2026-09-01', to: '2026-09-30' }, { itemId: 12 })).toBe('/ems/api/supply-data/export.xlsx?period=custom&from=2026-09-01&to=2026-09-30&item=12');
    expect(exportHref({ period: '90d', from: null, to: null }, { unitKey: 'unit:45' })).toBe('/ems/api/supply-data/export.xlsx?period=90d&unit=unit%3A45');
  });
});

describe('wording', () => {
  const base = { distribution_events: 12, previous_distribution_events: 9, change_pct: 33.3, items_distributed: 0, units_supplied: 0, requests_submitted: 0, requests_reconciled: 0 };

  it('compares with the previous period, or says there is nothing to compare', () => {
    expect(changeText(base).text).toBe('Up 33.3% from 9 in the previous period');
    expect(changeText({ ...base, change_pct: -25, previous_distribution_events: 16 }).text).toBe('Down 25% from 16 in the previous period');
    expect(changeText({ ...base, change_pct: 0 }).text).toBe('No change from 9 in the previous period');
    expect(changeText({ ...base, change_pct: null, previous_distribution_events: 0 })).toEqual({ text: 'No earlier activity to compare', tone: 'none' });
  });

  it('keeps zero as zero and writes units in plain words', () => {
    expect(formatNumber(0)).toBe('0');
    expect(formatNumber(2.04)).toBe('2');
    expect(formatNumber(2.46)).toBe('2.5');
    expect(quantityText(0, 'box')).toBe('0 boxes');
    expect(quantityText(1, 'box')).toBe('1 box');
    expect(quantityText(14, 'each')).toBe('14');
    expect(unitWord('bag', 3)).toBe('bags');
    expect(daysOfSupplyText(0.4)).toBe('less than a day');
    expect(daysOfSupplyText(1)).toBe('1 day');
    expect(daysOfSupplyText(46.7)).toBe('47 days');
  });
});
