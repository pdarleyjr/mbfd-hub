<?php

namespace App\Filament\Resources\Workgroup\RelationManagers;

use App\Filament\Resources\Workgroup\RelationManagers\Concerns\AuthorizesWorkgroupOwner;
use App\Models\User;
use App\Models\Workgroup;
use App\Services\Workgroup\WorkgroupMembershipService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MembersRelationManager extends RelationManager
{
    use AuthorizesWorkgroupOwner;

    protected static string $relationship = 'members';

    protected static ?string $title = 'Members';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->label('User')
                    ->options(fn () => User::query()
                        ->orderBy('name')
                        ->get(['id', 'name', 'email'])
                        ->mapWithKeys(fn (User $user): array => [
                            $user->id => "{$user->name} ({$user->email})",
                        ]))
                    ->searchable()
                    ->disabledOn('edit')
                    ->required(),
                Forms\Components\Select::make('role')
                    ->label('Role')
                    ->options([
                        'admin' => 'Admin',
                        'facilitator' => 'Facilitator',
                        'member' => 'Member',
                    ])
                    ->default('member')
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Forms\Components\Toggle::make('count_evaluations')
                    ->label('Count Evaluations')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('user.name')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'facilitator' => 'warning',
                        'member' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->label('Joined'),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')
                    ->query(fn (Builder $query) => $query->where('is_active', true))
                    ->label('Active Only'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addMembers')
                    ->label('Add Members')
                    ->icon('heroicon-o-user-plus')
                    ->authorize(fn (self $livewire): bool => $livewire->canManageOwner())
                    ->form([
                        Forms\Components\Select::make('user_ids')
                            ->label('Members')
                            ->options(function (): array {
                                $workgroup = $this->getOwnerRecord();

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
                        abort_unless($this->canManageOwner(), 404);

                        $workgroup = $this->getOwnerRecord();
                        abort_unless($workgroup instanceof Workgroup, 404);

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
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
