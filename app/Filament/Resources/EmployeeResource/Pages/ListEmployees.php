<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Enums\AccountStatus;
use App\Filament\Resources\EmployeeResource;
use App\Jobs\IssueMemberOnboardingInvitation;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\MemberOnboardingInvitationService;
use App\Services\Security\SecurityAuditRecorder;
use Filament\Actions;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('accountExceptions')->label(fn (): string => 'Account exceptions ('.\App\Models\User::query()->whereNull('employee_profile_id')->count().')')
                ->visible(fn (): bool => \App\Filament\Resources\AccountProfileResource::canViewAny())
                ->url(\App\Filament\Resources\AccountProfileResource::getUrl()),
            Actions\Action::make('sendOnboardingInvitations')
                ->label('Send onboarding invitations')
                ->icon('heroicon-o-envelope')
                ->visible(fn (): bool => $this->canIssueInvitations())
                ->modalHeading('Invite members awaiting activation')
                ->modalDescription('Only active personnel with an approved Employee ID–City email pairing and an unconfirmed Hub account are eligible. Already-invited members are skipped.')
                ->modalSubmitActionLabel('Send invitations')
                ->fillForm(fn (): array => ['cohort_hash' => $this->invitationCohort()['hash']])
                ->form([
                    Forms\Placeholder::make('invitation_preview')->label('Exact recipients')
                        ->content(fn (): HtmlString => $this->invitationPreview()),
                    Forms\Hidden::make('cohort_hash')->required(),
                    Forms\Checkbox::make('confirm_recipients')
                        ->label('I reviewed the recipients and want to send these invitations now.')
                        ->rule('accepted')->required(),
                    Forms\TextInput::make('current_password')->label('Your administrator password')
                        ->password()->autocomplete('current-password')->required(),
                ])
                ->action(fn (array $data) => $this->queueOnboardingInvitations($data)),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => \Filament\Resources\Components\Tab::make('All employees'),
            'awaiting' => \Filament\Resources\Components\Tab::make('Awaiting account')->modifyQueryUsing(fn ($query) => $query->whereDoesntHave('user')),
            'activation' => \Filament\Resources\Components\Tab::make('Awaiting activation')->modifyQueryUsing(fn ($query) => $query->whereHas('user', fn ($users) => $users->where('account_status', AccountStatus::PendingActivation->value))),
            'active' => \Filament\Resources\Components\Tab::make('Active logins')->modifyQueryUsing(fn ($query) => $query->whereHas('user', fn ($users) => $users->where('account_status', 'active'))),
            'disabled' => \Filament\Resources\Components\Tab::make('Disabled logins')->modifyQueryUsing(fn ($query) => $query->whereHas('user', fn ($users) => $users->where('account_status', 'disabled'))),
        ];
    }

    public static function canIssueInvitations(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isAuthenticationAllowed() && $actor->hasRole('super_admin');
    }

    /** @param array<string,mixed> $data */
    public function queueMemberInvitation(Employee $record, array $data): void
    {
        abort_unless(self::canIssueInvitations(), 403);
        $actor = auth()->user();
        assert($actor instanceof User);
        if (($data['confirm_member'] ?? false) !== true) {
            $this->tableActionError('confirm_member', 'Review and confirm this member before sending.');
        }
        if (! Hash::check((string) ($data['current_password'] ?? ''), $actor->password)) {
            $this->tableActionError('current_password', 'Your password is incorrect.');
        }
        if (config('queue.default') === 'sync') {
            $this->tableActionError('confirm_member', 'Background delivery is unavailable. No invitation was queued.');
        }

        $employee = Employee::query()->with('user')->findOrFail($record->id);
        $assessment = app(MemberOnboardingInvitationService::class)->assess($employee);
        $user = $employee->user;
        if ($assessment['status'] !== 'ready' || $user === null) {
            $this->tableActionError('confirm_member', 'This member is no longer eligible. Reopen the action to review the current status.');
        }
        $bindingHash = IssueMemberOnboardingInvitation::bindingHash(
            $user->id,
            $employee->employee_id,
            (string) $employee->city_email,
            $user->security_version,
        );
        if (! hash_equals($bindingHash, (string) ($data['binding_hash'] ?? ''))) {
            $this->tableActionError('confirm_member', 'This member’s account or City email changed. Reopen the action before sending.');
        }

        app(SecurityAuditRecorder::class)->record($actor, $user, 'member_onboarding_invitation_single_requested', 'allowed', null, [
            'employee_profile_id' => $employee->id,
        ]);
        IssueMemberOnboardingInvitation::dispatch($user->id, $actor->id, $bindingHash);

        Notification::make()->success()->title('Invitation queued')
            ->body('Delivery for this member is recorded in Outbound Email.')
            ->send();
    }

    /** @return array{ready:list<array{user_id:int,employee_id:string,email:string,binding_hash:string}>,statuses:array<string,int>,hash:string} */
    private function invitationCohort(): array
    {
        $invitations = app(MemberOnboardingInvitationService::class);
        $ready = [];
        $statuses = [];
        $employees = Employee::query()
            ->whereHas('user', fn ($query) => $query->where('account_status', AccountStatus::PendingActivation->value))
            ->with('user')
            ->orderBy('employee_id')
            ->get();
        $assessments = $invitations->assessMany($employees);

        foreach ($employees as $employee) {
            $assessment = $assessments[$employee->id];
            $status = $assessment['status'];
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            if ($status !== 'ready' || $employee->user === null) {
                continue;
            }
            $ready[] = [
                'user_id' => $employee->user->id,
                'employee_id' => $employee->employee_id,
                'email' => strtolower(trim((string) $employee->city_email)),
                'binding_hash' => IssueMemberOnboardingInvitation::bindingHash(
                    $employee->user->id,
                    $employee->employee_id,
                    (string) $employee->city_email,
                    $employee->user->security_version,
                ),
            ];
        }
        ksort($statuses);

        return [
            'ready' => $ready,
            'statuses' => $statuses,
            'hash' => hash('sha256', json_encode([$ready, $statuses], JSON_THROW_ON_ERROR)),
        ];
    }

    private function invitationPreview(): HtmlString
    {
        $cohort = $this->invitationCohort();
        $ready = $cohort['ready'];
        $skipped = array_sum($cohort['statuses']) - count($ready);
        $summary = '<p><strong>'.count($ready).'</strong> ready to invite; <strong>'.$skipped.'</strong> skipped. No email is sent until you confirm.</p>';
        $labels = [
            'already_invited' => 'Already invited',
            'missing_authoritative_city_email' => 'Missing City email',
            'unapproved_roster_binding' => 'Not in approved roster',
            'email_conflict' => 'Email conflict',
            'duplicate_employee_id' => 'Duplicate Employee ID',
            'identity_conflict' => 'Account conflict',
            'not_pending_onboarding' => 'Not eligible',
        ];
        foreach ($cohort['statuses'] as $status => $count) {
            if ($status !== 'ready') {
                $summary .= '<p>'.e($labels[$status] ?? 'Other blocked').' : '.$count.'</p>';
            }
        }
        if ($ready === []) {
            return new HtmlString($summary.'<p>There are no eligible recipients. Check roster approval and account status before trying again.</p>');
        }
        $rows = array_map(static fn (array $row): string => '<li>'.e($row['employee_id']).' — '.e($row['email']).'</li>', $ready);

        return new HtmlString($summary.'<div style="max-height:16rem;overflow:auto"><ul>'.implode('', $rows).'</ul></div>');
    }

    /** @param array<string,mixed> $data */
    private function queueOnboardingInvitations(array $data): void
    {
        abort_unless($this->canIssueInvitations(), 403);
        $actor = auth()->user();
        assert($actor instanceof User);
        if (($data['confirm_recipients'] ?? false) !== true) {
            $this->actionError('confirm_recipients', 'Review and confirm the recipients before sending.');
        }
        if (! Hash::check((string) ($data['current_password'] ?? ''), $actor->password)) {
            $this->actionError('current_password', 'Your password is incorrect.');
        }
        if (config('queue.default') === 'sync') {
            $this->actionError('confirm_recipients', 'Background delivery is unavailable. No invitations were queued.');
        }
        $cohort = $this->invitationCohort();
        if ($cohort['ready'] === [] || ! hash_equals($cohort['hash'], (string) ($data['cohort_hash'] ?? ''))) {
            $this->actionError('confirm_recipients', 'The recipient list changed or is empty. Reopen this action to review it again. No invitations were queued.');
        }

        app(SecurityAuditRecorder::class)->record($actor, $actor, 'member_onboarding_invitation_batch_requested', 'allowed', null, [
            'recipient_count' => count($cohort['ready']),
            'skipped_count' => array_sum($cohort['statuses']) - count($cohort['ready']),
            'cohort_sha256' => $cohort['hash'],
        ]);
        foreach ($cohort['ready'] as $recipient) {
            IssueMemberOnboardingInvitation::dispatch($recipient['user_id'], $actor->id, $recipient['binding_hash']);
        }

        Notification::make()->success()->title('Invitations queued')
            ->body(count($cohort['ready']).' unconfirmed members selected. Delivery status is recorded in Outbound Email.')
            ->send();
    }

    private function actionError(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $this->getMountedActionForm()->getStatePath().'.'.$field => $message,
        ]);
    }

    private function tableActionError(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $this->getMountedTableActionForm()->getStatePath().'.'.$field => $message,
        ]);
    }
}
