import type { ChecklistItem, Compartment } from '../types';

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
