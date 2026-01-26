<?php

namespace App\Filament\Resources\TodoResource\Pages;

use App\Filament\Resources\TodoResource;
use App\Models\TodoUpdate;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

class ViewTodo extends ViewRecord
{
    protected static string $resource = TodoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\Action::make('addUpdate')
                ->label('Add Update')
                ->icon('heroicon-o-plus-circle')
                ->form([
                    Forms\Components\Textarea::make('comment')
                        ->label('Update Comment')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    TodoUpdate::create([
                        'todo_id' => $this->record->id,
                        'user_id' => auth()->id(),
                        'username' => auth()->user()->name,
                        'comment' => $data['comment'],
                    ]);
                    $this->refreshFormData(['updates']);
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Task Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('title')
                            ->label('Title'),
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
                                        $url = asset('storage/' . $state);
                                        return new HtmlString(
                                            '<a href="' . $url . '" target="_blank" class="text-primary-600 hover:underline">' .
                                            '<span class="inline-flex items-center gap-1">' .
                                            '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>' .
                                            htmlspecialchars($filename) .
                                            '</span></a>'
                                        );
                                    }),
                            ])
                            ->contained(false),
                    ])
                    ->visible(fn ($record) => !empty($record->attachments))
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
