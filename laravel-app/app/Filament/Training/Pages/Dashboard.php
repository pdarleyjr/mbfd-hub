<?php

namespace App\Filament\Training\Pages;

use App\Filament\Training\Resources\TrainingTodoResource;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dashboard';
    
    protected static ?string $title = 'Training Dashboard';
    
    public function getSubheading(): ?string
    {
        return 'Current training work queue, external sources, and division tools.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTrainingTodo')
                ->label('New Training Todo')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->url('#'), // Will link to training todo resource create
                
            Action::make('openChatify')
                ->label('Open Chatify')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->url('/chatify'),
                
            Action::make('externalSources')
                ->label('External Sources')
                ->icon('heroicon-o-globe-alt')
                ->color('gray')
                ->modalHeading('External Training Sources')
                ->modalDescription('Quick access to external training tools and resources.')
                ->form([
                    \Filament\Forms\Components\TextInput::make('baserow_url')
                        ->label('Baserow URL')
                        ->helperText('Access the Baserow training database')
                        ->readonly()
                        ->default(config('services.baserow.url', 'https://baserow.darleyplex.com')),
                        
                    \Filament\Forms\Components\TextInput::create('resource_link')
                        ->label('Training Resources')
                        ->helperText('Central training documentation portal')
                        ->readonly()
                        ->default('https://docs.mbfdhub.com/training'),
                ])
                ->modalSubmitActionLabel('Open'),
                
            Action::make('openBaserow')
                ->label('Open Baserow')
                ->icon('heroicon-o-table-cells')
                ->color('amber')
                ->url(config('services.baserow.url', 'https://baserow.darleyplex.com'))
                ->openUrlInNewTab(),
        ];
    }

    public function getMaxContentWidth(): ?string
    {
        return '5xl';
    }
}
