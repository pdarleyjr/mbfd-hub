import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { RefreshCw } from 'lucide-react';
import { CAPTAIN_5_IMAGE, type VehicleIdentification } from '../types';
import { useOffline } from '../hooks/useOffline';
import DailyPageNav from './DailyPageNav';
import ActingApprovals from './acting/ActingApprovals';
import ActingControl from './acting/ActingControl';
import { CAPTAIN_5_ACTING } from './captain5/actingRole';
import Captain5Denied from './captain5/Captain5Denied';
import Captain5Messages from './captain5/Captain5Messages';
import HistoryTab from './captain5/HistoryTab';
import InspectionSheet from './captain5/InspectionSheet';
import IssueSheet from './captain5/IssueSheet';
import IssuesTab from './captain5/IssuesTab';
import ReplacementsTab from './captain5/ReplacementsTab';
import TodayTab from './captain5/TodayTab';
import VehicleIdentificationPanel from './VehicleIdentificationPanel';
import VehicleIdentificationCard from './VehicleIdentificationCard';
import RescueInventoryTab, { type RescueView } from './captain5/ems/RescueInventoryTab';
import SupplyRoomTab, { isSupplyView, type SupplyView } from './captain5/ems/SupplyRoomTab';
import { useEmsAccess, useOpenRequestCount } from './captain5/ems/useEmsAccess';
import { isKindFilter, isQueueFilter, type KindFilter, type QueueFilter } from './captain5/filters';
import { dutyLine, dutyTone, isReplacementTracked } from './captain5/format';
import type { ActingResult, Workspace } from './captain5/types';
import { useWorkspace } from './captain5/useWorkspace';
import DepartmentUpdatesPanel from './department-updates/DepartmentUpdatesPanel';
import Tabs, { type TabItem } from './ui/Tabs';
import Tag from './ui/Tag';
import { LoadError, SkeletonRows } from './ui/StateViews';

type TabId = 'today' | 'issues' | 'replacements' | 'rescue' | 'supply' | 'messages' | 'updates' | 'history';

const TAB_IDS: TabId[] = ['today', 'issues', 'replacements', 'rescue', 'supply', 'messages', 'updates', 'history'];
const isTab = (value: string | null): value is TabId => TAB_IDS.includes(value as TabId);
const isEmsTab = (value: string | null): boolean => value === 'rescue' || value === 'supply';
const positiveInt = (value: string | null): number | null => (value !== null && /^\d{1,12}$/.test(value) && Number(value) > 0 ? Number(value) : null);
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

interface EmsTabs { show: boolean; openRequests: number }

