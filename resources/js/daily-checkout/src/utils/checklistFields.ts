import type { ChecklistField, ChecklistFieldValue, ScheduledTaskStatus } from '../types';

export interface ChecklistFieldGroup {
  id: string;
  name: string;
  fields: ChecklistField[];
}

/** Fields with a heading, grouped under it in the order the template first names each heading. */
export function fieldGroups(fields: ChecklistField[]): ChecklistFieldGroup[] {
  const groups = new Map<string, ChecklistField[]>();
  for (const field of fields) {
    if (!field.group) continue;
    groups.set(field.group, [...(groups.get(field.group) ?? []), field]);
  }

  return Array.from(groups, ([name, grouped]) => ({ id: `group-${name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`, name, fields: grouped }));
}

/** A duty answered with an equipment word on an older draft: Present meant done, the others did not. */
export const taskIsCompleted = (status: ScheduledTaskStatus): boolean => status === 'Completed' || status === 'Present';

/** What a duty's answer reads as anywhere a person sees it. */
export const taskStatusLabel = (status: ScheduledTaskStatus): 'Completed' | 'Not Completed' => (taskIsCompleted(status) ? 'Completed' : 'Not Completed');

/** A typed reading the template's limits refuse. The server refuses it too, so it is caught before signing. */
export function fieldValueOutOfRange(field: ChecklistField, value: ChecklistFieldValue | undefined): boolean {
  if (typeof value !== 'number') return false;
  const min = field.inputType === 'percentage' ? 0 : field.min;
  const max = field.inputType === 'percentage' ? 100 : field.max;

  return (min !== undefined && value < min) || (max !== undefined && value > max);
}
