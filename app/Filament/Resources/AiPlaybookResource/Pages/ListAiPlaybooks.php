<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPlaybookResource\Pages;

use App\Filament\Resources\AiPlaybookResource;
use App\Models\AiAutomation\AiAutomationPlaybook;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

final class ListAiPlaybooks extends ListRecords
{
    protected static string $resource = AiPlaybookResource::class;

    public function getTitle(): string
    {
        return 'Response Messages';
    }

    public function getSubheading(): string
    {
        return 'The wording of the automated replies. Open a message to change it, preview it, and go back to an earlier version if needed.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'messages' => Tab::make('Messages')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', AiAutomationPlaybook::KIND_TEMPLATE)),
            'lists' => Tab::make('Advanced: word lists')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('kind', AiAutomationPlaybook::KIND_LIST)),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'messages';
    }
}
