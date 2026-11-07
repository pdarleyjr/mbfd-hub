import { describe, expect, it } from 'vitest';
import {
  correctionErrorMessage,
  emptyCorrectionForm,
  markerForItem,
  unlistedMarkers,
  unsentFor,
  validateCorrection,
  type CorrectionForm,
  type CorrectionMarker,
} from './inventoryCorrections';

const form = (patch: Partial<CorrectionForm>): CorrectionForm => ({ ...emptyCorrectionForm(2), ...patch });

describe('inventory correction validation', () => {
  it('refuses < and > before sending, as the server does', () => {
    expect(validateCorrection('new_item', form({ name: 'Hose <b>', manufacturer: 'A>B' }))).toEqual({
      name: 'Remove the < and > characters.',
      manufacturer: 'Remove the < and > characters.',
    });
    expect(validateCorrection('item', form({ reason: 'moved', toCompartmentId: '__elsewhere__', toLocationText: 'Rear <cab>' }))).toEqual({
      toLocationText: 'Remove the < and > characters.',
    });
  });

  it('asks for a quantity that differs from what the checklist already says', () => {
    expect(validateCorrection('item', form({ reason: 'wrong_quantity', quantity: '2' }), 2).quantity).toMatch(/already says 2/);
    expect(validateCorrection('item', form({ reason: 'wrong_quantity', quantity: '3' }), 2)).toEqual({});
    expect(validateCorrection('item', form({ reason: 'wrong_quantity', quantity: '0' }), 2).quantity).toMatch(/whole number/);
  });
});

describe('inventory correction errors', () => {
  it('tells the member to reload when the checklist changed, instead of retrying forever', () => {
    const message = correctionErrorMessage(new Error('HTTP 409: This apparatus\'s checklist changed since you opened it. Reload the checklist and report the correction again.'));
    expect(message).toMatch(/reload the page/);
    expect(message).not.toMatch(/try again/);
  });

  it('does not suggest editing the report when a limit was reached', () => {
    const message = correctionErrorMessage(new Error('HTTP 422: You have reached today\'s limit for inventory corrections. Contact Logistics directly.'));
    expect(message).toMatch(/Nothing was rejected/);
    expect(message).not.toMatch(/change it and try again/);
  });

  it('keeps the edit-and-retry advice for an ordinary refusal', () => {
    expect(correctionErrorMessage(new Error('HTTP 422: That item is not on this apparatus\'s checklist.'))).toMatch(/change it and try again/);
  });
});

describe('inventory correction markers', () => {
  const markers: CorrectionMarker[] = [
    { compartmentId: 'front_cab', itemId: 'a', kind: 'pending' },
    { compartmentId: 'front_cab', itemId: 'a', kind: 'unsent', queueId: 7, error: 'Checklist changed' },
    { compartmentId: 'front_cab', itemId: 'b', kind: 'question', correctionId: 3 },
    { compartmentId: 'front_cab', itemId: null, kind: 'pending' },
    { compartmentId: 'front_cab', itemId: null, kind: 'unsent', queueId: 8 },
  ];

  it('shows a refused report ahead of a plain pending one, and a question ahead of both', () => {
    expect(markerForItem(markers, 'front_cab', 'a')).toBe('unsent');
    expect(markerForItem(markers, 'front_cab', 'b')).toBe('question');
    expect(markerForItem(markers, 'rear_cab', 'a')).toBeNull();
    expect(unsentFor(markers, 'front_cab', 'a')?.queueId).toBe(7);
  });

  it('does not count a refused unlisted-item report as pending review', () => {
    expect(unlistedMarkers(markers, 'front_cab')).toEqual({ pending: 1, question: false });
  });
});
