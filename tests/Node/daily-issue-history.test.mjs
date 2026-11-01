import assert from 'node:assert/strict';
import test from 'node:test';
import {
  parseClosedPage,
  parseConcernNote,
  parseFindingsResponse,
  parseIssueHistory,
  parseIssueList,
  parseIssueSummary,
  parseTimelineEntry,
} from '../../resources/js/daily-checkout/src/utils/issueHistoryParse.ts';
import {
  initialLiveFindings,
  liveFindingsCacheKey,
  liveFindingsReducer,
  mergeKnownFindings,
} from '../../resources/js/daily-checkout/src/utils/liveFindings.ts';
import {
  concernDraftProblems,
  concernIssueRefs,
  concernStarted,
  newConcernDraft,
  restoreConcernDrafts,
  suggestExistingIssue,
  toConcernSubmissions,
} from '../../resources/js/daily-checkout/src/utils/concerns.ts';
import {
  closedIssuesHeading,
  formatEventDateTime,
  issuePresentation,
  liveStatusLine,
  openIssueCountText,
  reportedByLine,
} from '../../resources/js/daily-checkout/src/utils/issueHistoryFormat.ts';

const issueRow = (extra = {}) => ({
  id: 41,
  compartment: 'Driver L4',
  item: "Driver's Gear",
  compartment_id: 'comp_l4',
  item_id: 'comp_l4-item-1',
  issue_type: 'missing',
  severity: 'routine',
  description: 'The gear bag was not in the compartment.',
  reporter_name: 'Dana Ortiz',
  first_reported_at: '2026-10-30T15:00:00-04:00',
  action_status: 'replacement_available',
  action_label: 'Replacement available',
  closure_kind: null,
  latest_action: { label: 'Replacement ordered', actor_name: 'Sam Lee', at: '2026-10-31T09:00:00-04:00', text: 'Ordered from the vendor.' },
  last_observation: 'confirmed_again',
  last_observed_at: '2026-11-01T08:00:00-04:00',
  service_status: 'open',
  recurrence_of_defect_id: null,
  history_count: 4,
  ...extra,
});

const checklistFinding = (extra = {}) => ({
  id: 41,
  compartment: 'Old name',
  item: 'Old item',
  issue_type: 'missing',
  operational_impact: 'unclassified',
  last_observation: null,
  last_observed_at: null,
  service_status: null,
  action_label: 'Awaiting verification',
  latest_member_update: { text: 'Ordered.', at: '2026-10-31T09:00:00Z' },
  ...extra,
});

const live = (extra = {}) => parseIssueSummary(issueRow(extra));

// ---------------------------------------------------------------------------
// Parsers
// ---------------------------------------------------------------------------

test('an issue is rebuilt from the contract keys only; anything else is dropped', () => {
  const issue = parseIssueSummary({ ...issueRow(), internal_note: 'secret', resolution_notes: 'secret', details: { a: 1 }, photo_path: 'private/x.jpg' });

  assert.deepEqual(Object.keys(issue).sort(), Object.keys(issueRow()).sort());
  assert.equal(issue.description, 'The gear bag was not in the compartment.');
  assert.deepEqual(issue.latest_action, { label: 'Replacement ordered', actor_name: 'Sam Lee', at: '2026-10-31T09:00:00-04:00', text: 'Ordered from the vendor.' });
});

test('a bad row is skipped, never thrown on, and ids are not repeated', () => {
  const rows = [null, 'x', 7, [], {}, { id: 'nope' }, { id: -3 }, issueRow({ id: 5 }), issueRow({ id: 5, item: 'Duplicate' }), issueRow({ id: 6, latest_action: 'bad', history_count: -1, severity: 'weird', closure_kind: 'other' })];
  const issues = parseIssueList(rows);

  assert.deepEqual(issues.map(issue => issue.id), [5, 6]);
  assert.equal(issues[0].item, "Driver's Gear");
  assert.equal(issues[1].latest_action, null);
  assert.equal(issues[1].history_count, 0);
  assert.equal(issues[1].severity, 'routine');
  assert.equal(issues[1].closure_kind, null);
  assert.deepEqual(parseIssueList('not a list'), []);
});

