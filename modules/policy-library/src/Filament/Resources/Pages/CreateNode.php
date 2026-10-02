<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mbfd\PolicyLibrary\Filament\Resources\NodeResource;
use Mbfd\PolicyLibrary\Models\Edition;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\TreeService;

final class CreateNode extends CreateRecord
{
    protected static string $resource = NodeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): ManualNode {
            validator($data, ['edition_id' => 'required|integer|exists:policy_editions,id'])->validate();
            $edition = Edition::query()->findOrFail((int) $data['edition_id']);
            $node = ManualNode::query()->create(['manual_id' => $edition->manual_id, 'edition_id' => $edition->id, 'title' => $data['title'], 'slug' => $data['slug'], 'type' => $data['type']]);
            app(TreeService::class)->updateNode($node, $data);

            return $node;
        });
    }
}
