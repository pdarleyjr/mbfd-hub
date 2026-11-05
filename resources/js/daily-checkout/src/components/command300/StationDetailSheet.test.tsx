import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '../../utils/command300BoardApi';
import StationDetailSheet from './StationDetailSheet';
import { detail } from './fixtures';
import type { PolledResource } from './useVisiblePoll';
import type { StationDetail } from './types';

const resource = (data: StationDetail | null, extra: Partial<PolledResource<StationDetail>> = {}): PolledResource<StationDetail> => ({
  data, loading: false, error: null, stale: false, refreshing: false, retry: vi.fn(), refresh: vi.fn(), ...extra,
});

const open = (data: StationDetail | null = detail, extra: Partial<PolledResource<StationDetail>> = {}) => render(
  <StationDetailSheet stationId={41} stationName="Station 1" resource={resource(data, extra)} onClose={vi.fn()} returnFocusTo={() => null} />,
);

beforeEach(() => vi.restoreAllMocks());

describe('StationDetailSheet', () => {
  it('shows on shift, truck checkouts, requests and vehicle issues, and offers nothing to change', () => {
    open();
    const sheet = screen.getByTestId('command-station-sheet');
    for (const heading of ['On shift', 'Truck checkouts', 'Station requests', 'Vehicle issues']) {
      expect(within(sheet).getByRole('heading', { name: heading })).toBeInTheDocument();
    }
    expect(within(sheet).getByText('Jane Smith')).toBeInTheDocument();
    expect(within(sheet).getByText('Not submitted yet')).toBeInTheDocument();
    expect(within(sheet).getByText('Latest update:', { exact: false })).toBeInTheDocument();
    expect(within(sheet).getByText('Significant damage')).toBeInTheDocument();
    // Read only: no resolve, edit, save or delete control anywhere.
    expect(screen.queryByRole('button', { name: /^(mark resolved|resolve|edit|save|delete|assign)/i })).not.toBeInTheDocument();
  });

  it('opens a submitted checkout with that vehicle\'s issues, from the 300 route', async () => {
    const fetchCheckout = vi.spyOn(api, 'fetchCommandCheckout').mockResolvedValue({
      id: 900, apparatus_id: 1, designation: 'E1', type: 'Engine', submitted_by: 'Captain Sam Lee', shift: 'A', submitted_at: '2026-11-05T13:00:00Z', status: 'Approved', needs_attention: false,
      compartments: [{ name: 'Cab', items: [{ name: 'Mirror', status: 'damaged', notes: 'Cracked', has_photo: false }] }],
      issues: { defect_count: 1, ticket_count: 0, tickets: [], defects: [{ id: 3, compartment: 'Cab', item: 'Mirror', issue_type: 'damaged', severity: 'routine', reported_date: '2026-11-05', notes: null, from_this_checkout: true }] },
    });
    open();

    await userEvent.click(screen.getByRole('button', { name: /E1/ }));

    const issues = await screen.findByTestId('command-checkout-issues');
    expect(fetchCheckout).toHaveBeenCalledWith(41, 900);
    expect(within(issues).getByText('Reported in this checkout')).toBeInTheDocument();
    expect(screen.getByTestId('station-checkout-detail')).toBeInTheDocument();
  });

  it('opens a request read only, with its update history', async () => {
    open();
    await userEvent.click(screen.getByTestId('command-request-row'));
    const sheet = await screen.findByTestId('station-request-sheet');
    expect(within(sheet).getByText('Looking into it')).toBeInTheDocument();
    expect(within(sheet).queryByTestId('station-request-mark-resolved')).not.toBeInTheDocument();
  });

  it('says the roster is not available yet', () => {
    open({ ...detail, crew: { ...detail.crew, available: false, message: 'Roster is being reviewed.', units: [] } });
    expect(screen.getByText('Roster not available yet')).toBeInTheDocument();
    expect(screen.getByText('Roster is being reviewed.')).toBeInTheDocument();
  });

  it('shows a retryable error when the station cannot be loaded', async () => {
    const retry = vi.fn();
    open(null, { error: 'Boom', retry });
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(retry).toHaveBeenCalled();
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Boom'));
  });
});