test('missing wording falls back to the humanized status, and names fall back to nothing invented', () => {
  const issue = parseIssueSummary({ id: 9, action_status: 'waiting_for_parts' });

  assert.equal(issue.action_label, 'Waiting for parts');
  assert.equal(issue.reporter_name, null);
  assert.equal(issue.first_reported_at, null);
  assert.equal(issue.item, '');
});

test('the findings answer needs an open list; with it, notes and counts are read defensively', () => {
  assert.equal(parseFindingsResponse(null), null);
  assert.equal(parseFindingsResponse({ open: 'x' }), null);
  assert.equal(parseFindingsResponse([]), null);

  const snapshot = parseFindingsResponse({
    as_of: '2026-11-01T14:00:00-04:00',
    open: [issueRow(), { broken: true }],
    notes: [{ id: 3, description: 'Hose bed latch is stiff', location: 'Rear', reporter_name: 'Kim', reported_at: '2026-11-01T07:00:00-04:00', inspection_reference: null, photo_url: '/api/public/inspection-concerns/3/photo', extra: 1 }, { id: 4, description: '  ' }, 'x'],
    closed_count: 12,
  });

  assert.equal(snapshot.as_of, '2026-11-01T14:00:00-04:00');
  assert.equal(snapshot.open.length, 1);
  assert.equal(snapshot.notes.length, 1);
  assert.equal(snapshot.notes[0].photo_url, '/api/public/inspection-concerns/3/photo');
  assert.equal('extra' in snapshot.notes[0], false);
  assert.equal(snapshot.closed_count, 12);
  assert.equal(parseFindingsResponse({ open: [] }, 'fallback').as_of, 'fallback');
  assert.equal(parseFindingsResponse({ open: [], closed_count: 'many' }).closed_count, 0);
});

test('a photo address is kept only when it is a site path or https', () => {
  const entry = (photo_url) => parseTimelineEntry({ id: 'event-1', kind: 'event', photo_url });

  assert.equal(entry('/api/public/apparatus-issues/1/photo').photo_url, '/api/public/apparatus-issues/1/photo');
  assert.equal(entry('https://example.test/p.jpg').photo_url, 'https://example.test/p.jpg');
  assert.equal(entry('javascript:alert(1)').photo_url, null);
  assert.equal(entry('//evil.test/p.jpg').photo_url, null);
  assert.equal(entry(42).photo_url, null);
});

test('timeline entries keep their order and lose only the unreadable ones', () => {
  const history = parseIssueHistory({
    issue: issueRow(),
    timeline: [
      { id: 'event-12', kind: 'event', type: 'reported', label: 'Reported', lines: ['Missing', '', 7], text: null, actor_name: 'Dana Ortiz', at: '2026-10-30T15:00:00-04:00', photo_url: null },
      { id: 'message-4', kind: 'message', label: 'Update', text: 'Ordered', actor_name: null, at: null },
      { id: 'x', kind: 'other' },
      null,
      { kind: 'event' },
      { id: 12, kind: 'event', type: 'closed' },
    ],
  });

  assert.deepEqual(history.timeline.map(entry => entry.id), ['event-12', 'message-4', '12']);
  assert.deepEqual(history.timeline[0].lines, ['Missing']);
  assert.equal(history.timeline[1].type, 'update');
  assert.equal(history.timeline[1].actor_name, null);
  assert.equal(history.timeline[2].label, 'Closed');
  assert.equal(parseIssueHistory({ issue: null }), null);
  assert.equal(parseIssueHistory({ issue: 'x', timeline: [] }).issue, null);
});

