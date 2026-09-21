import { useEffect, useRef, useState, type Dispatch, type SetStateAction } from 'react';
import type { ChecklistData, ChecklistItem, Compartment } from '../types';
import { resolveBlueprint } from '../data/apparatusBlueprints';
import { inspectionProgress, itemIsComplete } from '../utils/inspectionProgress';
import ApparatusBlueprint from './ApparatusBlueprint';
import './inspection-workspace.css';

interface Props {
  compartments: Compartment[];
  findings?: ChecklistData['open_findings'];
  onChange: Dispatch<SetStateAction<Compartment[]>>;
  onSubmit: (compartments: Compartment[]) => void;
  onBack: () => void;
  backLabel?: string;
}

export default function CompartmentStep({ compartments, findings = [], onChange, onSubmit, onBack, backLabel = 'Member / Vehicle Info' }: Props) {
  const profile = resolveBlueprint(compartments);
  const [activeId, setActiveId] = useState(profile?.views[0].zones[0].compartmentId ?? compartments[0]?.id ?? '');
  const [viewId, setViewId] = useState(profile?.views[0].id ?? 'all');
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [quickOpen, setQuickOpen] = useState(false);
  const [photoError, setPhotoError] = useState<string | null>(null);
  const [focusItem, setFocusItem] = useState<string | null>(null);
  const workspaceRef = useRef<HTMLDivElement>(null);
  const quickButtonRef = useRef<HTMLButtonElement>(null);
  const current = compartments.find(compartment => compartment.id === activeId) ?? compartments[0];
  const view = profile?.views.find(entry => entry.id === viewId) ?? profile?.views[0];
  const progress = inspectionProgress(compartments);
  const compartmentProgress = current ? inspectionProgress([current]) : null;
  const unlocatedFindings = findings.filter(finding => !compartments.some(compartment => compartment.name === finding.compartment && compartment.items.some(item => item.name === finding.item)));
  const remaining = compartments.flatMap(compartment => compartment.items
    .filter(item => !itemIsComplete(item) || item.status !== 'Present')
    .map(item => ({ compartment, item })));

  useEffect(() => {
    if (!focusItem) return;
    const element = workspaceRef.current?.querySelector<HTMLElement>(`[data-item-id="${CSS.escape(focusItem)}"]`);
    element?.focus();
    element?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
    setFocusItem(null);
  }, [focusItem, activeId]);

  const select = (id: string, itemId?: string) => {
    const nextView = profile?.views.find(entry => entry.zones.some(zone => zone.compartmentId === id));
    if (nextView) setViewId(nextView.id);
    setActiveId(id);
    setExpandedId(itemId ?? null);
    setQuickOpen(false);
    if (itemId) setFocusItem(itemId);
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

  return <div className="inspection-layout">
    <aside className="inspection-navigation" aria-label="Apparatus navigation">
      <div className="inspection-panel-heading"><span className="inspection-eyebrow">Walk around</span><span>{compartments.length} areas</span></div>
      <h2>Choose an area</h2>
      {profile && view ? <>
        <div className="blueprint-views" aria-label="Apparatus views">
          {profile.views.map(entry => <button key={entry.id} type="button" aria-pressed={entry.id === view.id} onClick={() => { setViewId(entry.id); setActiveId(entry.zones[0].compartmentId); setExpandedId(null); }}>{entry.label}</button>)}
        </div>
        <ApparatusBlueprint view={view} compartments={compartments} activeId={current.id} onSelect={select} />
        <div className="blueprint-touch-zones" aria-label="Select a diagram area">{view.zones.map(zone => <button key={zone.compartmentId} type="button" aria-pressed={current.id === zone.compartmentId} onClick={() => select(zone.compartmentId)}>{zone.label}</button>)}</div>
        <div className="blueprint-legend"><span><i className="remaining-dot" />Remaining</span><span><i className="complete-dot" />Inspected</span><span>! Issue</span></div>
      </> : <p className="inspection-muted">Choose any compartment to inspect. Your progress follows you.</p>}
      <label className="inspection-field-label" htmlFor="inspection-compartment">Compartment</label>
      <select id="inspection-compartment" value={current.id} onChange={event => select(event.target.value)}>
        {compartments.map(compartment => { const p = inspectionProgress([compartment]); return <option key={compartment.id} value={compartment.id}>{compartment.name} · {p.completed}/{p.total}</option>; })}
      </select>
      <div className="inspection-area-list" aria-label="All inspection areas">
        {compartments.map(compartment => {
          const p = inspectionProgress([compartment]);
          return <button key={compartment.id} type="button" aria-pressed={compartment.id === current.id} onClick={() => select(compartment.id)}>
            <span className={`area-state ${p.remaining === 0 ? 'is-complete' : ''}`}>{p.remaining === 0 ? '✓' : '○'}</span><span>{compartment.name}</span><small>{p.completed}/{p.total}{p.issues > 0 ? ' !' : ''}</small>
          </button>;
        })}
      </div>
      <button type="button" className="inspection-info-link" onClick={onBack}>{backLabel} <span aria-hidden="true">↗</span></button>
      {unlocatedFindings.length > 0 && <details className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
        <summary className="cursor-pointer font-semibold">Other recorded findings ({unlocatedFindings.length})</summary>
        <p className="mt-2">These findings remain open. Verify the recorded location or duty before acting.</p>
        <ul className="mt-2 space-y-2">{unlocatedFindings.map(finding => <li key={finding.id}><strong>{finding.item}</strong> · {finding.compartment} · {finding.issue_type}</li>)}</ul>
      </details>}
    </aside>

    <section className="inspection-equipment" aria-label="Compartment Inspection" ref={workspaceRef}>
      <header className="equipment-heading">
        <div><p className="inspection-eyebrow">Compartment Inspection</p><h2>{current.name}</h2><p className="inspection-muted">{compartmentProgress.completed} of {compartmentProgress.total} inspected{compartmentProgress.issues > 0 ? ` · ${compartmentProgress.issues} issues` : ''}</p></div>
        <button type="button" className="confirm-compartment" aria-label="Mark all items in this compartment as present" onClick={() => onChange(previous => previous.map(compartment => compartment.id === current.id ? { ...compartment, items: compartment.items.map(item => (item.observed && item.status !== 'Present') || (item.inputType && item.inputType !== 'checkbox' && !itemIsComplete(item)) ? item : { ...item, status: 'Present', observed: true }) } : compartment))}>✓ Confirm all present</button>
      </header>
      <p className="inspection-observation-hint">Check the equipment, then tap Pass. Open an item to report an issue.</p>
      {photoError && <p role="alert" className="inspection-alert">{photoError}</p>}
      <div className="equipment-list">
        {current.items.map(item => {
          const expanded = expandedId === item.id;
          const issue = item.observed && item.status !== 'Present';
          const known = findings.find(finding => finding.compartment === current.name && finding.item === item.name);
          return <article key={item.id} className={`equipment-row ${issue ? 'has-issue' : ''} ${expanded ? 'is-expanded' : ''}`}>
            <div className="equipment-summary">
              <button type="button" className="equipment-expand" data-item-id={item.id} aria-expanded={expanded} aria-controls={`details-${current.id}-${item.id}`} onClick={() => setExpandedId(expanded ? null : item.id)}>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><path d="m12 3 8 4v10l-8 4-8-4V7l8-4Zm-8 4 8 4 8-4M12 11v10" /></svg>
                <span><strong>{item.name}</strong><small>{item.expectedQuantity !== undefined ? `Expected quantity: ${item.expectedQuantity} · ` : ''}{issue ? `${item.status} · Open to review` : item.observed ? 'Inspected' : 'Needs inspection'}</small></span>
                <span className="equipment-chevron" aria-hidden="true">{expanded ? '−' : '+'}</span>
              </button>
              <button type="button" className={`equipment-pass ${itemIsComplete(item) && !issue ? 'is-passed' : ''}`} aria-label={`Pass ${item.name}`} aria-pressed={itemIsComplete(item) && item.status === 'Present'} onClick={() => { if (item.inputType && item.inputType !== 'checkbox' && !itemIsComplete({ ...item, status: 'Present', observed: true })) { setExpandedId(item.id); } else { update(current.id, item.id, { status: 'Present', observed: true }); } }}>✓ <span>{item.inputType && item.inputType !== 'checkbox' ? 'Record' : 'Pass'}</span></button>
            </div>
            {known && <p className="px-4 pb-3 text-sm text-amber-900">Known {known.issue_type} · {known.last_observation === 'appears_corrected' ? 'Reported corrected · awaiting verification' : known.operational_impact === 'unclassified' ? 'Awaiting classification' : known.operational_impact.replaceAll('_', ' ')}{known.service_status ? ` · Service: ${known.service_status.replaceAll('_', ' ')}` : ''}</p>}
            {expanded && <div className="equipment-details" id={`details-${current.id}-${item.id}`}>
              {item.instructions && <p className="mb-2 text-sm text-slate-600">{item.instructions}</p>}
              <div className="equipment-status" role="group" aria-label={`Status for ${item.name}`}>
                {(['Present', 'Missing', 'Damaged'] as const).map(status => <button key={status} type="button" aria-pressed={item.observed === true && item.status === status} onClick={() => update(current.id, item.id, { status, observed: true })}>{status}</button>)}
              </div>
              {issue && <p className="equipment-issue-copy">This observation stays with the apparatus. An authorized reviewer determines any operational hold.</p>}
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
      <footer className="inspection-compartment-footer"><span>{compartments.findIndex(entry => entry.id === current.id) + 1} / {compartments.length} areas</span><button type="button" onClick={() => { const next = compartments[(compartments.findIndex(entry => entry.id === current.id) + 1) % compartments.length]; select(next.id); }}>Next area <span aria-hidden="true">→</span></button></footer>
    </section>

    <div className="inspection-action-bar">
      <button ref={quickButtonRef} type="button" className="quick-checklist-trigger" aria-expanded={quickOpen} aria-controls="quick-checklist" onClick={() => setQuickOpen(!quickOpen)}>Checklist <strong>{progress.remaining} remaining</strong>{progress.issues > 0 && <span>· {progress.issues} issues</span>}</button>
      <button type="button" className="inspection-review-button" disabled={progress.remaining > 0 || progress.total === 0} onClick={() => onSubmit(compartments)}>Review &amp; Submit <span aria-hidden="true">→</span></button>
    </div>
    {quickOpen && <aside id="quick-checklist" className="quick-checklist" aria-label="Quick Checklist" onKeyDown={event => { if (event.key === 'Escape') { setQuickOpen(false); quickButtonRef.current?.focus(); } }}>
      <header><div><span className="inspection-eyebrow">Quick Checklist</span><h2>{progress.remaining === 0 ? 'Inspection complete' : `${progress.remaining} remaining`}</h2></div><button type="button" aria-label="Close Quick Checklist" onClick={() => { setQuickOpen(false); quickButtonRef.current?.focus(); }}>×</button></header>
      {remaining.length === 0 ? <p className="inspection-muted">All equipment inspected. Ready to review and sign.</p> : <ul>{remaining.map(({ compartment, item }) => <li key={`${compartment.id}-${item.id}`}><button type="button" onClick={() => select(compartment.id, item.id)}><span><small>{compartment.name}</small><strong>{item.name}</strong></span><span>{item.observed ? item.status : 'Inspect'} →</span></button></li>)}</ul>}
    </aside>}
  </div>;
}
