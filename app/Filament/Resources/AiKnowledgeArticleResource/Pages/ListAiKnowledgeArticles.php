<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKnowledgeArticleResource\Pages;

use App\Filament\Resources\AiKnowledgeArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAiKnowledgeArticles extends ListRecords
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New article')];
    }
}
