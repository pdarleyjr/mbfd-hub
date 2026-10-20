<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingRosterAudit;
use App\Models\Training\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TrainingCourseModuleService
{
    public function createModule(TrainingCourse $course, array $data, User $actor): TrainingModule
    {
        $this->authorize($actor);
        $attributes = $this->moduleData($data);

        return DB::transaction(function () use ($course, $attributes, $actor): TrainingModule {
            $course = TrainingCourse::query()->lockForUpdate()->findOrFail($course->id);
            $module = $course->modules()->create($attributes + ['created_by' => $actor->id]);
            $this->audit('module.created', $actor, null, null, $module->toArray());

            return $module;
        });
    }

    public function updateModule(TrainingModule $module, array $data, User $actor): TrainingModule
    {
        $this->authorize($actor);
        $attributes = $this->moduleData($data);

        return DB::transaction(function () use ($module, $attributes, $actor): TrainingModule {
            TrainingCourse::query()->lockForUpdate()->findOrFail($module->training_course_id);
            $module = TrainingModule::query()->lockForUpdate()->findOrFail($module->id);
            $before = $module->toArray();
            $module->update($attributes);
            $this->audit('module.updated', $actor, null, $before, $module->toArray());

            return $module;
        });
    }

    /** Reporting attribution only: original digital revisions and artifacts remain immutable. */
    public function mapSession(TrainingSession $session, ?TrainingModule $module, User $actor, string $reason, int $expectedRevision, ?int $expectedModuleId): TrainingSession
    {
        $this->authorize($actor);
        $reason = Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\S/u']])->validate()['reason'];

        return DB::transaction(function () use ($session, $module, $actor, $reason, $expectedRevision, $expectedModuleId): TrainingSession {
            TrainingCourse::query()->lockForUpdate()->findOrFail($session->training_course_id);
            $session = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->revision !== $expectedRevision || $session->training_module_id !== $expectedModuleId) {
                throw ValidationException::withMessages(['training_module_id' => 'This roster or its module changed. Reload and review before mapping it.']);
            }
            if ($module !== null && ! TrainingModule::query()->whereKey($module->id)->where('training_course_id', $session->training_course_id)->exists()) {
                throw ValidationException::withMessages(['training_module_id' => 'Choose a module belonging to this course.']);
            }
            $newId = $module?->id;
            if ($session->training_module_id === $newId) {
                return $session;
            }
            $before = ['training_module_id' => $session->training_module_id];
            $session->update(['training_module_id' => $newId]);
            $this->audit('session.module_mapped', $actor, $session, $before, ['training_module_id' => $newId], $reason);

            return $session;
        });
    }

    /** @return list<string> */
    public static function audienceRanks(): array
    {
        return collect(['Captain', 'Lieutenant', 'Firefighter'])
            ->merge(Employee::query()->whereNotNull('rank')->distinct()->pluck('rank'))->filter()->unique()->sort()->values()->all();
    }

    /** @return list<string> */
    public static function audienceShifts(): array
    {
        return ['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'];
    }

    private function moduleData(array $data): array
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:200', 'regex:/\S/u'],
            'description' => ['nullable', 'string', 'max:10000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_required' => ['sometimes', 'boolean'],
            'audience' => ['nullable', 'array:ranks,shifts'],
            'audience.ranks' => ['sometimes', 'array', 'max:30'],
            'audience.ranks.*' => ['required', 'string', Rule::in(self::audienceRanks()), 'distinct'],
            'audience.shifts' => ['sometimes', 'array', 'max:5'],
            'audience.shifts.*' => ['required', 'string', Rule::in(self::audienceShifts()), 'distinct'],
        ])->validate();
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isAuthenticationAllowed(), 403);
        Gate::forUser($actor)->authorize('create', TrainingSession::class);
    }

    private function audit(string $action, User $actor, ?TrainingSession $session, ?array $before, array $after, ?string $reason = null): void
    {
        TrainingRosterAudit::query()->create([
            'training_session_id' => $session?->id, 'actor_user_id' => $actor->id,
            'action' => $action, 'before' => $before, 'after' => $after, 'reason' => $reason, 'created_at' => now()->utc(),
        ]);
    }
}