test('a closed page needs its data list and reads the cursor as text or null', () => {
  assert.equal(parseClosedPage({}), null);
  assert.deepEqual(parseClosedPage({ data: [issueRow({ id: 2, closure_kind: 'resolved' })], next_cursor: 'abc' }).next_cursor, 'abc');
  assert.equal(parseClosedPage({ data: [], next_cursor: 5 }).next_cursor, null);
  assert.equal(parseConcernNote({ id: 1 }), null);
});

// ---------------------------------------------------------------------------
// Merge with the checklist and the live state
// ---------------------------------------------------------------------------

test('live status and current names are laid over the checklist finding, which keeps what only it carries', () => {
  const merged = mergeKnownFindings([checklistFinding()], [live({ action_label: 'Replacement available', severity: 'significant' })], true);

  assert.equal(merged.findings.length, 1);
  const [finding] = merged.findings;
  assert.equal(finding.item, "Driver's Gear");
  assert.equal(finding.compartment, 'Driver L4');
  assert.equal(finding.compartment_id, 'comp_l4');
  assert.equal(finding.item_id, 'comp_l4-item-1');
  assert.equal(finding.action_label, 'Replacement available');
  assert.equal(finding.severity, 'significant');
  assert.equal(finding.first_reported_at, '2026-10-30T15:00:00-04:00');
  assert.deepEqual(finding.latest_member_update, { text: 'Ordered.', at: '2026-10-31T09:00:00Z' });
  assert.equal(merged.liveById.get(41).reporter_name, 'Dana Ortiz');
  assert.equal(merged.noLongerOpen.size, 0);
});

test('a live issue the checklist did not carry is added; the checklist order is kept', () => {
  const merged = mergeKnownFindings([checklistFinding({ id: 7 }), checklistFinding({ id: 41 })], [live({ id: 41 }), live({ id: 99, item: 'Pike pole', compartment_id: null, item_id: null })], true);

  assert.deepEqual(merged.findings.map(finding => finding.id), [7, 41, 99]);
  const added = merged.findings[2];
  assert.equal(added.item, 'Pike pole');
  assert.equal('compartment_id' in added, false);
  assert.equal(added.latest_member_update, null);
});

test('live data never replaces the checklist: offline or old, every checklist finding stays', () => {
  const findings = [checklistFinding({ id: 7 }), checklistFinding({ id: 8 })];
  const offline = mergeKnownFindings(findings, [], false);

  assert.deepEqual(offline.findings, findings);
  assert.equal(offline.noLongerOpen.size, 0);
  assert.deepEqual(mergeKnownFindings(undefined, [], false).findings, []);
});

test('a current answer that no longer lists a checklist finding marks it instead of dropping it', () => {
  const merged = mergeKnownFindings([checklistFinding({ id: 7 }), checklistFinding({ id: 41 })], [live({ id: 41 })], true);

  assert.deepEqual(merged.findings.map(finding => finding.id), [7, 41]);
  assert.deepEqual([...merged.noLongerOpen], [7]);
});

test('an unmatched finding with no usable ids stays out of every item and is still returned', () => {
  const merged = mergeKnownFindings([checklistFinding({ id: 3, compartment: 'Combined', item: 'Legacy bag' })], [], true);

  assert.equal(merged.findings[0].item, 'Legacy bag');
  assert.equal(merged.findings[0].compartment_id, undefined);
});

test('the live state moves loading, live, stale and unavailable, and keeps the last good answer', () => {
  const snapshot = parseFindingsResponse({ as_of: 'a', open: [issueRow()], closed_count: 1 });
  const newer = parseFindingsResponse({ as_of: 'b', open: [], closed_count: 2 });
  let store = initialLiveFindings;

  assert.equal(store.state, 'loading');
  store = liveFindingsReducer(store, { type: 'failure' });
  assert.deepEqual(store, { snapshot: null, state: 'unavailable' });
  store = liveFindingsReducer(store, { type: 'request' });
  assert.equal(store.state, 'loading');
  store = liveFindingsReducer(store, { type: 'success', snapshot });
  assert.equal(store.state, 'live');
  store = liveFindingsReducer(store, { type: 'failure' });
  assert.equal(store.state, 'stale');
  assert.equal(store.snapshot, snapshot);
  assert.equal(liveFindingsReducer(store, { type: 'failure' }), store);
  store = liveFindingsReducer(store, { type: 'request' });
  assert.equal(store.state, 'stale');
  store = liveFindingsReducer(store, { type: 'success', snapshot: newer });
  assert.deepEqual([store.state, store.snapshot.as_of], ['live', 'b']);
  assert.deepEqual(liveFindingsReducer(store, { type: 'reset' }), initialLiveFindings);
});

