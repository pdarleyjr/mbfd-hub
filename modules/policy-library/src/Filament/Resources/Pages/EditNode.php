<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Mbfd\PolicyLibrary\Filament\Resources\NodeResource;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\TreeService;

final class EditNode extends EditRecord
{
    protected static string $resource = NodeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof ManualNode) {
            throw new \LogicException('Navigation entry not found.');
        }
        app(TreeService::class)->updateNode($record, $data);

        return $record;
    }
}
