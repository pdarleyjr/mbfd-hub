import { useEffect, useRef, useState, type Dispatch, type SetStateAction } from 'react';
import type { ChecklistData, ChecklistItem, Compartment } from '../types';
import { resolveBlueprint } from '../data/apparatusBlueprints';
import { findingsForArea, inspectionProgress, itemIsComplete } from '../utils/inspectionProgress';
import { ArrowRight, Check, ChevronDown, ClipboardList, Package, TriangleAlert, X } from 'lucide-react';
import ApparatusBlueprint from './ApparatusBlueprint';
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
}

export default function CompartmentStep({ checklistType, compartments, findings = [], onChange, onSubmit, onBack, actionLabel }: Props) {
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
  const workspaceRef = useRef<HTMLDivElement>(null);
  const quickButtonRef = useRef<HTMLButtonElement>(null);
  const navigationRef = useRef<HTMLElement>(null);
  const current = compartments.find(compartment => compartment.id === activeId) ?? compartments[0];
  const view = mappedViews.find(entry => entry.id === viewId) ?? mappedViews[0];
  const selectedZone = view?.zones.find(zone => zone.compartmentId === current?.id);
  const visibleZones = view?.zones.filter(zone => compartments.some(compartment => compartment.id === zone.compartmentId)) ?? [];
  const showBlueprint = profile !== null && mappedViews.length > 0;
  const progress = inspectionProgress(compartments);
  const compartmentProgress = current ? inspectionProgress([current]) : null;
  const unlocatedFindings = findings.filter(finding => !compartments.some(compartment => compartment.name === finding.compartment && compartment.items.some(item => item.name === finding.item)));
  const mappedIds = new Set(mappedViews.flatMap(entry => entry.zones.map(zone => zone.compartmentId)));
  const otherAreas = compartments.filter(compartment => !mappedIds.has(compartment.id));
  const navigationIds = Array.from(new Set([...mappedViews.flatMap(entry => entry.zones.map(zone => zone.compartmentId)), ...otherAreas.map(area => area.id)]));
  const remaining = compartments.flatMap(compartment => compartment.items
    .filter(item => !itemIsComplete(item))
    .map(item => ({ compartment, item })));
  const attention = compartments.flatMap(compartment => compartment.items
    .map(item => ({ compartment, item, known: findings.filter(finding => finding.compartment === compartment.name && finding.item === item.name) }))
    .filter(({ item, known }) => (item.observed && item.status !== 'Present') || known.length > 0));

  const areaButton = (compartment: Compartment) => {
    const state = inspectionProgress([compartment]);
    const existing = findingsForArea(compartment, findings).length;
    return <button key={compartment.id} data-area-id={compartment.id} type="button" aria-pressed={compartment.id === current?.id} onClick={() => select(compartment.id)}>
      <span className={`area-state ${state.remaining === 0 ? 'is-complete' : ''}`} aria-hidden="true">{state.remaining === 0 ? <Check size={18} /> : <span className="area-open" />}</span>
      <span className="area-name">{compartment.name}{compartment.id === current?.id && <small className="area-selected">Selected</small>}{(existing > 0 || state.issues > 0) && <small className="area-warning"><TriangleAlert size={13} />{existing > 0 ? `${existing} existing` : ''}{existing > 0 && state.issues > 0 ? ' · ' : ''}{state.issues > 0 ? `${state.issues} reported` : ''}</small>}</span>
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

  const select = (id: string, itemId?: string) => {
    const nextView = mappedViews.find(entry => entry.zones.some(zone => zone.compartmentId === id));
    if (nextView) setViewId(nextView.id);
    setActiveId(id);
    setExpandedId(itemId ?? null);
    setQuickOpen(false);
    setPickerCollapsed(true);
    if (itemId) setFocusItem(itemId);
    else setFocusWorkspace(true);
  };

  const update = (compartmentId: string, itemId: string, patch: Partial<ChecklistItem>) => {
    onChange(previous => previous.map(compartment => compartment.id === compartmentId
      ? { ...compartment, items: compartment.items.map(item => item.id === itemId ? { ...item, ...patch } : item) }
      : compartment));
  };

  const capturePhoto = (compartmentId: string, itemId: string, file?: File) => {
    if (!file) return;
    if (!file.type.startsWith('image/') || file.size > 5_000_000) {
      setPhotoError('Choose an image smaller than 5 MB. Your inspection answers are still saved.');
      return;
    }
    setPhotoError(null);
    const reader = new FileReader();
    reader.onload = () => update(compartmentId, itemId, { photo: String(reader.result) });
    reader.onerror = () => setPhotoError('The image could not be read. Choose it again.');
    reader.readAsDataURL(file);
  };

  if (!current || !compartmentProgress) return <div role="alert">No checklist items available. <button onClick={onBack}>Member / Vehicle Info</button></div>;

  return <div className={`inspection-layout ${showBlueprint ? 'with-blueprint' : 'with-area-rail'} ${pickerCollapsed ? 'picker-collapsed' : ''}`}>
    <div className="inspection-area-summary">
      <div><strong>{selectedZone ? `${view.label} · ${selectedZone.label}` : current.name}</strong><span>{compartmentProgress.completed}/{compartmentProgress.total} inspected{compartmentProgress.issues > 0 ? ` · ${compartmentProgress.issues} reported` : ''}</span></div>
      <button type="button" aria-expanded={!pickerCollapsed} aria-controls="inspection-area-picker" onClick={() => {
        setPickerCollapsed(false);
        requestAnimationFrame(() => {
          const selected = navigationRef.current?.querySelector<HTMLButtonElement>('button[aria-pressed="true"]');
          selected?.focus({ preventScroll: true });
          navigationRef.current?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
        });
      }}>Change area</button>
    </div>
    <aside id="inspection-area-picker" className="inspection-navigation" aria-label="Apparatus navigation" ref={navigationRef}>
      <div className="inspection-panel-heading"><h2>Vehicle Areas</h2><div className="blueprint-legend"><span><i className="remaining-dot" />Remaining</span><span><Check size={13} />Inspected</span><span><b>E</b> Existing</span><span><b>R</b> Reported</span></div></div>
      {showBlueprint && view ? <>
        <div className="blueprint-views" aria-label="Apparatus views">
          {mappedViews.map(entry => <button key={entry.id} type="button" aria-pressed={entry.id === view.id} onClick={() => { setViewId(entry.id); setActiveId(entry.zones.find(zone => compartments.some(compartment => compartment.id === zone.compartmentId))!.compartmentId); setExpandedId(null); }}>{entry.label}</button>)}
        </div>
        <ApparatusBlueprint view={view} compartments={compartments} activeId={current.id} findings={findings} onSelect={select} />
        <div className="blueprint-touch-zones" aria-label="Select a diagram area">{visibleZones.map(zone => {
          const area = compartments.find(compartment => compartment.id === zone.compartmentId)!;
          const state = inspectionProgress([area]);
          const known = findingsForArea(area, findings).length;
          return <button key={zone.compartmentId} data-area-id={zone.compartmentId} type="button" aria-label={`${area.name}, ${state.completed} of ${state.total} inspected${known ? `, ${known} existing issues` : ''}${state.issues ? `, ${state.issues} reported issues` : ''}`} aria-pressed={current.id === zone.compartmentId} onClick={() => select(zone.compartmentId)}><span>{state.remaining === 0 && <Check size={13} className="area-state is-complete" />}{zone.label}{(known > 0 || state.issues > 0) && <TriangleAlert size={13} className="area-warning" />}</span><small>{current.id === zone.compartmentId ? 'Selected' : state.remaining === 0 ? 'Complete' : `${state.remaining} left`}</small></button>;
        })}</div>
        <div className="blueprint-findings">{visibleZones.map(zone => {
          const area = compartments.find(compartment => compartment.id === zone.compartmentId)!;
          const existing = findingsForArea(area, findings).length;
          const reported = inspectionProgress([area]).issues;
          return existing > 0 || reported > 0 ? <p key={area.id}><TriangleAlert size={14} /><span>{zone.label} · {existing > 0 ? `${existing} existing issue${existing === 1 ? '' : 's'}` : ''}{existing > 0 && reported > 0 ? ' · ' : ''}{reported > 0 ? `${reported} reported` : ''}</span></p> : null;
        })}</div>
        {otherAreas.length > 0 && <details className="inspection-other-areas" open={!mappedIds.has(current.id) || undefined}>
          <summary>Other areas <span>{otherAreas.length}</span><ChevronDown size={16} /></summary>
          <section className="inspection-area-list" aria-label="Other areas">{otherAreas.map(areaButton)}</section>
        </details>}
      </> : <section className="inspection-area-list rescue-area-rail" aria-label="Inspection areas">{compartments.map(areaButton)}</section>}
      {unlocatedFindings.length > 0 && <details className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
        <summary className="cursor-pointer font-semibold">Other recorded findings ({unlocatedFindings.length})</summary>
        <p className="mt-2">These findings remain open. Verify the recorded location or duty before acting.</p>
        <ul className="mt-2 space-y-2">{unlocatedFindings.map(finding => <li key={finding.id}><strong>{finding.item}</strong> · {finding.compartment} · {finding.issue_type}</li>)}</ul>
      </details>}
    </aside>

    <section className="inspection-equipment" aria-label="Compartment Inspection" ref={workspaceRef} tabIndex={-1}>
      <header className="equipment-heading">
        <div><p className="inspection-eyebrow">{showBlueprint && !mappedIds.has(current.id) ? 'Other area · Not shown on vehicle image' : 'Compartment Inspection'}</p><h2>{current.name}</h2><p className="inspection-muted">{compartmentProgress.completed} of {compartmentProgress.total} inspected{compartmentProgress.issues > 0 ? ` · ${compartmentProgress.issues} reported` : ''}</p></div>
        <button type="button" className="confirm-compartment" aria-label="Mark all items in this compartment as present" onClick={() => onChange(previous => previous.map(compartment => compartment.id === current.id ? { ...compartment, items: compartment.items.map(item => (item.observed && item.status !== 'Present') || (item.inputType && item.inputType !== 'checkbox' && !itemIsComplete(item)) ? item : { ...item, status: 'Present', observed: true }) } : compartment))}>✓ Confirm all present</button>
      </header>
      {photoError && <p role="alert" className="inspection-alert">{photoError}</p>}
      <div className="equipment-list">
        {current.items.map(item => {
          const expanded = expandedId === item.id;
          const issue = item.observed && item.status !== 'Present';
          const known = findings.find(finding => finding.compartment === current.name && finding.item === item.name);
          return <article key={item.id} className={`equipment-row ${issue ? 'has-issue' : ''} ${expanded ? 'is-expanded' : ''}`}>
            <div className="equipment-summary">
              <button type="button" className="equipment-expand" data-item-id={item.id} aria-expanded={expanded} aria-controls={`details-${current.id}-${item.id}`} onClick={() => setExpandedId(expanded ? null : item.id)}>
                <Package size={20} aria-hidden="true" />
                <span><strong>{item.name}</strong><small>{item.expectedQuantity !== undefined ? `Expected quantity: ${item.expectedQuantity} · ` : ''}{issue ? `${item.status} · Open to review` : item.observed ? 'Inspected' : 'Needs inspection'}</small></span>
                <span className="equipment-chevron" aria-hidden="true">{expanded ? '−' : '+'}</span>
              </button>
              <button type="button" className={`equipment-pass ${itemIsComplete(item) && !issue ? 'is-passed' : ''}`} aria-label={`Pass ${item.name}`} aria-pressed={itemIsComplete(item) && item.status === 'Present'} onClick={() => { if (item.inputType && item.inputType !== 'checkbox' && !itemIsComplete({ ...item, status: 'Present', observed: true })) { setExpandedId(item.id); } else { update(current.id, item.id, { status: 'Present', observed: true }); } }}>✓ <span>{item.inputType && item.inputType !== 'checkbox' ? 'Record' : 'Pass'}</span></button>
            </div>
            {known && <p className="px-4 pb-3 text-sm text-amber-900">Known {known.issue_type}{known.last_observation === 'appears_corrected' ? ' · Last reported present' : ''}</p>}
            {expanded && <div className="equipment-details" id={`details-${current.id}-${item.id}`}>
              {item.instructions && <p className="mb-2 text-sm text-slate-600">{item.instructions}</p>}
              <div className="equipment-status" role="group" aria-label={`Status for ${item.name}`}>
                {(['Present', 'Missing', 'Damaged'] as const).map(status => <button key={status} type="button" aria-pressed={item.observed === true && item.status === status} onClick={() => update(current.id, item.id, { status, observed: true })}>{status}</button>)}
              </div>
              {issue && <p className="equipment-issue-copy">Add a note or photo if it helps describe what you found.</p>}
              {item.inputType && item.inputType !== 'checkbox' && <label htmlFor={`value-${item.id}`}>
                {item.name}
                <input id={`value-${item.id}`} type={item.inputType === 'number' || item.inputType === 'percentage' ? 'number' : item.inputType === 'date' ? 'date' : 'text'}
                  value={item.value === null || item.value === undefined ? '' : String(item.value)} maxLength={2000}
                  className="mt-1 block min-h-12 w-full rounded border border-slate-300 bg-white p-3"
                  onChange={event => update(current.id, item.id, { value: item.inputType === 'number' || item.inputType === 'percentage' ? event.target.value === '' ? null : Number(event.target.value) : event.target.value, observed: true })} />
              </label>}
              <label htmlFor={`note-${item.id}`}>Notes (optional)</label>
              <textarea id={`note-${item.id}`} rows={2} value={item.notes ?? ''} onChange={event => update(current.id, item.id, { notes: event.target.value })} maxLength={2000} />
              <label htmlFor={`photo-${item.id}`}>Photo (optional)</label>
              <input id={`photo-${item.id}`} type="file" accept="image/*" capture="environment" onChange={event => capturePhoto(current.id, item.id, event.target.files?.[0])} />
              {item.photo && <img src={item.photo} alt={`Photo of ${item.name}`} className="inspection-photo" />}
            </div>}
          </article>;
        })}
      </div>
      <footer className="inspection-compartment-footer"><span>Area {navigationIds.indexOf(current.id) + 1} of {navigationIds.length}</span></footer>
    </section>

    <div className="inspection-action-bar">
      <button ref={quickButtonRef} type="button" className="quick-checklist-trigger" aria-expanded={quickOpen} aria-controls="quick-checklist" onClick={() => setQuickOpen(!quickOpen)}><ClipboardList size={20} aria-hidden="true" /><span>Checklist <strong>{progress.remaining > 0 ? `${progress.remaining} remaining` : 'Equipment inspected'}</strong>{attention.length > 0 && <small>{attention.length} needs attention</small>}</span></button>
      {progress.remaining > 0 ? <button type="button" className="inspection-review-button" onClick={() => select(navigationIds[(navigationIds.indexOf(current.id) + 1) % navigationIds.length])}>Next area<ArrowRight size={17} aria-hidden="true" /></button>
        : <button type="button" className="inspection-review-button" disabled={progress.total === 0} onClick={() => onSubmit(compartments)}>{actionLabel}<ArrowRight size={17} aria-hidden="true" /></button>}
    </div>
    {quickOpen && <aside id="quick-checklist" className="quick-checklist" aria-label="Quick Checklist" onKeyDown={event => { if (event.key === 'Escape') { setQuickOpen(false); quickButtonRef.current?.focus(); } }}>
      <header><div><span className="inspection-eyebrow">Quick Checklist</span><h2>{progress.remaining === 0 ? 'Equipment inspected' : `${progress.remaining} remaining`}</h2></div><button type="button" aria-label="Close Quick Checklist" onClick={() => { setQuickOpen(false); quickButtonRef.current?.focus(); }}><X size={22} /></button></header>
      <div className="quick-checklist-body">
        {remaining.length > 0 && <section aria-label="Remaining work"><h3>Remaining ({remaining.length})</h3><ul>{remaining.map(({ compartment, item }) => <li key={`${compartment.id}-${item.id}`}><button type="button" onClick={() => select(compartment.id, item.id)}><span><small>{compartment.name}</small><strong>{item.name}</strong></span><span>Inspect <ArrowRight size={16} /></span></button></li>)}</ul></section>}
        {attention.length > 0 && <section aria-label="Needs attention"><h3>Needs attention ({attention.length})</h3><ul>{attention.map(({ compartment, item, known }) => <li key={`${compartment.id}-${item.id}`}><button type="button" onClick={() => select(compartment.id, item.id)}><span><small>{compartment.name}</small><strong>{item.name}</strong>{known.length > 0 && <small>Existing {known.map(finding => finding.issue_type).join(', ')}</small>}</span><span>{item.observed && item.status !== 'Present' ? `${item.status} · Reported` : 'Existing issue'} <TriangleAlert size={16} /></span></button></li>)}</ul></section>}
        {remaining.length === 0 && attention.length === 0 && <p className="inspection-muted">All equipment inspected. No issues reported.</p>}
      </div>
    </aside>}
  </div>;
}
