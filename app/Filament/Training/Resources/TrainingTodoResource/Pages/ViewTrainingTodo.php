<?php

namespace App\Filament\Training\Resources\TrainingTodoResource\Pages;

use App\Filament\Training\Resources\TrainingTodoResource;
use App\Models\Training\TrainingTodo;
use App\Models\Training\TrainingTodoUpdate;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

class ViewTrainingTodo extends ViewRecord
{
    protected static string $resource = TrainingTodoResource::class;

    public function getRecord(): TrainingTodo
    {
        /** @var TrainingTodo $record */
        $record = parent::getRecord();

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->visible(fn (): bool => ! $this->getRecord()->trashed() && static::getResource()::canEdit($this->getRecord())),
            Actions\RestoreAction::make(),
            Actions\Action::make('addUpdate')
                ->label('Add Update')
                ->icon('heroicon-o-plus-circle')
                ->visible(fn (): bool => ! $this->getRecord()->trashed() && (auth()->user()?->can('update', $this->getRecord()) ?? false))
                ->form([
                    Forms\Components\Textarea::make('comment')
                        ->label('Update Comment')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    abort_unless(! $this->getRecord()->trashed() && auth()->user()?->can('update', $this->getRecord()), 403);

                    TrainingTodoUpdate::create([
                        'training_todo_id' => $this->getRecord()->id,
                        'user_id' => auth()->id(),
                        'username' => auth()->user()->name,
                        'comment' => $data['comment'],
                    ]);

                    $this->refreshFormData(['updates']);
                }),
        ];
    }

    public function deleteUpdate(int $updateId): void
    {
        abort_unless(! $this->getRecord()->trashed() && auth()->user()?->can('update', $this->getRecord()), 403);
        $update = $this->getRecord()->updates()->find($updateId);

        if (! $update) {
            Notification::make()
                ->title('Update not found')
                ->danger()
                ->send();

            return;
        }

        $update->delete();

        Notification::make()
            ->title('Update moved to Trash')
            ->success()
            ->send();
    }

    public function restoreUpdate(int $updateId): void
    {
        abort_unless(! $this->getRecord()->trashed() && auth()->user()?->can('update', $this->getRecord()), 403);
        $update = $this->getRecord()->updates()->onlyTrashed()->find($updateId);
        abort_unless($update !== null, 404);
        $update->restore();

        Notification::make()->title('Update restored')->success()->send();
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Task Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('title')
                            ->label('Title'),
                        Infolists\Components\TextEntry::make('deleted_at')
                            ->label('Moved to Trash')->dateTime()->visible(fn ($record): bool => $record->trashed()),
                        Infolists\Components\TextEntry::make('description')
                            ->label('Description')
                            ->html(),
                        Infolists\Components\TextEntry::make('assignee_names')
                            ->label('Assigned To')
                            ->badge()
                            ->color('primary'),
                        Infolists\Components\TextEntry::make('createdBy.name')
                            ->label('Created By'),
                        Infolists\Components\IconEntry::make('is_completed')
                            ->label('Status')
                            ->boolean()
                            ->trueIcon('heroicon-o-check-circle')
                            ->falseIcon('heroicon-o-x-circle')
                            ->trueColor('success')
                            ->falseColor('gray'),
                        Infolists\Components\TextEntry::make('completed_at')
                            ->label('Completed At')
                            ->dateTime()
                            ->visible(fn ($record) => $record->is_completed),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime(),
                    ])
                    ->columns(2),
                Infolists\Components\Section::make('Attachments')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('attachments')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('')
                                    ->formatStateUsing(function ($state) {
                                        $filename = basename($state);
                                        $url = asset('storage/'.$state);

                                        return new HtmlString(
                                            '<a href="'.$url.'" target="_blank" class="text-primary-600 hover:underline">'.
                                            '<span class="inline-flex items-center gap-1">'.
                                            '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>'.
                                            htmlspecialchars($filename).
                                            '</span></a>'
                                        );
                                    }),
                            ])
                            ->contained(false),
                    ])
                    ->visible(fn ($record) => ! empty($record->attachments))
                    ->collapsible(),
                Infolists\Components\Section::make('Updates')
                    ->schema([
                        Infolists\Components\ViewEntry::make('updates')
                            ->label('')
                            ->view('filament.infolists.todo-updates'),
                    ])
                    ->collapsible(),
            ]);
    }
}
