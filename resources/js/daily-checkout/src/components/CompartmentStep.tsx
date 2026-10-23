import { useEffect, useRef, useState, type Dispatch, type SetStateAction } from 'react';
import type { ChecklistData, ChecklistItem, Compartment } from '../types';
import { resolveBlueprint } from '../data/apparatusBlueprints';
import { findingsForArea, inspectionProgress, itemIsComplete } from '../utils/inspectionProgress';
import { previousIssueHeadline, previousIssueStatusLine, resolveFindingTargets, targetsForItem, unlocatedFindings as findUnlocatedFindings, formatReportedDate } from '../utils/previousFindings';
import { ArrowRight, Check, ChevronDown, ClipboardList, Package, TriangleAlert, X } from 'lucide-react';
import ApparatusBlueprint from './ApparatusBlueprint';
import InventoryCorrectionForm from './InventoryCorrectionForm';
import { correctionLink, emptyCorrectionForm, markerForItem, questionFor, unlistedMarkers, type CorrectionForm, type InventoryCorrectionContext } from '../utils/inventoryCorrections';
import './inspection-workspace.css';

interface Props {
  checklistType?: string;
  compartments: Compartment[];
  findings?: ChecklistData['open_findings'];
  onChange: Dispatch<SetStateAction<Compartment[]>>;
  onSubmit: (compartments: Compartment[]) => void;
  onBack: () => void;
  backLabel?: string;
  actionLabel: string;
  /** Present when this apparatus accepts inventory correction reports (checklist version, draft id, markers). */
  inventory?: InventoryCorrectionContext;
}

/** Answers replaced by "Confirm all present", kept so the member can undo the batch for this area. */
interface BatchUndo {
  compartmentId: string;
  previous: Array<Pick<ChecklistItem, 'id' | 'status' | 'observed'>>;
}

const needsValue = (item: ChecklistItem) => Boolean(item.inputType && item.inputType !== 'checkbox');
const isNumeric = (item: ChecklistItem) => item.inputType === 'number' || item.inputType === 'percentage';
const percentageOutOfRange = (item: ChecklistItem) => item.inputType === 'percentage' && typeof item.value === 'number' && (item.value < 0 || item.value > 100);
/** The batch only marks plain present/absent items that have not been reported or still need a typed value. */
const batchEligible = (item: ChecklistItem) => !((item.observed && item.status !== 'Present') || (needsValue(item) && !itemIsComplete(item)));
/** Leaving Damaged clears the significant-damage claim in the same update. */
const statusPatch = (status: ChecklistItem['status']): Partial<ChecklistItem> => status === 'Damaged' ? { status, observed: true } : { status, observed: true, significant: undefined };

