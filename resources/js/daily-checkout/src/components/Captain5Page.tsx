import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { RefreshCw } from 'lucide-react';
import { CAPTAIN_5_IMAGE } from '../types';
import { useOffline } from '../hooks/useOffline';
import DailyPageNav from './DailyPageNav';
import Captain5Denied from './captain5/Captain5Denied';
import Captain5Messages from './captain5/Captain5Messages';
import DutyTab from './captain5/DutyTab';
import HistoryTab from './captain5/HistoryTab';
import InspectionSheet from './captain5/InspectionSheet';
import IssueSheet from './captain5/IssueSheet';
import IssuesTab from './captain5/IssuesTab';
import ReplacementsTab from './captain5/ReplacementsTab';
import TodayTab from './captain5/TodayTab';
import { isKindFilter, isQueueFilter, type KindFilter, type QueueFilter } from './captain5/filters';
import { dutyLine, isReplacementTracked } from './captain5/format';
import type { Workspace } from './captain5/types';
import { useWorkspace } from './captain5/useWorkspace';
import Tabs, { type TabItem } from './ui/Tabs';
import Tag from './ui/Tag';
import { LoadError } from './ui/StateViews';

type TabId = 'today' | 'issues' | 'replacements' | 'messages' | 'history' | 'duty';

const TAB_IDS: TabId[] = ['today', 'issues', 'replacements', 'messages', 'history', 'duty'];
const isTab = (value: string | null): value is TabId => TAB_IDS.includes(value as TabId);
const positiveInt = (value: string | null): number | null => (value !== null && /^\d{1,12}$/.test(value) && Number(value) > 0 ? Number(value) : null);

function tabsFor(workspace: Workspace, unread: number): Array<TabItem<TabId>> {
  const tracked = workspace.issues.filter(isReplacementTracked).length;
  return [
    { id: 'today', label: 'Today' },
    { id: 'issues', label: 'Issues', badge: workspace.counts.open, badgeLabel: 'open issues' },
    { id: 'replacements', label: 'Replacements', badge: tracked, badgeLabel: 'in progress' },
    { id: 'messages', label: 'Messages', badge: unread, badgeLabel: 'unread messages' },
    { id: 'history', label: 'History' },
    ...(workspace.access.manage_assignments ? [{ id: 'duty' as const, label: 'Duty' }] : []),
  ];
}

function Loading() {
  return (
    <div data-hub-ui="2" className="space-y-6 font-hub" role="status" aria-label="Loading Captain 5">
      <DailyPageNav fallback="/stations" />
      <span className="hub-skeleton block !h-40 w-full" />
      <span className="hub-skeleton block !h-64 w-full" />
    </div>
  );
}

