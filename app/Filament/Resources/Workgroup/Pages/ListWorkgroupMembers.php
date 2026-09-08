<?php

namespace App\Filament\Resources\Workgroup\Pages;

use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use App\Models\User;
use App\Models\Workgroup;
use App\Services\Workgroup\WorkgroupMembershipService;
use App\Support\Workgroups\WorkgroupAccess;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWorkgroupMembers extends ListRecords
{
    protected static string $resource = WorkgroupMemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addMembers')
                ->label('Add Members')
                ->icon('heroicon-o-user-plus')
                ->authorize(fn (): bool => WorkgroupMemberResource::canCreate())
                ->form([
                    Forms\Components\Select::make('workgroup_id')
                        ->label('Workgroup')
                        ->options(function (): array {
                            $user = auth()->user();

                            return $user instanceof User
                                ? app(WorkgroupAccess::class)
                                    ->scopeManageWorkgroups(Workgroup::query(), $user)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all()
                                : [];
                        })
                        ->live()
                        ->searchable()
                        ->required(),
                    Forms\Components\Select::make('user_ids')
                        ->label('Members')
                        ->options(function (callable $get): array {
                            $workgroup = Workgroup::query()->find($get('workgroup_id'));

                            return $workgroup instanceof Workgroup
                                ? app(WorkgroupMembershipService::class)
                                    ->availableUsers($workgroup)
                                    ->orderBy('name')
                                    ->get(['id', 'name', 'email'])
                                    ->mapWithKeys(fn (User $user): array => [
                                        $user->id => "{$user->name} ({$user->email})",
                                    ])
                                    ->all()
                                : [];
                        })
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Select one or more existing users. Memberships in other workgroups are allowed.'),
                    Forms\Components\Select::make('role')
                        ->options([
                            'admin' => 'Admin',
                            'facilitator' => 'Facilitator',
                            'member' => 'Member',
                        ])
                        ->default('member')
                        ->required(),
                    Forms\Components\Toggle::make('count_evaluations')
                        ->label('Count Evaluations')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    $workgroup = Workgroup::query()->find($data['workgroup_id'] ?? null);

                    abort_unless(
                        $user instanceof User
                        && $workgroup instanceof Workgroup
                        && app(WorkgroupAccess::class)->canManageWorkgroup($user, $workgroup),
                        404,
                    );

                    $added = app(WorkgroupMembershipService::class)->addUsers(
                        $workgroup,
                        $data['user_ids'] ?? [],
                        (string) ($data['role'] ?? 'member'),
                        (bool) ($data['count_evaluations'] ?? true),
                    );

                    Notification::make()
                        ->success()
                        ->title($added === 1 ? '1 member added' : "{$added} members added")
                        ->send();
                }),
        ];
    }
}
