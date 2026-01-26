<?php

namespace App\Filament\Resources\TodoResource\Pages;

use App\Filament\Resources\TodoResource;
use App\Models\TodoUpdate;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Component;
use Illuminate\Support\HtmlString;

class ViewTodo extends ViewRecord
{
    protected static string $resource = TodoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
            
            Actions\Action::make('addUpdate')
                ->label('Add Update')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->form([
                    Forms\Components\Textarea::make('comment')
                        ->label('Update / Comment')
                        ->required()
                        ->rows(4)
                        ->placeholder('Add a progress note or comment about this todo item...')
                        ->columnSpanFull(),
                ])
                ->action(function (array $data) {
                    TodoUpdate::create([
                        'todo_id' => $this->record->id,
                        'user_id' => auth()->id(),
                        'username' => auth()->user()->name,
                        'comment' => $data['comment'],
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Update added successfully')
                        ->send();
                        
                    // Refresh the page to show the new update
                    $this->redirect(static::getUrl(['record' => $this->record]));
                })
                ->modalHeading('Add Update')
                ->modalSubmitActionLabel('Add Update')
                ->modalWidth('lg'),
        ];
    }

    public function getContentTabLabel(): ?string
    {
        return null; // Hide the content tab label
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Load updates for display
        $data['updates_list'] = $this->record->updates()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($update) {
                return [
                    'username' => $update->username,
                    'created_at' => $update->created_at->format('M j, Y g:i A'),
                    'comment' => $update->comment,
                ];
            })
            ->toArray();

        return $data;
    }
}
