<?php

namespace App\Filament\Resources\Workgroup\RelationManagers;

use App\Models\WorkgroupMember;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class AttendanceRelationManager extends RelationManager
{
    protected static string $relationship = 'attendees';

    protected static ?string $title = 'Session Attendance';

    protected static ?string $recordTitleAttribute = 'id';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->heading('Attendance — Members who attended this session')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Member Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin'       => 'danger',
                        'facilitator' => 'warning',
                        'member'      => 'info',
                        default       => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
            ])
            ->headerActions([
                // Native Filament AttachAction — lets admins pick an existing member to add to attendance
                Tables\Actions\AttachAction::make()
                    ->label('Add Member')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(function (\Illuminate\Database\Eloquent\Builder $query) {
                        $session = $this->getOwnerRecord();
                        // Only show active members from the same workgroup
                        return $query
                            ->where('is_active', true)
                            ->where('workgroup_id', $session->workgroup_id)
                            ->with('user');
                    })
                    ->recordSelectSearchColumns(['user.name', 'user.email'])
                    ->after(function () {
                        Notification::make()
                            ->title('Member added to session attendance')
                            ->success()
                            ->send();
                    }),

                // Convenience action — sync ALL active workgroup members as attendees at once
                Tables\Actions\Action::make('markAllAttending')
                    ->label('Mark All Active Members Present')
                    ->icon('heroicon-o-user-group')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Mark all active members as attending?')
                    ->modalDescription('This will add ALL active members from this workgroup to the session attendance list. Existing attendance records are preserved.')
                    ->action(function (): void {
                        $session = $this->getOwnerRecord();
                        $allMemberIds = WorkgroupMember::where('is_active', true)
                            ->where('workgroup_id', $session->workgroup_id)
                            ->pluck('id')
                            ->toArray();

                        // sync(false) = attach missing, do not detach existing
                        $session->attendees()->sync($allMemberIds);

                        Notification::make()
                            ->title('Attendance updated')
                            ->body(count($allMemberIds) . ' active member(s) marked as attending.')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->label('Remove from Attendance'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make()
                        ->label('Remove Selected'),
                ]),
            ]);
    }
}
