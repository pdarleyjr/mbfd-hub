import { useState, type Dispatch, type SetStateAction } from 'react';
import type {
  ChecklistData,
  ChecklistFieldValue,
  ScheduledChecklistTaskResult,
  ScheduledTaskStatus,
} from '../types';
import { fieldGroups, taskIsCompleted } from '../utils/checklistFields.ts';
import { equipmentFieldIds } from '../utils/equipmentNumbers.ts';

interface ChecklistFieldsStepProps {
  checklist: ChecklistData;
  initialFieldValues: Array<{ id: string; value: ChecklistFieldValue }>;
  initialScheduledTasks: ScheduledChecklistTaskResult[];
  onFieldsChange: Dispatch<SetStateAction<Array<{ id: string; value: ChecklistFieldValue }>>>;
  onTasksChange: Dispatch<SetStateAction<ScheduledChecklistTaskResult[]>>;
  onSubmit: (
    fieldValues: Array<{ id: string; value: ChecklistFieldValue }>,
    scheduledTasks: ScheduledChecklistTaskResult[],
  ) => void;
  onBack: () => void;
  continueLabel: string;
}

const INPUT = 'min-h-12 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-4 py-3 text-base text-hub-ink focus:border-hub-focus focus:outline-none';
const LABEL = 'mb-1.5 block text-sm font-semibold text-hub-ink';

// Selected duty states: text names the state, the fill reinforces it.
const TASK_CHOICES = [
  { status: 'Completed', selected: 'border-hub-success bg-hub-success text-white' },
  { status: 'Not Completed', selected: 'border-hub-danger bg-hub-danger text-white' },
] as const;

