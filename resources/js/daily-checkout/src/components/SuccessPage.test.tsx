import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';
import { reportedIssuesFor } from '../utils/receiptIssues';
import type { Defect, InspectionSubmission } from '../types';
import SuccessPage from './SuccessPage';

function renderReceipt(entry: string, state?: Record<string, unknown>) {
  const [pathname, query] = entry.split('?');
  render(
    <MemoryRouter initialEntries={[{ pathname, search: query ? `?${query}` : '', state }]}>
      <SuccessPage />
    </MemoryRouter>,
  );
}

const defect: Defect = { item: 'Spanner wrench', compartment: 'Cab', status: 'Missing' };

describe('SuccessPage receipt', () => {
  it('says only that the inspection was received and recorded, and never claims a reply', () => {
    renderReceipt('/success', { outcome: 'submitted' });

    expect(screen.getByRole('heading', { name: 'Inspection received' })).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Inspection received and recorded.');
    expect(screen.getByRole('status')).not.toHaveTextContent(/support services|replied/i);
    expect(screen.queryByText(/No new issues reported/)).not.toBeInTheDocument();
    expect(screen.queryByText(/sent for follow-up/)).not.toBeInTheDocument();
  });

  it('adds "No new issues reported." when the member reported none', () => {
    renderReceipt('/success', { outcome: 'submitted', issues: 'none' });

    expect(screen.getByRole('status')).toHaveTextContent('Inspection received and recorded. No new issues reported.');
  });

  it('says issues were sent for follow-up, without a count, when the member reported some', () => {
    renderReceipt('/success', { outcome: 'submitted', issues: 'some' });

    expect(screen.getByRole('status')).toHaveTextContent('Inspection received and recorded. Issues you reported were sent for follow-up.');
    expect(screen.getByRole('status')).not.toHaveTextContent(/\d/);
  });

  it('ignores an issues value it does not know', () => {
    renderReceipt('/success', { outcome: 'submitted', issues: '7' });

    expect(screen.getByRole('status')).toHaveTextContent(/^Inspection received and recorded\.$/);
  });

  it('keeps the honest wording for an inspection still saved on the device', () => {
    renderReceipt('/success?queued=true', { outcome: 'queued', queuedSubmissionId: 'q1', issues: 'none' });

    expect(screen.getByRole('heading', { name: 'Inspection saved on this device' })).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('It has not been sent yet.');
    expect(screen.queryByText(/No new issues reported/)).not.toBeInTheDocument();
    expect(screen.queryByText(/received and recorded/)).not.toBeInTheDocument();
  });
});

describe('reportedIssuesFor', () => {
  const base: Pick<InspectionSubmission, 'defects' | 'concerns' | 'review_flag' | 'scheduled_tasks'> = { defects: [] };

  it('is none when nothing was reported', () => {
    expect(reportedIssuesFor(base)).toBe('none');
    expect(reportedIssuesFor({ ...base, concerns: [], scheduled_tasks: [{ id: 't', status: 'Completed' }] })).toBe('none');
  });

  it('is none for a note for the record: it asks for no action and the server files no finding', () => {
    expect(reportedIssuesFor({ ...base, concerns: [{ kind: 'note', description: 'Port rail scratched' } as never] })).toBe('none');
  });

  it('is some for a Missing or Damaged item, a concern, a review flag or an undone duty', () => {
    expect(reportedIssuesFor({ ...base, defects: [defect] })).toBe('some');
    expect(reportedIssuesFor({ ...base, concerns: [{ kind: 'issue', description: 'Noise' } as never] })).toBe('some');
    expect(reportedIssuesFor({ ...base, review_flag: { explanation: 'Look at this', items: [] } })).toBe('some');
    expect(reportedIssuesFor({ ...base, scheduled_tasks: [{ id: 't', status: 'Not Completed' }] })).toBe('some');
  });
});
