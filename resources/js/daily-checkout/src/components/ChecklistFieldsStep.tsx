import { useEffect, useState } from 'react';
import type {
  ChecklistData,
  ChecklistFieldValue,
  ItemStatus,
  ScheduledChecklistTaskResult,
} from '../types';

interface ChecklistFieldsStepProps {
  checklist: ChecklistData;
  initialFieldValues: Array<{ id: string; value: ChecklistFieldValue }>;
  initialScheduledTasks: ScheduledChecklistTaskResult[];
  onSubmit: (
    fieldValues: Array<{ id: string; value: ChecklistFieldValue }>,
    scheduledTasks: ScheduledChecklistTaskResult[],
  ) => void;
  onBack: () => void;
}

export default function ChecklistFieldsStep({
  checklist,
  initialFieldValues,
  initialScheduledTasks,
  onSubmit,
  onBack,
}: ChecklistFieldsStepProps) {
  const [fieldValues, setFieldValues] = useState(initialFieldValues);
  const [scheduledTasks, setScheduledTasks] = useState(initialScheduledTasks);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setFieldValues(initialFieldValues);
    setScheduledTasks(initialScheduledTasks);
  }, [initialFieldValues, initialScheduledTasks]);

  const updateFieldValue = (id: string, value: ChecklistFieldValue) => {
    setFieldValues((current) => current.map((fieldValue) => (
      fieldValue.id === id ? { ...fieldValue, value } : fieldValue
    )));
  };

  const updateScheduledTask = (id: string, status: ItemStatus) => {
    setScheduledTasks((current) => current.map((task) => (
      task.id === id ? { ...task, status } : task
    )));
  };

  const markAllDueTasksPresent = () => {
    setScheduledTasks((current) => current.map((task) => ({ ...task, status: 'Present' as ItemStatus })));
  };

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    const missingRequiredField = checklist.fields.find((field) => {
      if (!field.required) return false;

      const value = fieldValues.find((fieldValue) => fieldValue.id === field.id)?.value;
      return value === null || value === '';
    });
    if (missingRequiredField) {
      setError(`${missingRequiredField.name} is required.`);

      return;
    }

    setError(null);
    onSubmit(fieldValues, scheduledTasks);
  };

  return (
    <form onSubmit={handleSubmit} className="max-w-2xl mx-auto space-y-6">
      <div className="text-center">
        <h2 className="text-2xl font-bold text-neutral-800 font-heading">Checklist Details</h2>
        <p className="mt-1 text-neutral-500">Record the Fire Boat-specific readings and checks before compartment inspection.</p>
      </div>

      {error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</p>}

      <section aria-label="Checklist fields" className="space-y-4 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
        {checklist.fields.map((field) => {
          const value = fieldValues.find((fieldValue) => fieldValue.id === field.id)?.value ?? null;

          if (field.inputType === 'checkbox') {
            return (
              <label key={field.id} className="flex min-h-12 items-center gap-3 rounded-lg border border-neutral-200 px-4 py-3 text-neutral-800">
                <input
                  id={field.id}
                  type="checkbox"
                  checked={value === true}
                  onChange={(event) => updateFieldValue(field.id, event.target.checked)}
                  className="h-5 w-5 rounded border-neutral-400 text-red-600 focus:ring-red-500"
                />
                <span className="font-medium">{field.name}</span>
              </label>
            );
          }

          const type = field.inputType === 'number' || field.inputType === 'percentage'
            ? 'number'
            : field.inputType === 'date'
              ? 'date'
              : 'text';

          return (
            <div key={field.id}>
              <label htmlFor={field.id} className="mb-1.5 block text-sm font-semibold text-neutral-700">
                {field.name}{field.required ? ' *' : ''}
              </label>
              <input
                id={field.id}
                type={type}
                step={field.inputType === 'number' || field.inputType === 'percentage' ? 'any' : undefined}
                value={value === null ? '' : String(value)}
                readOnly={field.inputType === 'date'}
                onChange={(event) => {
                  if (field.inputType === 'number' || field.inputType === 'percentage') {
                    updateFieldValue(field.id, event.target.value === '' ? null : Number(event.target.value));

                    return;
                  }

                  updateFieldValue(field.id, event.target.value);
                }}
                className="min-h-12 w-full rounded-lg border-2 border-neutral-200 bg-neutral-50 px-4 py-3 text-base text-neutral-900 focus:border-red-500 focus:outline-none"
              />
            </div>
          );
        })}
      </section>

      <section aria-labelledby="scheduled-duties-heading" className="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 id="scheduled-duties-heading" className="font-heading text-lg font-bold text-neutral-800">Scheduled duties due today</h3>
            {checklist.inspection_date && <p className="text-sm text-neutral-500">Inspection date: {checklist.inspection_date}</p>}
          </div>
          {scheduledTasks.length > 0 && (
            <button
              type="button"
              aria-label="Mark all due duties as present"
              onClick={markAllDueTasksPresent}
              className="min-h-11 rounded-lg border border-green-300 bg-green-50 px-4 py-2 text-sm font-semibold text-green-800 hover:bg-green-100"
            >
              Mark all present
            </button>
          )}
        </div>

        {scheduledTasks.length === 0 ? (
          <p className="text-sm text-neutral-600">No scheduled duties are due for this inspection date.</p>
        ) : (
          <div className="space-y-4">
            {checklist.due_tasks.map((task) => {
              const result = scheduledTasks.find((scheduledTask) => scheduledTask.id === task.id);
              if (!result) return null;

              return (
                <div key={task.id} className="rounded-lg border border-neutral-200 p-3">
                  <p className="font-semibold text-neutral-900">{task.name}</p>
                  {task.instructions && <p className="mt-1 text-sm text-neutral-600">{task.instructions}</p>}
                  <div className="mt-3 flex gap-2" role="group" aria-label={`Status for ${task.name}`}>
                    {(['Present', 'Missing', 'Damaged'] as const).map((status) => (
                      <button
                        key={status}
                        type="button"
                        onClick={() => updateScheduledTask(task.id, status)}
                        aria-pressed={result.status === status}
                        className={`min-h-11 flex-1 rounded-lg border px-3 py-2 text-sm font-semibold ${
                          result.status === status
                            ? status === 'Present'
                              ? 'border-green-600 bg-green-600 text-white'
                              : status === 'Missing'
                                ? 'border-red-600 bg-red-600 text-white'
                                : 'border-amber-500 bg-amber-500 text-white'
                            : 'border-neutral-300 bg-white text-neutral-700'
                        }`}
                      >
                        {status}
                      </button>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </section>

      <div className="flex gap-3">
        <button
          type="button"
          onClick={onBack}
          className="min-h-12 flex-1 rounded-lg border-2 border-neutral-200 px-4 py-3 font-semibold text-neutral-700 hover:bg-neutral-50"
        >
          Back
        </button>
        <button
          type="submit"
          className="min-h-12 flex-1 rounded-lg bg-red-600 px-4 py-3 font-semibold text-white hover:bg-red-700"
        >
          Continue to Compartment Inspection
        </button>
      </div>
    </form>
  );
}