test('the answer kept on the device fills an empty screen but never replaces a fresher one', () => {
  const cached = parseFindingsResponse({ as_of: 'old', open: [issueRow()] });
  const fresh = parseFindingsResponse({ as_of: 'new', open: [] });

  assert.deepEqual(liveFindingsReducer(initialLiveFindings, { type: 'cached', snapshot: cached }), { snapshot: cached, state: 'loading' });
  // The request already failed (offline): the cached answer is old, not missing.
  const failed = liveFindingsReducer(initialLiveFindings, { type: 'failure' });
  assert.deepEqual(liveFindingsReducer(failed, { type: 'cached', snapshot: cached }), { snapshot: cached, state: 'stale' });
  const live = liveFindingsReducer(initialLiveFindings, { type: 'success', snapshot: fresh });
  assert.equal(liveFindingsReducer(live, { type: 'cached', snapshot: cached }), live);
});

test('the cached answer is keyed by apparatus and member', () => {
  const key = liveFindingsCacheKey(102, 401);

  assert.match(key, /102/);
  assert.match(key, /401/);
  assert.notEqual(key, liveFindingsCacheKey(102, 402));
  assert.notEqual(key, liveFindingsCacheKey(103, 401));
});

// ---------------------------------------------------------------------------
// Concerns
// ---------------------------------------------------------------------------

const draft = (extra = {}) => ({ ...newConcernDraft(`id-${Math.random()}`), ...extra });

test('a checkout with no concerns sends none, and an untouched entry is ignored', () => {
  assert.deepEqual(toConcernSubmissions([]), []);
  assert.deepEqual(toConcernSubmissions([draft(), draft({ kind: 'issue' })]), []);
  assert.deepEqual(concernDraftProblems([draft(), draft({ kind: 'note' })]), []);
  assert.equal(concernStarted(draft({ kind: 'note' })), false);
});

test('a note and a problem convert to the payload shape, with empty optional keys left out', () => {
  const note = draft({ client_id: 'a', kind: 'note', description: '  Dent above the pump panel  ', location: ' ' });
  const problem = draft({ client_id: 'b', kind: 'issue', description: 'Cracked mirror', location: ' Officer side ', photo: 'data:image/png;base64,AAA' });

  assert.deepEqual(toConcernSubmissions([note, problem]), [
    { client_id: 'a', kind: 'note', description: 'Dent above the pump panel' },
    { client_id: 'b', kind: 'issue', description: 'Cracked mirror', location: 'Officer side', photo: 'data:image/png;base64,AAA' },
  ]);
});

test('choosing an open issue makes the entry an update to it', () => {
  const [entry] = toConcernSubmissions([draft({ client_id: 'c', kind: null, description: 'Still missing today', existing_issue_id: 41 })]);

  assert.deepEqual(entry, { client_id: 'c', kind: 'issue', description: 'Still missing today', existing_issue_id: 41 });
  assert.deepEqual(concernDraftProblems([draft({ description: 'x', existing_issue_id: 41 })]), []);
});