export default function ChecklistFieldsStep({
  checklist,
  initialFieldValues,
  initialScheduledTasks,
  onFieldsChange: setFieldValues,
  onTasksChange: setScheduledTasks,
  onSubmit,
  onBack,
  continueLabel,
}: ChecklistFieldsStepProps) {
  const fieldValues = initialFieldValues;
  const scheduledTasks = initialScheduledTasks;
  const [error, setError] = useState<string | null>(null);

  const updateFieldValue = (id: string, value: ChecklistFieldValue) => {
    setFieldValues((current) => current.map((fieldValue) => (
      fieldValue.id === id ? { ...fieldValue, value } : fieldValue
    )));
  };

  const updateScheduledTask = (id: string, status: ScheduledTaskStatus) => {
    setScheduledTasks((current) => current.map((task) => (
      task.id === id ? { ...task, status, observed: true } : task
    )));
  };

  const updateTaskNote = (id: string, notes: string) => {
    setScheduledTasks((current) => current.map((task) => (task.id === id ? { ...task, notes: notes === '' ? null : notes } : task)));
  };

  // A duty the member already marked not completed is left as they said.
  const markAllDueTasksCompleted = () => {
    setScheduledTasks((current) => current.map((task) => task.observed && !taskIsCompleted(task.status) ? task : ({ ...task, status: 'Completed' as const, observed: true })));
  };

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    const missingRequiredField = checklist.fields.find((field) => {
      if (!field.required) return false;

      const value = fieldValues.find((fieldValue) => fieldValue.id === field.id)?.value;
      return value === null || value === undefined
        || (typeof value === 'string' && value.trim() === '')
        || (typeof value === 'number' && !Number.isFinite(value));
    });
    if (missingRequiredField) {
      setError(`${missingRequiredField.name} is required.`);

      return;
    }

    setError(null);
    if (scheduledTasks.some(task => !task.observed)) {
      setError('Confirm each scheduled duty before continuing.');
      return;
    }
    onSubmit(fieldValues, scheduledTasks);
  };

  const knownFields = checklist.fields.filter(field => field.id === checklist.inspection_date_field_id || field.id === 'vehicle_num');
  const editableFields = checklist.fields.filter(field => !knownFields.includes(field));
  // Shift is separately required by the processing contract, even on Fire Boat.
  const requiredFields = editableFields.filter(field => field.required || field.id === 'fb6-shift');
  // SCBA and radio numbers are on the review step's card, the one place every member passes. A required one stays here.
  const onCard = equipmentFieldIds(checklist.equipment_numbers);
  const shownFields = editableFields.filter(field => !requiredFields.includes(field) && !onCard.has(field.id));
  // Fields with a heading are everyday readings, so they stay on screen. Only the rest are tucked away.
  const groups = fieldGroups(shownFields);
  const optionalFields = shownFields.filter(field => !field.group);

  const renderField = (field: ChecklistData['fields'][number]) => {
    const value = fieldValues.find((fieldValue) => fieldValue.id === field.id)?.value ?? null;

    if (field.id === 'fb6-shift') return <div key={field.id}>
      <label htmlFor={field.id} className={LABEL}>Shift *</label>
      <select id={field.id} value={String(value ?? '')} required onChange={event => updateFieldValue(field.id, event.target.value)} className={INPUT}>
        <option value="">Select shift</option>{['A', 'B', 'C'].map(shift => <option key={shift} value={shift}>{shift} Shift</option>)}
      </select>
    </div>;

    if (field.inputType === 'checkbox') {
      return (
        <label key={field.id} className="hub-well flex min-h-12 items-center gap-3 px-4 py-3 text-hub-ink">
          <input
            id={field.id}
            type="checkbox"
            checked={value === true}
            onChange={(event) => updateFieldValue(field.id, event.target.checked)}
            className="h-5 w-5 rounded border-hub-control-border text-hub-blue focus:ring-hub-focus"
          />
          <span className="font-medium">{field.name}{!field.required && !field.group && <span className="ml-1 text-sm font-normal text-hub-n-600">· Optional</span>}</span>
        </label>
      );
    }

    const numeric = field.inputType === 'number' || field.inputType === 'percentage';
    const type = numeric ? 'number' : field.inputType === 'date' ? 'date' : 'text';
    const hint = field.hint ?? (field.inputType === 'percentage' ? 'Percentage, 0 to 100.' : undefined);
    const hintId = hint ? `${field.id}-hint` : undefined;

    return (
      <div key={field.id}>
        <label htmlFor={field.id} className={LABEL}>
          {field.name}{field.unit && field.inputType !== 'percentage' ? ` (${field.unit})` : ''}{field.required ? ' *' : field.group ? '' : ' · Optional'}
        </label>
        {field.multiline ? <textarea id={field.id} rows={3} required={field.required} maxLength={2000} value={value === null ? '' : String(value)} onChange={event => updateFieldValue(field.id, event.target.value)} className={`min-h-24 ${INPUT}`} /> : <input
          id={field.id}
          type={type}
          inputMode={field.id === 'mileage' ? 'numeric' : numeric ? 'decimal' : undefined}
          step={field.id === 'mileage' ? 1 : numeric ? 'any' : undefined}
          min={field.id === 'mileage' || field.inputType === 'percentage' ? 0 : field.min}
          max={field.id === 'mileage' ? 999999999 : field.inputType === 'percentage' ? 100 : field.max}
          aria-describedby={hintId}
          value={value === null ? '' : String(value)}
          required={field.required}
          maxLength={field.inputType === 'text' ? 2000 : undefined}
          onChange={(event) => {
            if (numeric) {
              updateFieldValue(field.id, event.target.value === '' ? null : Number(event.target.value));

              return;
            }

            updateFieldValue(field.id, event.target.value);
          }}
          className={`${INPUT} ${numeric ? 'tabular-nums' : ''}`}
        />}
        {hintId && <p id={hintId} className="mt-1 text-[13px] text-hub-n-600">{hint}</p>}
      </div>
    );
  };

  return (
    <form data-hub-ui="2" onSubmit={handleSubmit} className="mx-auto max-w-2xl space-y-4">
      <div className="space-y-1">
        <h2 className="text-xl font-bold text-hub-ink font-heading">Checklist Details</h2>
        <p className="mt-1 text-hub-n-600">{groups.length > 0 ? 'Enter what you can read today. A blank reading will not stop the inspection.' : 'Complete required checks. Additional readings and identifiers are optional.'}</p>
      </div>

      {error && <p role="alert" className="rounded-hub-control border border-hub-danger-border bg-hub-danger-bg px-4 py-3 text-sm font-semibold text-hub-danger">{error}</p>}

      {requiredFields.length > 0 && <section aria-label="Required checklist fields" className="inspection-panel grid gap-4 p-4 sm:grid-cols-2">
        {requiredFields.map(renderField)}
      </section>}

      {knownFields.length > 0 && <section aria-label="Recorded automatically" className="inspection-panel grid gap-3 p-3 sm:grid-cols-2">
        {knownFields.map(field => <div key={field.id}>
          <label htmlFor={field.id} className="block text-[13px] font-medium text-hub-n-600">{field.name} · Recorded automatically</label>
          <input id={field.id} type={field.inputType === 'date' ? 'date' : 'text'} readOnly value={String(fieldValues.find(answer => answer.id === field.id)?.value ?? '')} className="mt-1 min-h-11 w-full border-0 bg-transparent text-sm font-medium tabular-nums text-hub-ink" />
        </div>)}
      </section>}

      {groups.map(group => <section key={group.name} aria-labelledby={`${group.id}-heading`} className="inspection-panel p-4">
        <h3 id={`${group.id}-heading`} className="mb-3 font-heading text-lg font-bold text-hub-ink">{group.name}</h3>
        <div className="grid gap-4 sm:grid-cols-2">{group.fields.map(renderField)}</div>
      </section>)}

      {optionalFields.length > 0 && <details className="inspection-panel" onInvalidCapture={event => { event.currentTarget.open = true; }}>
        <summary className="flex min-h-11 cursor-pointer items-center px-4 py-3 font-semibold text-hub-ink">Additional details · Optional</summary>
        <p className="px-4 pb-3 text-sm text-hub-n-600">Enter additional readings and identifiers when available. You may continue without them.</p>
        <section aria-label="Optional checklist fields" className="grid gap-4 px-4 pb-4 sm:grid-cols-2">{optionalFields.map(renderField)}</section>
      </details>}

      {checklist.schema_version === 2 && <section aria-labelledby="scheduled-duties-heading" className="inspection-panel p-4">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 id="scheduled-duties-heading" className="font-heading text-lg font-bold text-hub-ink">Scheduled duties due today</h3>
            {checklist.inspection_date && <p className="text-sm tabular-nums text-hub-n-600">Inspection date: {checklist.inspection_date}</p>}
          </div>
          {scheduledTasks.length > 0 && (
            <button type="button" onClick={markAllDueTasksCompleted} className="hub-btn hub-btn--secondary hub-btn--wrap">
              Mark all duties completed
            </button>
          )}
        </div>

        {scheduledTasks.length === 0 ? (
          <p className="text-sm text-hub-n-600">No scheduled duties are due for this inspection date.</p>
        ) : (
          <div className="space-y-3">
            {checklist.due_tasks.map((task) => {
              const result = scheduledTasks.find((scheduledTask) => scheduledTask.id === task.id);
              if (!result) return null;

              return (
                <div key={task.id} className="hub-well p-3">
                  <p className="font-semibold text-hub-ink">{task.name}</p>
                  {task.instructions && <p className="mt-1 text-sm text-hub-n-600">{task.instructions}</p>}
                  <div className="mt-3 flex gap-2" role="group" aria-label={`Status for ${task.name}`}>
                    {TASK_CHOICES.map(({ status, selected: selectedStyle }) => {
                      const selected = result.observed === true && taskIsCompleted(result.status) === (status === 'Completed');
                      return (
                        <button
                          key={status}
                          type="button"
                          onClick={() => updateScheduledTask(task.id, status)}
                          aria-pressed={selected}
                          className={`min-h-11 flex-1 rounded-hub-control border px-3 py-2 text-sm font-semibold transition-colors ${selected ? selectedStyle : 'border-hub-control-border bg-hub-well text-hub-ink hover:bg-hub-n-50'}`}
                        >
                          {status}
                        </button>
                      );
                    })}
                  </div>
                  {result.observed === true && !taskIsCompleted(result.status) && <label className="mt-3 block text-sm font-semibold text-hub-ink">Why not? (optional)
                    <input type="text" maxLength={2000} value={result.notes ?? ''} onChange={event => updateTaskNote(task.id, event.target.value)} className={`mt-1 ${INPUT}`} />
                  </label>}
                </div>
              );
            })}
          </div>
        )}
      </section>}

      <div className="flex gap-3">
        <button type="button" onClick={onBack} className="hub-btn hub-btn--secondary flex-1">
          Back
        </button>
        <button type="submit" className="hub-btn hub-btn--primary hub-btn--wrap flex-1">
          {continueLabel}
        </button>
      </div>
    </form>
  );
}
