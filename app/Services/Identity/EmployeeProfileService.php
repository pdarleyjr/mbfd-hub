<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\Employee;
use App\Models\EmployeeProfileEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class EmployeeProfileService
{
    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Employee $employee, array $attributes, ?string $currentPassword = null, ?string $reason = null): Employee
    {
        try {
            $allowed = [...Employee::PROFILE_FIELDS, 'city_email'];
            if (array_diff(array_keys($attributes), $allowed) !== []) {
                throw ValidationException::withMessages(['profile' => 'Identity, access, and employment status must use their dedicated actions.']);
            }
            foreach ($attributes as $field => $value) {
                if (is_string($value)) {
                    $attributes[$field] = trim($value);
                }
            }
            $attributes = Validator::make($attributes, [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'rank' => ['sometimes', 'nullable', 'string', 'max:255'],
                'station' => ['sometimes', 'nullable', 'string', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
                'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'city_email' => ['sometimes', 'required', 'email:rfc', 'max:254'],
            ])->validate();

            $linkedId = User::query()->where('employee_profile_id', $employee->getKey())->value('id');

            return DB::transaction(function () use ($actor, $employee, $attributes, $linkedId, $currentPassword, $reason): Employee {
                // Administrative writers share this guard before participant locks.
                app(\App\Services\Security\LastCriticalAdministratorGuard::class)->lockActiveCriticalAdministrators();
                // Then lock participant Users in PK order, followed by Employee.
                $users = User::query()->whereKey(array_filter([$actor->getKey(), $linkedId]))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $currentActor = $users->get($actor->getKey());
                if (! $currentActor instanceof User || ! $currentActor->isAuthenticationAllowed()) {
                    throw new AuthorizationException;
                }
                $currentEmployee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
                $currentLinkedId = User::query()->where('employee_profile_id', $currentEmployee->getKey())->value('id');
                if ($currentLinkedId !== $linkedId) {
                    throw ValidationException::withMessages(['profile' => 'The account link changed. Reload the personnel record before saving.']);
                }
                $linkedUser = $linkedId === null ? null : $users->get($linkedId);
                if ($linkedUser !== null && $linkedUser->employee_id !== $currentEmployee->employee_id) {
                    throw ValidationException::withMessages(['profile' => 'The personnel and account identity must be reconciled before editing.']);
                }
                $manager = $currentActor->hasRole('super_admin')
                    || $currentActor->can('admin.personnel.manage')
                    || $currentActor->can('admin.members.manage');
                $self = $linkedUser !== null && $linkedUser->is($currentActor);
                if (($self && array_diff(array_keys($attributes), ['phone', 'display_name']) !== [])
                    || (! $self && ! $manager)) {
                    throw new AuthorizationException;
                }

                $changesEmail = array_key_exists('city_email', $attributes)
                    && ($currentEmployee->city_email !== strtolower($attributes['city_email'])
                        || ($linkedUser !== null && $linkedUser->email !== strtolower($attributes['city_email'])));
                if ($changesEmail && ! $currentActor->hasRole('super_admin')) {
                    throw new AuthorizationException('Recovery identity changes require a security administrator.');
                }
                if ($changesEmail && (blank($reason) || mb_strlen($reason) > 500)) {
                    throw ValidationException::withMessages(['reason' => 'An audit reason of 1 to 500 characters is required.']);
                }
                if ($changesEmail && ($currentPassword === null
                    || ! Hash::check($currentPassword, $currentActor->getAuthPassword()))) {
                    throw new CurrentPasswordMismatch('The current password is incorrect.');
                }

                $changes = $attributes;
                unset($changes['city_email']);
                $currentEmployee->fill($changes);
                $changedFields = array_keys($currentEmployee->getDirty());
                $currentEmployee->save();

                if (array_key_exists('city_email', $attributes)) {
                    $email = strtolower($attributes['city_email']);
                    if ($changesEmail) {
                        $changedFields[] = 'city_email';
                    }
                    try {
                        if ($linkedUser !== null) {
                            if ($changesEmail) {
                                // Delete the old-address credential before the canonical email changes.
                                // revokeAll below also deletes credentials at the new destination.
                                $broker = Password::broker();
                                if (! $broker instanceof PasswordBroker) {
                                    throw new \LogicException('The configured password broker does not support token persistence.');
                                }
                                $broker->deleteToken($linkedUser);
                            }
                            app(CanonicalCityEmailService::class)->sync($currentEmployee, $linkedUser, $email);
                            if ($changesEmail) {
                                app(AccountSecurityService::class)->revokeAll($linkedUser->fresh(), 'city email changed', now());
                            }
                        } else {
                            // Roster-only personnel have no recovery destination or login to provision.
                            if (! str_ends_with($email, '@miamibeachfl.gov')
                                || Employee::query()->whereKeyNot($currentEmployee->getKey())->whereRaw('LOWER(city_email) = ?', [$email])->exists()
                                || User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                                throw new InvalidArgumentException;
                            }
                            $currentEmployee->forceFill(['city_email' => $email])->save();
                        }
                    } catch (InvalidArgumentException|UniqueConstraintViolationException) {
                        throw ValidationException::withMessages(['city_email' => 'Use a unique, valid city email for this member.']);
                    }
                }

                if ($changedFields !== []) {
                    EmployeeProfileEvent::query()->create([
                        'employee_id' => $currentEmployee->getKey(),
                        'actor_user_id' => $currentActor->getKey(),
                        'target_user_id' => $linkedId,
                        'action' => 'profile_updated',
                        'result' => 'success',
                        'reason' => $changesEmail ? trim($reason) : null,
                        'metadata' => ['fields' => $changedFields],
                    ]);
                }

                return $currentEmployee->fresh();
            }, 3);
        } catch (Throwable $exception) {
            // Audit outside the rolled-back profile transaction. Ordinary profile
            // validation keeps its existing behavior; only email attempts enter here.
            if (array_key_exists('city_email', $attributes)
                && Employee::query()->whereKey($employee->getKey())->exists()) {
                EmployeeProfileEvent::query()->create([
                    'employee_id' => $employee->getKey(),
                    'actor_user_id' => User::query()->whereKey($actor->getKey())->value('id'),
                    'target_user_id' => User::query()->where('employee_profile_id', $employee->getKey())->value('id'),
                    'action' => 'profile_updated',
                    'result' => $exception instanceof AuthorizationException || $exception instanceof ValidationException ? 'denied' : 'failed',
                    'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 500),
                    'metadata' => ['fields' => array_values(array_intersect(array_keys($attributes), [...Employee::PROFILE_FIELDS, 'city_email']))],
                ]);
            }
            throw $exception;
        }
    }
}