test('a started entry needs a description and a kind, and is not sent until it has both', () => {
  const noText = draft({ client_id: 'n', kind: 'note', location: 'Rear' });
  const noKind = draft({ client_id: 'k', description: 'Loose step' });
  const good = draft({ client_id: 'g', kind: 'issue', description: 'Loose step' });

  assert.deepEqual(concernDraftProblems([noText, noKind, good]).map(problem => [problem.client_id, problem.field]), [['n', 'description'], ['k', 'kind']]);
  assert.deepEqual(toConcernSubmissions([noText, noKind, good]).map(entry => entry.client_id), ['g']);
});

test('no more than 20 entries are sent, and long text is cut to the limits', () => {
  const many = Array.from({ length: 25 }, (_, index) => draft({ client_id: `d${index}`, kind: 'note', description: 'Note' }));

  assert.equal(toConcernSubmissions(many).length, 20);
  const [long] = toConcernSubmissions([draft({ kind: 'note', description: 'x'.repeat(2500), location: 'y'.repeat(300) })]);
  assert.equal(long.description.length, 2000);
  assert.equal(long.location.length, 255);
});

test('saved concern drafts come back clean, capped, and without anything unreadable', () => {
  const restored = restoreConcernDrafts([
    { client_id: 'a', kind: 'issue', description: 'Leak', location: 'Pump', photo: 'data:image/png;base64,AAA', existing_issue_id: 5, junk: 1 },
    { client_id: 'b', kind: 'wrong', description: 4, existing_issue_id: 'x' },
    { description: 'no id' },
    null,
  ]);

  assert.deepEqual(restored, [
    { client_id: 'a', kind: 'issue', description: 'Leak', location: 'Pump', photo: 'data:image/png;base64,AAA', existing_issue_id: 5 },
    { client_id: 'b', kind: null, description: '', location: '', existing_issue_id: null },
  ]);
  assert.deepEqual(restoreConcernDrafts('x'), []);
  assert.equal(restoreConcernDrafts(Array.from({ length: 30 }, (_, index) => ({ client_id: `c${index}` }))).length, 20);
});

test('the open issues offered to a concern leave out the ones no longer open', () => {
  const merged = mergeKnownFindings([checklistFinding({ id: 7, item: 'Hose', compartment: 'Rear' }), checklistFinding({ id: 41 })], [live({ id: 41 })], true);
  const refs = concernIssueRefs(merged.findings, merged.liveById, merged.noLongerOpen);

  assert.deepEqual(refs.map(ref => ref.id), [41]);
  assert.equal(refs[0].status, 'Replacement available');
  assert.equal(refs[0].description, 'The gear bag was not in the compartment.');
});

const refs = [
  { id: 41, item: "Driver's Gear", compartment: 'Driver L4', issue_type: 'missing', status: 'Replacement available', description: 'The gear bag was not in the compartment.' },
  { id: 52, item: 'Rear step', compartment: 'Rear', issue_type: 'damaged', status: 'Awaiting verification', description: null },
];

test('a description that shares most of its words with an open issue suggests that issue', () => {
  assert.equal(suggestExistingIssue("drivers gear is missing again", refs)?.id, 41);
  assert.equal(suggestExistingIssue('The rear step is bent and damaged', refs)?.id, 52);
});

test('the duplicate hint stays quiet for unrelated or very short text', () => {
  assert.equal(suggestExistingIssue('Cracked mirror on the officer side', refs), null);
  assert.equal(suggestExistingIssue('Gear', refs), null);
  assert.equal(suggestExistingIssue('', refs), null);
  assert.equal(suggestExistingIssue('The step', []), null);
});

// ---------------------------------------------------------------------------
// Wording
// ---------------------------------------------------------------------------

