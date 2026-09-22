import type { ChecklistData, Compartment } from '../types';
import type { BlueprintView } from '../data/apparatusBlueprints';
import { findingsForArea, inspectionProgress } from '../utils/inspectionProgress';

interface Props {
  view: BlueprintView;
  compartments: Compartment[];
  activeId: string;
  onSelect: (id: string) => void;
  findings?: ChecklistData['open_findings'];
}

export default function ApparatusBlueprint({ view, compartments, activeId, onSelect, findings = [] }: Props) {
  return (
    <svg className="apparatus-blueprint" viewBox="0 0 600 230" aria-label={`${view.label} apparatus compartments`}>
      <g transform={view.mirror ? 'translate(600 0) scale(-1 1)' : undefined}>
      <path d={view.outline} className="blueprint-body" />
      {view.details.map((d, index) => <path key={index} d={d} className="blueprint-detail" />)}
      {view.wheels?.map(wheel => <g key={wheel.x} aria-hidden="true"><circle cx={wheel.x} cy={wheel.y} r="25" className="blueprint-wheel" /><circle cx={wheel.x} cy={wheel.y} r="12" className="blueprint-hub" /></g>)}
      </g>
      {view.zones.map(zone => {
        const x = view.mirror ? 600 - zone.x - zone.width : zone.x;
        const compartment = compartments.find(entry => entry.id === zone.compartmentId);
        if (!compartment) return null;
        const progress = inspectionProgress([compartment]);
        const existing = findingsForArea(compartment, findings).length;
        const complete = progress.total > 0 && progress.remaining === 0;
        return <g key={zone.compartmentId} data-area-id={zone.compartmentId} role="button" tabIndex={0} aria-pressed={activeId === zone.compartmentId}
          aria-label={`${compartment.name}, ${progress.completed} of ${progress.total} inspected${existing ? `, ${existing} existing issue${existing === 1 ? '' : 's'}` : ''}${progress.issues ? `, ${progress.issues} reported issues` : ''}`}
          onClick={() => onSelect(zone.compartmentId)} onKeyDown={event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); onSelect(zone.compartmentId); } }}
          className={`blueprint-zone ${complete ? 'is-complete' : 'is-incomplete'} ${activeId === zone.compartmentId ? 'is-active' : ''}`}>
          <rect x={x} y={zone.y} width={zone.width} height={zone.height} rx="3" />
          <text x={x + zone.width / 2} y={zone.y + zone.height / 2 - 3} textAnchor="middle" style={{ fontSize: Math.min(18, (zone.width - 6) / (zone.label.length * 0.68)) }}>{zone.label}</text>
          <text className="blueprint-zone-count" x={x + zone.width / 2} y={zone.y + zone.height / 2 + 18} textAnchor="middle">{complete ? '✓' : `${progress.completed}/${progress.total}`}</text>
          {existing > 0 && <g className="blueprint-warning existing" aria-hidden="true"><circle cx={x + zone.width - 3} cy={zone.y - 3} r="10" /><text x={x + zone.width - 3} y={zone.y + 1} textAnchor="middle">E</text></g>}
          {progress.issues > 0 && <g className="blueprint-warning reported" aria-hidden="true"><circle cx={x + 3} cy={zone.y - 3} r="10" /><text x={x + 3} y={zone.y + 1} textAnchor="middle">R</text></g>}
        </g>;
      })}
      <text x="25" y="225" className="blueprint-caption">{view.id === 'left' ? 'FRONT ←' : view.id === 'right' ? 'FRONT →' : view.label.toUpperCase()}</text>
      <text x="570" y="225" textAnchor="end" className="blueprint-caption">SCHEMATIC · NOT TO SCALE</text>
    </svg>
  );
}
