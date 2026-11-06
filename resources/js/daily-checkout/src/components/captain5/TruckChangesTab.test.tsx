import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { parseSummary } from '../changeout/changeoutParse';
import TruckChangesTab from './TruckChangesTab';
import type { RescueTruckChange } from './types';

const change = (extra: Partial<RescueTruckChange> = {}): RescueTruckChange => ({
  id: 'changeout:1', unit: 'R 44', from_vehicle_number: '17503', to_vehicle_number: '1033', at: '2026-11-05T14:00:00Z', by: 'Lt. Alvarez',
  recent: true, note: null, left_behind: [], without_replacement: false, ...extra,
});

describe('a unit kept on its truck with no replacement', () => {
  it('reads as kept on the truck, never as a move from a truck to itself', () => {
    render(<TruckChangesTab trucks={[]} changes={[
      change({ id: 'changeout:2', from_vehicle_number: '1033', to_vehicle_number: '1033', without_replacement: true }),
      change(),
    ]} />);

    expect(screen.getByText('R 44 kept on truck 1033 (no replacement available)')).toBeTruthy();
    expect(screen.getByText('R 44: truck 17503 to truck 1033')).toBeTruthy();
    expect(screen.queryByText('R 44: truck 1033 to truck 1033')).toBeNull();
  });

  it('carries the flag on a changeout record and treats a missing flag as a move', () => {
    expect(parseSummary({ changeout: { id: 7, without_replacement: true } }).without_replacement).toBe(true);
    expect(parseSummary({ changeout: { id: 8 } }).without_replacement).toBe(false);
  });
});