test('Resolved and Closed with no action read differently, and a reopened issue is active', () => {
  const resolved = issuePresentation({ closure_kind: 'resolved', action_status: 'resolved_replaced', action_label: 'Replaced', severity: 'routine' });
  const closed = issuePresentation({ closure_kind: 'closed_no_action', action_status: 'closed', action_label: 'Closed with no action', severity: 'routine' });
  const reopened = issuePresentation({ closure_kind: 'resolved', action_status: 'reopened', action_label: 'Reopened', severity: 'routine' });
  const active = issuePresentation({ closure_kind: null, action_status: 'replacement_available', action_label: 'Replacement available', severity: 'significant' });

  assert.deepEqual([resolved.phase, resolved.label, resolved.detail], ['resolved', 'Resolved', 'Replaced']);
  assert.deepEqual([closed.phase, closed.label, closed.detail], ['closed', 'Closed with no action', null]);
  assert.notEqual(resolved.label, closed.label);
  assert.deepEqual([reopened.phase, reopened.label], ['active', 'Reopened']);
  assert.deepEqual([active.phase, active.label, active.tone], ['active', 'Replacement available', 'danger']);
  assert.equal(issuePresentation({ closure_kind: null, action_status: '', action_label: '', severity: 'routine' }).label, 'Open');
  assert.equal(issuePresentation({ closure_kind: 'resolved', action_status: 'x', action_label: 'Resolved', severity: 'routine' }).detail, null);
});

test('who reported it and when never invents a name or a date', () => {
  assert.equal(reportedByLine({ reporter_name: 'Dana Ortiz', first_reported_at: '2026-10-30T15:00:00-04:00' }), 'Reported by Dana Ortiz on Oct 30, 2026');
  assert.equal(reportedByLine({ reporter_name: null, first_reported_at: '2026-10-30T15:00:00-04:00' }), 'Reported by Not recorded on Oct 30, 2026');
  assert.equal(reportedByLine({ reporter_name: 'Dana Ortiz', first_reported_at: null }), 'Reported by Dana Ortiz on Not recorded');
  assert.equal(reportedByLine({ reporter_name: null, first_reported_at: 'garbage' }), 'Reported by Not recorded');
});

test('times are in the department time zone and unreadable ones are null', () => {
  // Daylight time ended that morning, so 19:30 UTC is 2:30 PM Eastern; the evening before it was UTC-4.
  assert.equal(formatEventDateTime('2026-11-01T19:30:00Z'), 'Nov 1, 2026, 2:30 PM');
  assert.equal(formatEventDateTime('2026-10-31T19:30:00Z'), 'Oct 31, 2026, 3:30 PM');
  assert.equal(formatEventDateTime(null), null);
  assert.equal(formatEventDateTime('not a date'), null);
});

test('the count and the closed heading read plainly', () => {
  assert.equal(openIssueCountText(0), 'No open issues on this apparatus');
  assert.equal(openIssueCountText(1), '1 open issue on this apparatus');
  assert.equal(openIssueCountText(3), '3 open issues on this apparatus');
  assert.equal(closedIssuesHeading(12), 'Earlier closed issues (12)');
});

test('the status line says how current the list is, and offline never reads as live', () => {
  const now = new Date('2026-11-01T20:00:00Z');
  const asOf = '2026-11-01T18:14:00Z';

  assert.deepEqual(liveStatusLine('live', asOf, false, now), { text: 'Updated 1:14 PM', tone: 'ok' });
  assert.equal(liveStatusLine('stale', asOf, true, now).text, 'Offline. Showing the issues last saved 1:14 PM.');
  assert.equal(liveStatusLine('stale', asOf, false, now).text, 'Could not refresh. Showing the issues last saved 1:14 PM.');
  assert.equal(liveStatusLine('stale', '2026-10-30T18:14:00Z', true, now).text, 'Offline. Showing the issues last saved Oct 30, 2:14 PM.');
  assert.equal(liveStatusLine('stale', asOf, true, now).tone, 'warn');
  assert.equal(liveStatusLine('unavailable', null, false, now).text, 'Live issue history could not be loaded.');
  assert.equal(liveStatusLine('loading', null, false, now).tone, 'muted');
  for (const state of ['live', 'loading', 'stale', 'unavailable']) {
    assert.doesNotMatch(liveStatusLine(state, asOf, false, now).text, /—/);
  }
});