function tabsFor(workspace: Workspace, unread: number, ems: EmsTabs): Array<TabItem<TabId>> {
  const tracked = workspace.issues.filter(isReplacementTracked).length;
  return [
    { id: 'today', label: 'Today' },
    { id: 'issues', label: 'Issues', badge: workspace.counts.open, badgeLabel: 'open issues' },
    { id: 'replacements', label: 'Replacements', badge: tracked, badgeLabel: 'in progress' },
    ...(ems.show ? [
      { id: 'rescue' as const, label: 'Rescue Inventory', badge: ems.openRequests, badgeLabel: 'open requests' },
      { id: 'supply' as const, label: 'Supply Room' },
    ] : []),
    { id: 'messages', label: 'Messages', badge: unread, badgeLabel: 'unread messages' },
    { id: 'updates', label: 'Department Updates' },
    { id: 'history', label: 'History' },
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
  const [identificationSaved, setIdentificationSaved] = useState(false);
  const pushedIssue = useRef(false);
  const pushedRequest = useRef(false);
  const ems = useEmsAccess();

  const requestedTab = searchParams.get('tab');
  const issueId = positiveInt(searchParams.get('issue'));
  // The EMS tabs exist only for people the EMS API admits. A deep link keeps its tab while that is being checked.
  const emsShown = ems.status === 'ready' || ((ems.status === 'loading' || ems.status === 'error') && isEmsTab(requestedTab));
  const openRequests = useOpenRequestCount(ems.status === 'ready');
  const requestParam = searchParams.get('request');
  const requestId = requestParam !== null && UUID.test(requestParam) ? requestParam.toLowerCase() : null;
  const rescueView: RescueView = searchParams.get('view') === 'stats' ? 'stats' : 'requests';
  const sectionParam = searchParams.get('section');
  const supplyView: SupplyView = isSupplyView(sectionParam) ? sectionParam : 'stock';
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

  useEffect(() => { if (requestId === null) pushedRequest.current = false; }, [requestId]);
  const openRequest = useCallback((uuid: string) => { pushedRequest.current = true; update({ request: uuid }, true); }, [update]);
  const closeRequest = useCallback(() => {
    if (pushedRequest.current) { pushedRequest.current = false; navigate(-1); } else update({ request: null });
  }, [navigate, update]);
  const changed = useCallback(() => { setVersion((n) => n + 1); void load('silent'); }, [load]);
  const identificationChanged = useCallback((next: VehicleIdentification | undefined) => {
    setIdentificationSaved(true);
    ws.patch({ vehicle_identification: next });
    void load('silent');
  }, [ws.patch, load]);
  const actingChanged = useCallback((result: ActingResult) => { ws.patch({ on_duty: result.on_duty, acting: result.acting }); void load('silent'); }, [ws.patch, load]);

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
  const tabs = tabsFor(data, ws.unread, { show: emsShown, openRequests: openRequests.count });
  const active: TabId = isTab(requestedTab) && tabs.some((tab) => tab.id === requestedTab) ? requestedTab : 'today';
  const refreshLabel = isOffline ? 'Refresh unavailable while offline' : ws.refreshing ? 'Refreshing Captain 5' : 'Refresh Captain 5';

  const renderEms = (id: 'rescue' | 'supply') => {
    if (ems.status === 'loading') return <SkeletonRows rows={4} />;
    if (ems.status === 'error') return <LoadError title={id === 'rescue' ? 'Rescue Inventory could not be loaded' : 'The Supply Room could not be loaded'} message={ems.error} onRetry={ems.reload} />;
    if (ems.status !== 'ready') return null;
    return id === 'rescue'
      ? (
        <RescueInventoryTab
          meta={ems.meta}
          requestId={requestId}
          view={rescueView}
          onView={(next) => update({ view: next === 'stats' ? 'stats' : null, request: null })}
          onOpenRequest={openRequest}
          onCloseRequest={closeRequest}
          tick={openRequests.tick}
          onChanged={openRequests.refresh}
          onForbidden={ems.deny}
        />
      )
      : <SupplyRoomTab meta={ems.meta} view={supplyView} onView={(next) => update({ section: next === 'stock' ? null : next })} onForbidden={ems.deny} />;
  };

  const renderPanel = (id: TabId) => {
    switch (id) {
      case 'today':
        return <TodayTab workspace={data} onFilter={(next) => update({ tab: 'issues', filter: next, kind: null })} onOpenInspection={setInspectionId} onHandoff={(handoff) => ws.patch({ handoff })} />;
      case 'issues':
        return <IssuesTab workspace={data} queue={queue} kind={kind} onFilter={(nextQueue, nextKind) => update({ filter: nextQueue, kind: nextKind })} onOpen={openIssue} />;
      case 'replacements':
        return <ReplacementsTab issues={data.issues} onOpen={openIssue} />;
      case 'rescue':
      case 'supply':
        return renderEms(id);
      case 'messages':
        return <Captain5Messages onDenied={ws.markForbidden} onOpenUpdates={() => update({ tab: 'updates', filter: null, kind: null })} />;
      case 'updates':
        return <DepartmentUpdatesPanel origin="captain_5" onOpenMessages={() => update({ tab: 'messages', filter: null, kind: null })} />;
      default:
        return <HistoryTab onOpen={openIssue} version={version} onForbidden={ws.markForbidden} />;
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
              <Tag tone={dutyTone(data.on_duty)} className="!text-sm !whitespace-normal !py-1 max-w-full break-words">{dutyLine(data.on_duty)}</Tag>
            </p>
            <ActingControl role={CAPTAIN_5_ACTING} onDuty={data.on_duty} acting={data.acting} onChange={actingChanged} />
            <p className="text-[13px] leading-5 text-hub-n-600" data-testid="captain5-authority">Your access: {access.authority_label || 'Captain 5'}</p>
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

      <ActingApprovals role={CAPTAIN_5_ACTING} acting={data.acting} onChange={actingChanged} />

      {data.vehicle_identification && !data.vehicle_identification.can_answer && (
        <VehicleIdentificationCard block={data.vehicle_identification} answers={{}} onChange={() => undefined} readOnly />
      )}
      {data.vehicle_identification?.can_answer && data.apparatus_id !== undefined && (
        <VehicleIdentificationPanel apparatusId={data.apparatus_id} block={data.vehicle_identification} onSaved={identificationChanged} testId="captain5-vehicle-identification" />
      )}
      {identificationSaved && !data.vehicle_identification && (
        <p role="status" className="hub-callout p-3 text-sm" data-testid="captain5-vehicle-identification-saved">
          Vehicle information saved. Thank you.
        </p>
      )}

      {ws.error && (
        <div className="hub-callout hub-callout--warning p-3 text-sm" role="alert">
          Captain 5 could not be refreshed: {ws.error} What you see may be out of date.
        </div>
      )}

      <div className="hub-panel overflow-hidden" data-testid="captain5-workspace">
        <Tabs
          tabs={tabs}
          active={active}
          onChange={(id) => update({ tab: id === 'today' ? null : id, filter: null, kind: null, request: null, view: null, section: null })}
          label="Captain 5 workspace"
          idPrefix="captain5"
          panelClassName="p-4 sm:p-5"
          renderPanel={renderPanel}
        />
      </div>

      <IssueSheet issueId={issueId} onClose={closeIssue} onChanged={changed} onOpenInspection={setInspectionId} />
      <InspectionSheet inspectionId={inspectionId} onClose={() => setInspectionId(null)} />
    </div>
  );
}
