import type { ChecklistData, ChecklistFieldValue, ChecklistItem, Compartment, MeterData, OfficerInfo, ScheduledChecklistTaskResult, VehicleIdentificationAnswers } from '../types';
import { resolveFindingTargets } from './previousFindings';
import { vehicleConfirmationComplete } from './vehicleConfirmation';
import { activeVehicleIdentification, vehicleIdentificationComplete } from './vehicleIdentification';

export function itemIsComplete(item: ChecklistItem) {
  if (item.observed !== true) return false;
  if (item.status !== 'Present' || !item.inputType || item.inputType === 'checkbox' || item.valueRequired === false) return true;
  return item.value !== null && item.value !== undefined && String(item.value).trim() !== '';
}

export function inspectionProgress(compartments: Compartment[]) {
  const items = compartments.flatMap(compartment => compartment.items);
  const completed = items.filter(itemIsComplete).length;
  const issues = items.filter(item => item.observed === true && item.status !== 'Present').length;
  return { total: items.length, completed, remaining: items.length - completed, issues };
}

/** Findings that resolve to an item of this area. Pass every area so id matches fall back to names correctly. */
export function findingsForArea(compartment: Compartment, findings: ChecklistData['open_findings'] = [], compartments: Compartment[] = [compartment]) {
  const located = new Set(resolveFindingTargets(compartments, findings).filter(target => target.compartment.id === compartment.id).map(target => target.finding));
  return findings.filter(finding => located.has(finding));
}

export function inspectionReadiness(checklist: ChecklistData | null, compartments: Compartment[], fieldValues: Array<{ id: string; value: ChecklistFieldValue }>, scheduledTasks: ScheduledChecklistTaskResult[], officer: OfficerInfo, meters: MeterData, identification: VehicleIdentificationAnswers = {}) {
  const equipment = inspectionProgress(compartments);
  const missingFields = checklist?.fields.filter(field => {
    if (!field.required) return false;
    const value = fieldValues.find(answer => answer.id === field.id)?.value;
    return value === null || value === undefined || (typeof value === 'string' && value.trim() === '')
      || (typeof value === 'number' && !Number.isFinite(value));
  }) ?? [];
  const missingDuties = checklist?.due_tasks.filter(task => !scheduledTasks.find(answer => answer.id === task.id)?.observed) ?? [];
  const missingShift = !['A', 'B', 'C'].includes(officer.shift);
  // The physical vehicle must be confirmed before submitting, and a different vehicle has to be recorded first.
  const missingVehicle = !vehicleConfirmationComplete(officer.vehicleConfirmation);
  // Each open one-time vehicle question needs an answer, or "not available" with its reason. No questions, no gate.
  // Not asked while the crew says they are at a different vehicle.
  const missingIdentification = !vehicleIdentificationComplete(activeVehicleIdentification(checklist?.vehicle_identification, officer), identification);
  const maxMiles = checklist?.fields.some(field => field.id === 'mileage') ? 999999999 : 2147483647;
  const invalidMeters = (meters.engine_hours !== null && (!Number.isFinite(meters.engine_hours) || meters.engine_hours < 0 || meters.engine_hours > 9999999.9 || !/^\d+(\.\d)?$/.test(String(meters.engine_hours))))
    || (meters.miles !== null && (!Number.isInteger(meters.miles) || meters.miles < 0 || meters.miles > maxMiles));
  const nextStep = equipment.remaining > 0 || equipment.total === 0 ? 'compartments'
    : missingFields.length > 0 || missingDuties.length > 0 ? 'details'
    : missingShift || missingVehicle || missingIdentification ? 'officer' : invalidMeters ? 'meter' : 'submit';
  const label = nextStep === 'compartments' ? `${equipment.remaining} ${equipment.remaining === 1 ? 'item' : 'items'} remaining`
    : nextStep === 'details' ? 'Continue: Required details'
    : nextStep === 'officer' ? (missingVehicle || missingIdentification ? (missingShift ? 'Continue: Vehicle & Shift' : 'Continue: Vehicle') : 'Continue: Shift')
    : nextStep === 'meter' ? 'Continue: Readings' : 'Review & Sign';
  return { equipment, missingFields, missingDuties, missingShift, missingVehicle, missingIdentification, invalidMeters, nextStep, label, ready: nextStep === 'submit' } as const;
}
