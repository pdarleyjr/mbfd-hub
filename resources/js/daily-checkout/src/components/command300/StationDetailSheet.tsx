import { useMemo, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { Ban, Check, ChevronRight, Circle, ClipboardCheck, ClipboardList, Truck, Users, Wrench } from 'lucide-react';
import type { StationCheckoutRow, StationRequestSummary } from '../../types';
import { fetchCommandCheckout } from '../../utils/command300BoardApi';
import { Sheet } from '../captain5/ui';
import CheckoutSheet from '../station/CheckoutSheet';
import StationRequestSheet from '../station/StationRequestSheet';
import { clockTime } from '../station/format';
import { EmptyState, LoadError, SkeletonRows } from '../ui/StateViews';
import Tag, { StatusTag } from '../ui/Tag';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { PRESENCE_LABEL, PRESENCE_TONE, SECONDARY, ageLabel, readableName } from './format';
import type { CommandCheckoutDetail, DetailRequest, DetailUnit, StationDetail } from './types';
import type { PolledResource } from './useVisiblePoll';
import VehicleIssues from './VehicleIssues';

const ROW = 'hub-well flex min-h-14 w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:border-hub-blue/40 sm:px-4';

function Section({ id, icon: Icon, title, detail, children }: { id: string; icon: typeof Users; title: string; detail?: ReactNode; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className="space-y-3" data-testid={id}>
      <div>
        <h3 id={id} className="flex items-center gap-2 font-heading text-lg font-bold text-hub-ink"><Icon className="h-5 w-5 text-hub-n-600" aria-hidden="true" />{title}</h3>
        {detail && <p className={SECONDARY}>{detail}</p>}
      </div>
      {children}
    </section>
  );
}

/** The read-only request shape the shared request sheet already knows how to show. */
function asSummary(request: DetailRequest, stationId: number): StationRequestSummary {
  return {
    id: request.id,
    request_number: request.request_number,
    station_id: stationId,
    room_name_snapshot: request.room_name,
    room: null,
    request_type: request.request_type as StationRequestSummary['request_type'],
    title: request.title,
    description: request.description ?? '',
    priority: request.priority as StationRequestSummary['priority'],
    status: request.status as StationRequestSummary['status'],
    is_open: request.is_open,
    current_public_response: request.current_public_response,
    created_at: request.created_at ?? '',
    updated_at: request.updated_at ?? request.created_at ?? '',
    items: request.items.map((item) => ({ ...item, quantity: item.quantity ?? undefined })) as StationRequestSummary['items'],
    updates: request.updates.map((update) => ({ ...update, created_at: update.created_at ?? '', is_auto_receipt: false })) as unknown as StationRequestSummary['updates'],
  };
}

interface Props {
  stationId: number | null;
  /** The name already known from the board, shown while the detail loads. */
  stationName: string;
  resource: PolledResource<StationDetail>;
  onClose: () => void;
  returnFocusTo: () => HTMLElement | null;
}

/**
 * A station for the 300, read only: who is on shift, each truck's checkout, the station requests with their updates
 * and the open vehicle issues. Checkouts and requests open one level further; nothing here can change anything.
 */
export default function StationDetailSheet({ stationId, stationName, resource, onClose, returnFocusTo }: Props) {
  const { data, loading, error, stale, retry } = resource;
  const [openCheckout, setOpenCheckout] = useState<StationCheckoutRow | null>(null);
  const [openRequest, setOpenRequest] = useState<StationRequestSummary | null>(null);
  const opener = useRef<HTMLElement | null>(null);
  const remember = (event: MouseEvent<HTMLElement>) => { opener.current = event.currentTarget; };
  const returnToOpener = useMemo(() => () => opener.current, []);

  const close = () => { setOpenCheckout(null); setOpenRequest(null); onClose(); };

  return (
    <Sheet
      open={stationId !== null}
      onClose={close}
      returnFocusTo={returnFocusTo}
      testId="command-station-sheet"
      title={data?.station.name ?? stationName}
      description={data ? `Read only · updated ${clockTime(data.as_of)}` : 'Read only'}
    >
      {!data && error && <LoadError title="The station could not be loaded" message={error} onRetry={retry} />}
      {!data && !error && (loading || stationId !== null) && <SkeletonRows rows={4} />}
      {data && stationId !== null && (
        <div className="space-y-8" data-testid="command-station-detail">
          {stale && <p role="status" className="hub-callout hub-callout--warning p-3 text-sm">This station could not be refreshed and may be out of date.</p>}

          <Section id="command-detail-crew" icon={Users} title="On shift" detail={data.crew.available ? [data.crew.shift ? `${data.crew.shift} Shift` : null, data.crew.date_label, data.crew.window].filter(Boolean).join(' · ') : undefined}>
            {!data.crew.available ? (
              <EmptyState icon={<Users className="hub-empty__icon" aria-hidden="true" />} title="Roster not available yet" body={data.crew.message ?? "Today's roster has not arrived yet."} />
            ) : data.crew.units.length === 0 ? (
              <EmptyState icon={<Users className="hub-empty__icon" aria-hidden="true" />} title="Nobody listed" body="Today's roster has no units under this station." />
            ) : (
              <div className="grid gap-3 sm:grid-cols-2">
                {data.crew.units.map((unit) => (
                  <div key={unit.unit} className="hub-well min-w-0 p-3">
                    <h4 className="font-heading text-base font-semibold text-hub-ink">{unit.unit}</h4>
                    <ul className="mt-1 divide-y divide-hub-glass-border">
                      {unit.members.map((member, index) => (
                        <li key={index} className="flex items-start justify-between gap-2 py-2" data-testid="command-crew-member">
                          <div className="min-w-0">
                            <p className={`break-words text-sm font-semibold ${member.is_vacant ? 'text-hub-n-600' : 'text-hub-ink'}`}>{member.is_vacant ? 'Vacant' : readableName(member.name)}</p>
                            <p className={`tabular-nums ${SECONDARY}`}>{[member.rank, member.from && member.through ? `${member.from} to ${member.through}` : null].filter(Boolean).join(' · ')}</p>
                          </div>
                          {!member.is_vacant && (
                            <Tag tone={PRESENCE_TONE[member.presence]}>{member.presence === 'working' ? PRESENCE_LABEL.working : `${PRESENCE_LABEL[member.presence]} · ${member.status_label}`}</Tag>
                          )}
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            )}
            {data.crew.notice && <p className="hub-well p-3 text-sm text-hub-ink" role="status">{data.crew.notice}</p>}
          </Section>

          <Section id="command-detail-checkouts" icon={Truck} title="Truck checkouts" detail="This shift, 7 AM to 7 AM. Select a submitted checkout to read it, with that vehicle's issues.">
            {data.units.length === 0 ? (
              <EmptyState icon={<ClipboardCheck className="hub-empty__icon" aria-hidden="true" />} title="No trucks to check out" body="No apparatus at this station owes a daily checkout." />
            ) : (
              <ul className="space-y-2">
                {data.units.map((unit) => <CheckoutRow key={unit.apparatus_id} unit={unit} onOpen={(event, row) => { remember(event); setOpenCheckout(row); }} />)}
              </ul>
            )}
          </Section>

          <Section id="command-detail-requests" icon={ClipboardList} title="Station requests" detail={`${data.requests_total} open, plus any from the last 24 hours. Newest first.`}>
            {data.requests.length === 0 ? (
              <EmptyState icon={<ClipboardList className="hub-empty__icon" aria-hidden="true" />} title="No station requests" body="Open requests and anything new in the last day appear here." />
            ) : (
              <ul className="space-y-2">
                {data.requests.map((request) => (
                  <li key={request.id}>
                    <button type="button" className={ROW} data-testid="command-request-row" onClick={(event) => { remember(event); setOpenRequest(asSummary(request, data.station.id)); }}>
                      <span className="min-w-0 flex-1">
                        <span className="block break-words font-semibold text-hub-ink">{request.title}</span>
                        <span className={`block ${SECONDARY}`}>
                          {[request.room_name || 'Station-wide', request.created_at ? `Submitted ${ageLabel(request.created_at) || formatHubDate(request.created_at)}` : null].filter(Boolean).join(' · ')}
                        </span>
                        {request.updates.length > 0 && (
                          <span className={`block break-words ${SECONDARY}`}>
                            <span className="font-semibold text-hub-ink">Latest update:</span> {request.updates[request.updates.length - 1].public_note || request.updates[request.updates.length - 1].status}
                          </span>
                        )}
                      </span>
                      <span className="flex flex-none flex-col items-end gap-1">
                        <StatusTag status={request.status} />
                        {request.priority === 'high' || request.priority === 'critical' ? <StatusTag status={request.priority} suffix="priority" /> : null}
                      </span>
                      <ChevronRight className="h-4 w-4 flex-none text-hub-n-500" aria-hidden="true" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </Section>

          <Section id="command-detail-issues" icon={Wrench} title="Vehicle issues" detail="Open issues and service tickets, by vehicle.">
            {data.vehicle_issues.length === 0 ? (
              <EmptyState icon={<Check className="hub-empty__icon" aria-hidden="true" />} title="No open vehicle issues" body="Nothing is open on this station's vehicles." />
            ) : (
              <div className="space-y-4">
                {data.vehicle_issues.map((group) => (
                  <div key={group.apparatus_id} data-testid="command-issue-group">
                    <h4 className="mb-2 font-heading text-base font-semibold text-hub-ink">
                      {group.designation}
                      <span className={`ml-2 font-normal ${SECONDARY}`}>{group.defect_count + group.ticket_count} open</span>
                    </h4>
                    <VehicleIssues issues={group} />
                  </div>
                ))}
              </div>
            )}
          </Section>
        </div>
      )}

      <CheckoutSheet<CommandCheckoutDetail>
        stationId={stationId ?? 0}
        row={openCheckout}
        layer={1}
        load={fetchCommandCheckout}
        onClose={() => setOpenCheckout(null)}
        returnFocusTo={returnToOpener}
        renderExtra={(detail) => (
          <section aria-labelledby="command-checkout-issues" className="mb-5 space-y-2" data-testid="command-checkout-issues">
            <h3 id="command-checkout-issues" className="font-heading text-base font-semibold text-hub-ink">Open issues on this vehicle</h3>
            {detail.issues.defect_count + detail.issues.ticket_count === 0
              ? <p className="text-sm text-hub-n-600">No open issues or service tickets.</p>
              : <VehicleIssues issues={detail.issues} showOrigin />}
          </section>
        )}
      />
      <StationRequestSheet request={openRequest} layer={1} onClose={() => setOpenRequest(null)} returnFocusTo={returnToOpener} />
    </Sheet>
  );
}

function CheckoutRow({ unit, onOpen }: { unit: DetailUnit; onOpen: (event: MouseEvent<HTMLElement>, row: StationCheckoutRow) => void }) {
  const row = unit.checkout;
  const Icon = unit.state === 'submitted' ? Check : unit.state === 'out_of_service' ? Ban : Circle;
  const body = (
    <>
      <Icon className={`h-5 w-5 flex-none ${unit.state === 'submitted' ? 'text-hub-success' : unit.state === 'out_of_service' ? 'text-hub-n-500' : 'text-hub-warning'}`} aria-hidden="true" />
      <span className="min-w-0 flex-1">
        <span className="block font-semibold text-hub-ink">{unit.designation}{unit.type && <span className={`ml-2 font-normal ${SECONDARY}`}>{unit.type}</span>}</span>
        <span className={`block break-words ${SECONDARY}`}>
          {row ? `${row.submitted_by ?? 'Submitter not recorded'}${row.shift ? ` · ${row.shift} Shift` : ''}${row.submitted_at ? ` · ${clockTime(row.submitted_at)}` : ''}` : unit.state === 'out_of_service' ? 'Out of service' : 'Not submitted yet'}
        </span>
      </span>
      <span className="flex flex-none flex-col items-end gap-1">
        {unit.state === 'submitted' ? <Tag tone={row?.needs_attention ? 'warning' : 'success'}>{row?.needs_attention ? row.status : 'Submitted'}</Tag> : unit.state === 'out_of_service' ? <StatusTag status="out_of_service" /> : <Tag tone="warning">Pending</Tag>}
        {unit.open_issues > 0 && <Tag tone="warning">{unit.open_issues} {unit.open_issues === 1 ? 'issue' : 'issues'}</Tag>}
      </span>
    </>
  );
  if (!row) return <li className="hub-well flex min-h-14 items-center gap-3 px-3 py-2.5 sm:px-4" data-testid="command-checkout-row" data-state={unit.state}>{body}</li>;
  return (
    <li>
      <button type="button" className={ROW} data-testid="command-checkout-row" data-state={unit.state} onClick={(event) => onOpen(event, row)}>
        {body}
        <ChevronRight className="h-4 w-4 flex-none text-hub-n-500" aria-hidden="true" />
      </button>
    </li>
  );
}