export default function Captain5Page() {
  const ws = useWorkspace();
  const { data, load } = ws;
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const isOffline = useOffline();
  const [inspectionId, setInspectionId] = useState<number | null>(null);
  const [version, setVersion] = useState(0);
  const pushedIssue = useRef(false);

  const requestedTab = searchParams.get('tab');
  const issueId = positiveInt(searchParams.get('issue'));
  const queue: QueueFilter = isQueueFilter(searchParams.get('filter')) ? searchParams.get('filter') as QueueFilter : 'all';
  const kind: KindFilter = isKindFilter(searchParams.get('kind')) ? searchParams.get('kind') as KindFilter : 'all';

  const update = useCallback((changes: Record<string, string | null>, push = false) => {
    const next = new URLSearchParams(searchParams);
    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === 'all') next.delete(key); else next.set(key, value);
    }
    setSearchParams(next, { replace: !push, preventScrollReset: true });
  }, [searchParams, setSearchParams]);

  useEffect(() => { if (issueId === null) pushedIssue.current = false; }, [issueId]);

  const openIssue = useCallback((id: number) => { pushedIssue.current = true; update({ issue: String(id) }, true); }, [update]);
  const closeIssue = useCallback(() => {
    if (pushedIssue.current) { pushedIssue.current = false; navigate(-1); } else update({ issue: null });
  }, [navigate, update]);
  const changed = useCallback(() => { setVersion((n) => n + 1); void load('silent'); }, [load]);

  if (ws.forbidden) return <Captain5Denied />;
  if (ws.loading && !data) return <Loading />;
  if (!data) {
    return (
      <div data-hub-ui="2" className="mx-auto max-w-lg font-hub">
        <DailyPageNav fallback="/stations" />
        <LoadError title="Captain 5 could not be loaded" message={ws.error} onRetry={() => void load('initial')} />
      </div>
    );
  }

  const { access } = data;
  const tabs = tabsFor(data, ws.unread);
  const active: TabId = isTab(requestedTab) && tabs.some((tab) => tab.id === requestedTab) ? requestedTab : 'today';
  const refreshLabel = isOffline ? 'Refresh unavailable while offline' : ws.refreshing ? 'Refreshing Captain 5' : 'Refresh Captain 5';

  const renderPanel = (id: TabId) => {
    switch (id) {
      case 'today':
        return <TodayTab workspace={data} onFilter={(next) => update({ tab: 'issues', filter: next, kind: null })} onOpenInspection={setInspectionId} onHandoff={(handoff) => ws.patch({ handoff })} />;
      case 'issues':
        return <IssuesTab workspace={data} queue={queue} kind={kind} onFilter={(nextQueue, nextKind) => update({ filter: nextQueue, kind: nextKind })} onOpen={openIssue} />;
      case 'replacements':
        return <ReplacementsTab issues={data.issues} onOpen={openIssue} />;
      case 'messages':
        return <Captain5Messages onDenied={ws.markForbidden} />;
      case 'history':
        return <HistoryTab onOpen={openIssue} version={version} onForbidden={ws.markForbidden} />;
      default:
        return <DutyTab assignments={data.assignments} onChange={(assignments) => { ws.patch({ assignments }); void load('silent'); }} />;
    }
  };

  return (
    <div data-hub-ui="2" className="space-y-6 font-hub" data-testid="captain5-page">
      <DailyPageNav fallback="/stations" />

      <section className="hub-panel p-4 sm:p-6" aria-labelledby="captain5-heading">
        <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
          <img
            src={CAPTAIN_5_IMAGE}
            alt="Miami Beach Fire Rescue Captain 5 EMS vehicle"
            width="1200"
            height="800"
            decoding="async"
            className="aspect-[3/2] h-auto w-full max-w-[14rem] flex-none self-center rounded-hub-control bg-hub-n-100 object-cover sm:self-start"
          />
          <div className="min-w-0 flex-1 space-y-2">
            <h1 id="captain5-heading" className="font-heading text-2xl font-bold text-hub-ink sm:text-3xl">Captain 5 · EMS Operations</h1>
            <p className="flex flex-wrap items-center gap-2 text-sm" data-testid="captain5-duty-line">
              <Tag tone={data.on_duty.resolved ? 'success' : 'warning'} className="!text-sm">{dutyLine(data.on_duty)}</Tag>
            </p>
            <p className="flex flex-wrap items-center gap-2 text-[13px] leading-5 text-hub-n-600" data-testid="captain5-authority">
              <span>Your access: {access.authority_label || 'Captain 5'}</span>
              {!access.act && <Tag>View only</Tag>}
            </p>
          </div>
          <button
            type="button"
            data-testid="captain5-refresh"
            className="hub-btn hub-btn--secondary !min-h-11 !min-w-11 flex-none gap-2 self-start"
            onClick={() => void load('manual')}
            disabled={ws.refreshing || isOffline}
            aria-busy={ws.refreshing}
            aria-label={refreshLabel}
            title={refreshLabel}
          >
            <RefreshCw className={`h-4 w-4 ${ws.refreshing ? 'motion-safe:animate-spin' : ''}`} aria-hidden="true" />
            <span className="hidden sm:inline">{ws.refreshing ? 'Refreshing' : 'Refresh'}</span>
          </button>
        </div>
      </section>

      {ws.error && (
        <div className="hub-callout hub-callout--warning p-3 text-sm" role="alert">
          Captain 5 could not be refreshed: {ws.error} What you see may be out of date.
        </div>
      )}

      <div className="hub-panel overflow-hidden" data-testid="captain5-workspace">
        <Tabs
          tabs={tabs}
          active={active}
          onChange={(id) => update({ tab: id === 'today' ? null : id, filter: null, kind: null })}
          label="Captain 5 workspace"
          idPrefix="captain5"
          panelClassName="p-4 sm:p-5"
          renderPanel={renderPanel}
        />
      </div>

      <IssueSheet issueId={issueId} canAct={access.act} onClose={closeIssue} onChanged={changed} onOpenInspection={setInspectionId} />
      <InspectionSheet inspectionId={inspectionId} canAct={access.act} onClose={() => setInspectionId(null)} />
    </div>
  );
}