export default function CompartmentStep({ checklistType, compartments, findings = [], onChange, onSubmit, onBack, actionLabel, inventory }: Props) {
  const profile = resolveBlueprint(checklistType);
  const mappedViews = profile?.views.filter(view => view.zones.some(zone => compartments.some(compartment => compartment.id === zone.compartmentId))) ?? [];
  const [activeId, setActiveId] = useState(mappedViews[0]?.zones.find(zone => compartments.some(compartment => compartment.id === zone.compartmentId))?.compartmentId ?? compartments[0]?.id ?? '');
  const [viewId, setViewId] = useState(mappedViews[0]?.id ?? 'all');
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [quickOpen, setQuickOpen] = useState(false);
  const [photoError, setPhotoError] = useState<string | null>(null);
  const [focusItem, setFocusItem] = useState<string | null>(null);
  const [pickerCollapsed, setPickerCollapsed] = useState(false);
  const [focusWorkspace, setFocusWorkspace] = useState(false);
  const [batchUndo, setBatchUndo] = useState<BatchUndo | null>(null);
  const [announcement, setAnnouncement] = useState('');
  // Inventory correction reports in progress, keyed by item or compartment so closing a panel or changing area keeps what was typed.
  const [correctionForms, setCorrectionForms] = useState<Record<string, CorrectionForm>>({});
  // Bumped to clear a photo picker after a removal or a rejected file, so the same file can be chosen again.
  const [photoResets, setPhotoResets] = useState<Record<string, number>>({});
  const workspaceRef = useRef<HTMLDivElement>(null);
  const quickButtonRef = useRef<HTMLButtonElement>(null);
  const quickDialogRef = useRef<HTMLElement>(null);
  const navigationRef = useRef<HTMLElement>(null);
  const current = compartments.find(compartment => compartment.id === activeId) ?? compartments[0];
  const view = mappedViews.find(entry => entry.id === viewId) ?? mappedViews[0];
  const selectedZone = view?.zones.find(zone => zone.compartmentId === current?.id);
  const visibleZones = view?.zones.filter(zone => compartments.some(compartment => compartment.id === zone.compartmentId)) ?? [];
  const showBlueprint = profile !== null && mappedViews.length > 0;
  const progress = inspectionProgress(compartments);
  const compartmentProgress = current ? inspectionProgress([current]) : null;
  const findingTargets = resolveFindingTargets(compartments, findings);
  const unlocatedFindings = findUnlocatedFindings(findingTargets, findings);
  const mappedIds = new Set(mappedViews.flatMap(entry => entry.zones.map(zone => zone.compartmentId)));
  const otherAreas = compartments.filter(compartment => !mappedIds.has(compartment.id));
  const navigationIds = Array.from(new Set([...mappedViews.flatMap(entry => entry.zones.map(zone => zone.compartmentId)), ...otherAreas.map(area => area.id)]));
  const remaining = compartments.flatMap(compartment => compartment.items
    .filter(item => !itemIsComplete(item))
    .map(item => ({ compartment, item })));
  const attention = compartments.flatMap(compartment => compartment.items
    .map(item => ({ compartment, item, known: targetsForItem(findingTargets, compartment.id, item.id).map(target => target.finding) }))
    .filter(({ item, known }) => (item.observed && item.status !== 'Present') || known.length > 0));

  const areaButton = (compartment: Compartment) => {
    const state = inspectionProgress([compartment]);
    const existing = findingsForArea(compartment, findings, compartments).length;
    return <button key={compartment.id} data-area-id={compartment.id} type="button" aria-pressed={compartment.id === current?.id} onClick={() => select(compartment.id)}>
      <span className={`area-state ${state.remaining === 0 ? 'is-complete' : ''}`} aria-hidden="true">{state.remaining === 0 ? <Check size={18} /> : <span className="area-open" />}</span>
      <span className="area-name">{compartment.name}{compartment.id === current?.id && <small className="area-selected">Selected</small>}{(existing > 0 || state.issues > 0) && <small className="area-warning"><TriangleAlert size={13} aria-hidden="true" />{existing > 0 ? `${existing} existing` : ''}{existing > 0 && state.issues > 0 ? ' · ' : ''}{state.issues > 0 ? `${state.issues} reported` : ''}</small>}</span>
      <small className="area-progress">{state.completed}/{state.total}</small>
    </button>;
  };

  useEffect(() => {
    navigationRef.current?.querySelector<HTMLElement>(`.rescue-area-rail [data-area-id="${CSS.escape(activeId)}"]`)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }, [activeId]);

  useEffect(() => {
    if (!focusItem) return;
    const element = workspaceRef.current?.querySelector<HTMLElement>(`[data-item-id="${CSS.escape(focusItem)}"]`);
    element?.focus();
    element?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
    setFocusItem(null);
  }, [focusItem, activeId]);

  useEffect(() => {
    if (!focusWorkspace) return;
    // CSS determines whether this container has room for a simultaneous picker.
    if (navigationRef.current && getComputedStyle(navigationRef.current).display === 'none') {
      workspaceRef.current?.focus({ preventScroll: true });
      workspaceRef.current?.querySelector('header')?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
    }
    setFocusWorkspace(false);
  }, [focusWorkspace, pickerCollapsed, activeId]);

  // The Quick Checklist is a non-modal dialog: focus moves into it when it opens.
  useEffect(() => {
    if (quickOpen) quickDialogRef.current?.focus();
  }, [quickOpen]);

  const closeQuickChecklist = () => {
    setQuickOpen(false);
    quickButtonRef.current?.focus();
  };

  const select = (id: string, itemId?: string) => {
    const nextView = mappedViews.find(entry => entry.zones.some(zone => zone.compartmentId === id));
    if (nextView) setViewId(nextView.id);
    setActiveId(id);
    setExpandedId(itemId ?? null);
    setQuickOpen(false);
    setPickerCollapsed(true);
    setBatchUndo(null);
    if (itemId) setFocusItem(itemId);
    else setFocusWorkspace(true);
  };

  const update = (compartmentId: string, itemId: string, patch: Partial<ChecklistItem>) => {
    onChange(previous => previous.map(compartment => compartment.id === compartmentId
      ? { ...compartment, items: compartment.items.map(item => item.id === itemId ? { ...item, ...patch } : item) }
      : compartment));
  };

  const confirmAllPresent = (area: Compartment) => {
    const changed = area.items.filter(item => batchEligible(item) && !(item.status === 'Present' && item.observed === true));
    // A repeat tap that changes nothing keeps the existing Undo for the first batch.
    if (changed.length > 0) {
      setBatchUndo({ compartmentId: area.id, previous: changed.map(({ id, status, observed }) => ({ id, status, observed })) });
      setAnnouncement(`${changed.length} ${changed.length === 1 ? 'item' : 'items'} marked present in ${area.name}.`);
    }
    onChange(previous => previous.map(compartment => compartment.id === area.id ? { ...compartment, items: compartment.items.map(item => batchEligible(item) ? { ...item, status: 'Present', observed: true } : item) } : compartment));
  };

  const undoBatch = () => {
    if (!batchUndo) return;
    const previousById = new Map(batchUndo.previous.map(entry => [entry.id, entry]));
    // Only answers the batch set are restored; anything the member changed afterwards is kept.
    onChange(previous => previous.map(compartment => compartment.id === batchUndo.compartmentId ? {
      ...compartment,
      items: compartment.items.map(item => {
        const before = previousById.get(item.id);
        return before && item.status === 'Present' && item.observed === true ? { ...item, status: before.status, observed: before.observed } : item;
      }),
    } : compartment));
    setBatchUndo(null);
    setAnnouncement('Confirm all present was undone.');
  };

  const correctionFormFor = (key: string, expectedQuantity?: number): CorrectionForm => correctionForms[key] ?? emptyCorrectionForm(expectedQuantity);
  const patchCorrectionForm = (key: string, expectedQuantity?: number) => (patch: Partial<CorrectionForm>) => {
    setCorrectionForms(previous => ({ ...previous, [key]: { ...(previous[key] ?? emptyCorrectionForm(expectedQuantity)), ...patch } }));
  };

  const resetPhotoInput = (itemId: string) => setPhotoResets(previous => ({ ...previous, [itemId]: (previous[itemId] ?? 0) + 1 }));

  const removePhoto = (compartmentId: string, itemId: string) => {
    update(compartmentId, itemId, { photo: undefined });
    resetPhotoInput(itemId);
    setAnnouncement('Photo removed.');
    // The Remove button unmounts with the preview; keep keyboard focus on the photo picker.
    requestAnimationFrame(() => document.getElementById(`photo-${itemId}`)?.focus());
  };

  const capturePhoto = (compartmentId: string, itemId: string, file?: File) => {
    if (!file) return;
    if (!file.type.startsWith('image/') || file.size > 5_000_000) {
      setPhotoError('Choose an image smaller than 5 MB. Your inspection answers are still saved.');
      resetPhotoInput(itemId);
      return;
    }
    setPhotoError(null);
    const reader = new FileReader();
    reader.onload = () => update(compartmentId, itemId, { photo: String(reader.result) });
    reader.onerror = () => setPhotoError('The image could not be read. Choose it again.');
    reader.readAsDataURL(file);
  };

  const unlistedPending = inventory && current ? unlistedMarkers(inventory.markers, current.id) : { pending: 0, question: false };

  if (!current || !compartmentProgress) return <div data-hub-ui="2" role="alert" className="inspection-alert">No checklist items available. <button type="button" className="hub-btn hub-btn--secondary ml-2" onClick={onBack}>Member / Vehicle Info</button></div>;

  return <div data-hub-ui="2" className={`inspection-layout ${showBlueprint ? 'with-blueprint' : 'with-area-rail'} ${pickerCollapsed ? 'picker-collapsed' : ''}`}>
    <div className="inspection-area-summary inspection-panel">
      <div><strong>{selectedZone ? `${view.label} · ${selectedZone.label}` : current.name}</strong><span>{compartmentProgress.completed}/{compartmentProgress.total} inspected{compartmentProgress.issues > 0 ? ` · ${compartmentProgress.issues} reported` : ''}</span></div>
      <button type="button" className="hub-btn hub-btn--tertiary" aria-expanded={!pickerCollapsed} aria-controls="inspection-area-picker" onClick={() => {
        setPickerCollapsed(false);
        requestAnimationFrame(() => {
          const selected = navigationRef.current?.querySelector<HTMLButtonElement>('button[aria-pressed="true"]');
          selected?.focus({ preventScroll: true });
          navigationRef.current?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
        });
      }}>Change area</button>
    </div>
    <aside id="inspection-area-picker" className="inspection-navigation inspection-panel" aria-label="Apparatus navigation" ref={navigationRef}>
      <div className="inspection-panel-heading"><h2>Vehicle Areas</h2><div className="blueprint-legend"><span><i className="remaining-dot" />Remaining</span><span><Check size={13} aria-hidden="true" />Inspected</span><span><b aria-hidden="true">E</b> Existing</span><span><b aria-hidden="true">R</b> Reported</span></div></div>
      {showBlueprint && view ? <>
        <div className="blueprint-views" aria-label="Apparatus views">
          {mappedViews.map(entry => <button key={entry.id} type="button" aria-pressed={entry.id === view.id} onClick={() => { setViewId(entry.id); setActiveId(entry.zones.find(zone => compartments.some(compartment => compartment.id === zone.compartmentId))!.compartmentId); setExpandedId(null); setBatchUndo(null); }}>{entry.label}</button>)}
        </div>
        <ApparatusBlueprint view={view} compartments={compartments} activeId={current.id} findings={findings} onSelect={select} />
        {/* The keyboard path for diagram areas: one tab stop per area (the diagram zones are pointer targets). */}
        <div className="blueprint-touch-zones" aria-label="Select a diagram area">{visibleZones.map(zone => {
          const area = compartments.find(compartment => compartment.id === zone.compartmentId)!;
          const state = inspectionProgress([area]);
          const known = findingsForArea(area, findings, compartments).length;
          return <button key={zone.compartmentId} data-area-id={zone.compartmentId} type="button" aria-label={`${zone.label}: ${area.name}, ${state.completed} of ${state.total} inspected${known ? `, ${known} existing issues` : ''}${state.issues ? `, ${state.issues} reported issues` : ''}`} aria-pressed={current.id === zone.compartmentId} onClick={() => select(zone.compartmentId)}><span>{state.remaining === 0 && <Check size={13} className="area-state is-complete" aria-hidden="true" />}{zone.label}{(known > 0 || state.issues > 0) && <TriangleAlert size={13} className="area-warning" aria-hidden="true" />}</span><small>{current.id === zone.compartmentId ? 'Selected' : state.remaining === 0 ? 'Complete' : `${state.remaining} left`}</small></button>;
        })}</div>
        <div className="blueprint-findings">{visibleZones.map(zone => {
          const area = compartments.find(compartment => compartment.id === zone.compartmentId)!;
          const existing = findingsForArea(area, findings, compartments).length;
          const reported = inspectionProgress([area]).issues;
          return existing > 0 || reported > 0 ? <p key={area.id}><TriangleAlert size={14} aria-hidden="true" /><span>{zone.label} · {existing > 0 ? `${existing} existing issue${existing === 1 ? '' : 's'}` : ''}{existing > 0 && reported > 0 ? ' · ' : ''}{reported > 0 ? `${reported} reported` : ''}</span></p> : null;
        })}</div>
        {otherAreas.length > 0 && <details className="inspection-other-areas" open={!mappedIds.has(current.id) || undefined}>
          <summary>Other areas <span>{otherAreas.length}</span><ChevronDown size={16} aria-hidden="true" /></summary>
          <section className="inspection-area-list" aria-label="Other areas">{otherAreas.map(areaButton)}</section>
        </details>}
      </> : <section className="inspection-area-list rescue-area-rail" aria-label="Inspection areas">{compartments.map(areaButton)}</section>}
      {unlocatedFindings.length > 0 && <details className="inspection-unlocated-findings">
        <summary>Other recorded findings ({unlocatedFindings.length})</summary>
        <p>These findings remain open. Verify the recorded location or duty before acting.</p>
        <ul>{unlocatedFindings.map(finding => <li key={finding.id}><strong>{finding.item}</strong> · {finding.compartment} · {finding.issue_type}</li>)}</ul>
      </details>}
    </aside>

    <section className="inspection-equipment inspection-panel" aria-label="Compartment Inspection" ref={workspaceRef} tabIndex={-1}>
      <header className="equipment-heading">
        <div><p className="inspection-eyebrow">{showBlueprint && !mappedIds.has(current.id) ? 'Other area · Not shown on vehicle image' : 'Compartment Inspection'}</p><h2>{current.name}</h2><p className="inspection-muted">{compartmentProgress.completed} of {compartmentProgress.total} inspected{compartmentProgress.issues > 0 ? ` · ${compartmentProgress.issues} reported` : ''}</p></div>
        <button type="button" className="hub-btn hub-btn--secondary hub-btn--wrap confirm-compartment" onClick={() => confirmAllPresent(current)}><Check size={18} aria-hidden="true" />Confirm all present</button>
      </header>
      <p role="status" className="sr-only">{announcement}</p>
      {batchUndo?.compartmentId === current.id && <div className="inspection-batch-undo">
        <p>{batchUndo.previous.length} {batchUndo.previous.length === 1 ? 'item' : 'items'} marked present in {current.name}.</p>
        <button type="button" className="hub-btn hub-btn--tertiary" onClick={undoBatch}>Undo</button>
      </div>}
      {photoError && <p role="alert" className="inspection-alert">{photoError}</p>}
      <div className="equipment-list">
        {current.items.map(item => {
          const expanded = expandedId === item.id;
          const issue = item.observed && item.status !== 'Present';
          const known = targetsForItem(findingTargets, current.id, item.id).map(target => target.finding);
          const markedPresent = item.observed === true && item.status === 'Present';
          const actionLabel = needsValue(item) ? 'Record' : 'Pass';
          const outOfRange = percentageOutOfRange(item);
          const correctionMarker = inventory ? markerForItem(inventory.markers, current.id, item.id) : null;
          const itemSpec = [item.manufacturer, item.model, item.description].filter(Boolean).join(' · ');
          const correctionKey = `item:${current.id}:${item.id}`;
          return <article key={item.id} className={`equipment-row ${issue ? 'has-issue' : ''} ${expanded ? 'is-expanded' : ''}`}>
            <div className="equipment-summary">
              <button type="button" className="equipment-expand" data-item-id={item.id} aria-expanded={expanded} aria-controls={`details-${current.id}-${item.id}`} onClick={() => setExpandedId(expanded ? null : item.id)}>
                <Package size={20} aria-hidden="true" />
                <span><strong>{item.name}</strong><small>{item.expectedQuantity !== undefined ? `Expected quantity: ${item.expectedQuantity} · ` : ''}{issue ? `${item.status} · Open to review` : item.observed ? 'Inspected' : 'Needs inspection'}</small></span>
                <span className="equipment-chevron" aria-hidden="true">{expanded ? '−' : '+'}</span>
              </button>
              <button type="button" className={`equipment-pass ${itemIsComplete(item) && !issue ? 'is-passed' : ''}`} aria-label={`${actionLabel} ${item.name}`} aria-pressed={itemIsComplete(item) && item.status === 'Present'} onClick={() => { if (needsValue(item) && !itemIsComplete({ ...item, status: 'Present', observed: true })) { setExpandedId(item.id); } else { update(current.id, item.id, statusPatch('Present')); } }}><Check size={16} aria-hidden="true" /><span>{actionLabel}</span></button>
            </div>
            {correctionMarker && <div className="equipment-markers" data-correction-marker={correctionMarker}>
              {correctionMarker === 'question'
                ? <><span className="hub-tag hub-tag--warning">Logistics asked a question</span><a className="ic-link" href={correctionLink(questionFor(inventory!.markers, current.id, item.id))}>Open the question</a></>
                : <span className="hub-tag hub-tag--info">Correction pending review</span>}
            </div>}
            {known.length > 0 && <div className="equipment-known" data-previous-issues={item.id}>
              {known.map(finding => {
                const memberUpdate = finding.latest_member_update;
                const updatedOn = formatReportedDate(memberUpdate?.at);
                return <div key={finding.id} className="equipment-known-entry">
                  <p><TriangleAlert size={14} aria-hidden="true" /><strong>{previousIssueHeadline(finding)}</strong></p>
                  <p>{previousIssueStatusLine(finding)}{finding.last_observation === 'appears_corrected' ? ' · Last reported present' : ''}</p>
                  {memberUpdate?.text && <p className="equipment-known-update">Latest update{updatedOn ? ` (${updatedOn})` : ''}: {memberUpdate.text}</p>}
                </div>;
              })}
              {markedPresent && <p role="status" className="equipment-known-note">Marked present. Logistics will verify the correction.</p>}
            </div>}
            {expanded && <div className="equipment-details" id={`details-${current.id}-${item.id}`}>
              {item.instructions && <p className="equipment-instructions">{item.instructions}</p>}
              {itemSpec && <p className="equipment-spec">{itemSpec}</p>}
              <div className="equipment-status" role="group" aria-label={`Status for ${item.name}`}>
                {(['Present', 'Missing', 'Damaged'] as const).map(status => <button key={status} type="button" aria-pressed={item.observed === true && item.status === status} onClick={() => update(current.id, item.id, statusPatch(status))}>{status}</button>)}
              </div>
              {item.observed === true && item.status === 'Damaged' && <label className="equipment-significant" htmlFor={`significant-${item.id}`}>
                <input id={`significant-${item.id}`} type="checkbox" checked={item.significant === true} onChange={event => update(current.id, item.id, { significant: event.target.checked ? true : undefined })} />
                <span>Significant damage (new, affects safe operation)</span>
              </label>}
              {issue && <p className="equipment-issue-copy">Add a note or photo if it helps describe what you found.</p>}
              {needsValue(item) && <label htmlFor={`value-${item.id}`}>
                {item.name}
                <input id={`value-${item.id}`} type={isNumeric(item) ? 'number' : item.inputType === 'date' ? 'date' : 'text'}
                  inputMode={isNumeric(item) ? 'decimal' : undefined}
                  step={isNumeric(item) ? 'any' : undefined}
                  min={item.inputType === 'percentage' ? 0 : undefined}
                  max={item.inputType === 'percentage' ? 100 : undefined}
                  maxLength={isNumeric(item) ? undefined : 2000}
                  aria-describedby={item.inputType === 'percentage' ? `value-hint-${item.id}` : undefined}
                  aria-invalid={outOfRange || undefined}
                  value={item.value === null || item.value === undefined ? '' : String(item.value)}
                  onChange={event => update(current.id, item.id, { value: isNumeric(item) ? event.target.value === '' ? null : Number(event.target.value) : event.target.value, observed: true })} />
              </label>}
              {item.inputType === 'percentage' && <p id={`value-hint-${item.id}`} className={`inspection-field-hint ${outOfRange ? 'is-invalid' : ''}`}>{outOfRange ? 'Enter a percentage from 0 to 100.' : 'Percentage, 0 to 100.'}</p>}
              <label htmlFor={`note-${item.id}`}>Notes (optional)</label>
              <textarea id={`note-${item.id}`} rows={2} value={item.notes ?? ''} onChange={event => update(current.id, item.id, { notes: event.target.value })} maxLength={2000} />
              <label htmlFor={`photo-${item.id}`}>Photo (optional)</label>
              {/* No forced capture: phones offer the camera or the photo library. The key resets the picker after a change. */}
              <input key={`photo-${item.id}-${photoResets[item.id] ?? 0}`} id={`photo-${item.id}`} type="file" accept="image/*" onChange={event => capturePhoto(current.id, item.id, event.target.files?.[0])} />
              {item.photo && <div className="inspection-photo-preview">
                <img src={item.photo} alt={`Photo of ${item.name}`} className="inspection-photo" />
                <button type="button" className="hub-btn hub-btn--destructive" aria-label={`Remove photo of ${item.name}`} onClick={() => removePhoto(current.id, item.id)}>Remove photo</button>
              </div>}
              {inventory && <InventoryCorrectionForm mode="item" fieldId={`ic-${current.id}-${item.id}`} compartment={current} compartments={compartments} item={item}
                form={correctionFormFor(correctionKey, item.expectedQuantity)} onForm={patchCorrectionForm(correctionKey, item.expectedQuantity)} context={inventory} />}
            </div>}
          </article>;
        })}
      </div>
      {inventory && <div className="equipment-unlisted">
        <InventoryCorrectionForm mode="new_item" fieldId={`ic-new-${current.id}`} compartment={current} compartments={compartments}
          form={correctionFormFor(`new:${current.id}`)} onForm={patchCorrectionForm(`new:${current.id}`)} context={inventory} />
        {unlistedPending.pending > 0 && <p className="inspection-muted" data-unlisted-pending>
          {unlistedPending.question ? 'Logistics asked a question about an unlisted item reported here. ' : `${unlistedPending.pending} unlisted ${unlistedPending.pending === 1 ? 'item' : 'items'} reported here, pending review. `}
          {unlistedPending.question && <a className="ic-link" href={correctionLink(questionFor(inventory.markers, current.id, null))}>Open the question</a>}
        </p>}
      </div>}
      <footer className="inspection-compartment-footer"><span>Area {navigationIds.indexOf(current.id) + 1} of {navigationIds.length}</span></footer>
    </section>

    <div className="inspection-action-bar">
      <button ref={quickButtonRef} type="button" className="quick-checklist-trigger" aria-expanded={quickOpen} aria-controls="quick-checklist" onClick={() => setQuickOpen(!quickOpen)}><ClipboardList size={20} aria-hidden="true" /><span>Checklist <strong>{progress.remaining > 0 ? `${progress.remaining} remaining` : 'Equipment inspected'}</strong>{attention.length > 0 && <small>{attention.length} needs attention</small>}</span></button>
      {progress.remaining > 0 ? <button type="button" className="hub-btn hub-btn--primary hub-btn--wrap inspection-review-button" onClick={() => select(navigationIds[(navigationIds.indexOf(current.id) + 1) % navigationIds.length])}>Next area<ArrowRight size={17} aria-hidden="true" /></button>
        : <button type="button" className="hub-btn hub-btn--primary hub-btn--wrap inspection-review-button" disabled={progress.total === 0} onClick={() => onSubmit(compartments)}>{actionLabel}<ArrowRight size={17} aria-hidden="true" /></button>}
    </div>
    {quickOpen && <aside ref={quickDialogRef} id="quick-checklist" className="quick-checklist" role="dialog" aria-modal="false" aria-labelledby="quick-checklist-title" tabIndex={-1} onKeyDown={event => { if (event.key === 'Escape') closeQuickChecklist(); }}>
      <header><div><span id="quick-checklist-title" className="inspection-eyebrow">Quick Checklist</span><h2>{progress.remaining === 0 ? 'Equipment inspected' : `${progress.remaining} remaining`}</h2></div><button type="button" aria-label="Close Quick Checklist" onClick={closeQuickChecklist}><X size={22} aria-hidden="true" /></button></header>
      <div className="quick-checklist-body">
        {remaining.length > 0 && <section aria-label="Remaining work"><h3>Remaining ({remaining.length})</h3><ul>{remaining.map(({ compartment, item }) => <li key={`${compartment.id}-${item.id}`}><button type="button" onClick={() => select(compartment.id, item.id)}><span><small>{compartment.name}</small><strong>{item.name}</strong></span><span>Inspect <ArrowRight size={16} aria-hidden="true" /></span></button></li>)}</ul></section>}
        {attention.length > 0 && <section aria-label="Needs attention"><h3>Needs attention ({attention.length})</h3><ul>{attention.map(({ compartment, item, known }) => <li key={`${compartment.id}-${item.id}`}><button type="button" onClick={() => select(compartment.id, item.id)}><span><small>{compartment.name}</small><strong>{item.name}</strong>{known.length > 0 && <small>Existing {known.map(finding => finding.issue_type).join(', ')}</small>}</span><span>{item.observed && item.status !== 'Present' ? `${item.status} · Reported` : 'Existing issue'} <TriangleAlert size={16} aria-hidden="true" /></span></button></li>)}</ul></section>}
        {remaining.length === 0 && attention.length === 0 && <p className="inspection-muted">All equipment inspected. No issues reported.</p>}
      </div>
    </aside>}
  </div>;
}
