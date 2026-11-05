import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { markedPresentNote } from '../../utils/previousFindings';
import { describeRun } from './useIssueClearing';
import { eventTitle } from './IssueTimeline';
import { verificationText } from './format';
import IssueRow from './IssueRow';
import RecentlyResolved from './RecentlyResolved';
import { ACTIONS } from './actionForms';
import type { IssueEvent, IssueSummary } from './types';

const issue = (extra: Partial<IssueSummary> = {}): IssueSummary => ({
  id: 5, kind: 'rescue', apparatus_id: 1, unit: 'Rescue 5', compartment: 'Cab', item: 'Pulse oximeter', issue_type: 'Missing', severity: 'routine',
  action_status: 'reported', action_label: 'New — not yet acknowledged', open: true, details: {}, expected_delivery: null, overdue: false,
  awaiting_verification: false, first_reported_at: '2026-11-04T12:00:00Z', first_reported_by: null, last_observed_at: null, resolved_at: null,
  resolution_type: null, state_token: 'reported:1', ...extra,
});

const event = (type: string, extra: Partial<IssueEvent> = {}): IssueEvent => ({
  id: 1, type, from: null, to: null, actor: 'Pat Lee', at: null, member_message: null, internal_note: null, details: {}, inspection_id: null, ...extra,
});

describe('resolved issue vocabulary', () => {
  it('names every way an issue is reported fixed or resolved in the timeline', () => {
    expect(eventTitle(event('resolved_by_checkout', { from: 'reported', to: 'resolved' }))).toBe('Resolved during checkout');
    expect(eventTitle(event('appears_corrected'))).toBe('Needs verification: a checkout reported it present');
    expect(eventTitle(event('correction_reported'))).toBe('Needs verification: a member reported it fixed');
    expect(eventTitle(event('member_resolved'))).toBe('Resolved by a member');
    expect(eventTitle(event('correction_verified'))).toBe('Correction verified');
    expect(eventTitle(event('cleared_from_captain5'))).toBe('Sent to Logistics by Pat Lee');
  });

  it('says who reported it present or fixed, and when', () => {
    const now = new Date('2026-11-05T14:00:00Z').getTime();
    vi.spyOn(Date, 'now').mockReturnValue(now);
    const waiting = issue({ awaiting_verification: true, verification: { by: 'Sam Doe', at: '2026-11-05T12:00:00Z', via: 'checkout', safety_critical: false } });
    expect(verificationText(waiting)).toBe('Needs verification — Sam Doe reported it present 2 h ago');
    expect(verificationText({ ...waiting, verification: { by: 'Sam Doe', at: null, via: 'report', safety_critical: false } })).toBe('Needs verification — Sam Doe reported it fixed');
    expect(verificationText(issue())).toBeNull();
  });

  it('keeps Send to Logistics apart from clearing a resolved issue', () => {
    expect(ACTIONS.send_to_logistics.label).toBe('Send to Logistics');
    expect(ACTIONS.clear_resolution.label).toBe('Clear from list');
    expect(describeRun('send_to_logistics', 1, { done: 1, changed: 0, failed: 0, refusal: null })).toBe('Sent to Logistics. It stays open with Logistics.');
    expect(describeRun('clear_resolution', 1, { done: 1, changed: 0, failed: 0, refusal: null })).toBe('Cleared from the resolved list.');
  });

  it('tells a member what Present will do', () => {
    expect(markedPresentNote([{ owner: 'captain5', member_correction: { closes_immediately: true } }])).toBe('Marked present. This closes the issue.');
    expect(markedPresentNote([{ owner: 'captain5', member_correction: { closes_immediately: false } }])).toBe('Marked present. Captain 5 will verify it before it is closed.');
    expect(markedPresentNote([{ owner: 'logistics' }])).toBe('Marked present. Support Services will verify it before it is closed.');
  });

  it('marks a waiting issue strongly and states the safety-critical case', () => {
    render(<ul><IssueRow issue={issue({ awaiting_verification: true, verification: { by: 'Sam Doe', at: '2026-11-05T12:00:00Z', via: 'checkout', safety_critical: true } })} onOpen={() => {}} /></ul>);
    const line = screen.getByTestId('captain5-needs-verification');
    expect(line).toHaveTextContent('Needs verification — Sam Doe reported it present');
    expect(line).toHaveTextContent('Safety-critical');
  });
});

describe('Recently resolved', () => {
  const resolved = issue({
    open: false, action_status: 'resolved', action_label: 'Resolved', closure: 'resolved', resolved_at: '2026-11-05T12:00:00Z',
    resolution: { text: 'Resolved by Sam Doe during Rescue 5 checkout · Nov 5, 7:00 AM', by: 'Sam Doe', source: 'checkout' },
  });

  it('shows the resolved-by line and a Clear button that is not an open issue', async () => {
    const onClear = vi.fn();
    render(<RecentlyResolved issues={[resolved]} hours={72} busy={false} onOpen={() => {}} onClear={onClear} />);
    const section = screen.getByTestId('captain5-recently-resolved');
    expect(within(section).getByRole('heading')).toHaveTextContent('Recently resolved (1)');
    expect(within(section).getByTestId('captain5-resolution-line')).toHaveTextContent('Resolved by Sam Doe during Rescue 5 checkout');
    expect(section).toHaveTextContent('after 3 days');
    await userEvent.click(within(section).getByRole('button', { name: 'Clear from list: Rescue 5, Pulse oximeter' }));
    expect(onClear).toHaveBeenCalledWith(resolved);
  });

  it('renders nothing when there is nothing resolved', () => {
    const { container } = render(<RecentlyResolved issues={[]} hours={72} busy={false} onOpen={() => {}} onClear={() => {}} />);
    expect(container).toBeEmptyDOMElement();
  });
});
