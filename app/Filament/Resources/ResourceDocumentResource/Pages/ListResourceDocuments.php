<?php

declare(strict_types=1);

namespace App\Filament\Resources\ResourceDocumentResource\Pages;

use App\Filament\Resources\ResourceDocumentResource;
use App\Filament\Resources\ResourceDocumentResource\Actions\AddResourceDocumentAction;
use App\Models\ResourceDocument;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ListResourceDocuments extends ListRecords
{
    protected static string $resource = ResourceDocumentResource::class;

    /** @return array<int, \Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [AddResourceDocumentAction::make()];
    }

    /**
     * Drag-and-drop order. Filament's own implementation updates every row in one query and so skips model
     * events; this saves each moved row so the history records who moved what, and from where.
     *
     * @param  array<int|string>  $order
     */
    public function reorderTable(array $order): void
    {
        abort_unless(Gate::allows('update', ResourceDocument::class), 403);

        if (! $this->getTable()->isReorderable()) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $order)));

        DB::transaction(function () use ($ids): void {
            $documents = ResourceDocumentResource::getEloquentQuery()->whereKey($ids)->get()->keyBy('id');
            foreach ($ids as $index => $id) {
                $document = $documents->get($id);
                if ($document instanceof ResourceDocument && $document->sort !== $index + 1) {
                    $document->update(['sort' => $index + 1]);
                }
            }
        });
    }
}
