<?php

declare(strict_types=1);

namespace App\Filament\Resources\ResourceDocumentResource\Pages;

use App\Filament\Resources\ResourceDocumentResource;
use App\Models\ResourceDocument;
use App\Services\ResourceLibrary\ResourceFileInput;
use App\Services\ResourceLibrary\ResourceImportException;
use App\Services\ResourceLibrary\ResourceImportResult;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class EditResourceDocument extends EditRecord
{
    protected static string $resource = ResourceDocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ResourceDocument, 404);

        $original = $data['replacement_original'] ?? null;
        $preview = $data['replacement_preview'] ?? null;
        unset($data['replacement_original'], $data['replacement_preview']);

        if ($original instanceof TemporaryUploadedFile || $preview instanceof TemporaryUploadedFile) {
            $this->replaceFiles($record, $data, $original, $preview);
        }

        $record->update($data);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replaceFiles(ResourceDocument $record, array $data, mixed $original, mixed $preview): void
    {
        if (! $original instanceof TemporaryUploadedFile) {
            Notification::make()->danger()->title('Choose the new original file')->body('A preview PDF can only be added together with the original it was exported from.')->send();

            throw new Halt;
        }

        try {
            $result = app(ResourceLibraryService::class)->store(
                [
                    'slug' => $record->slug,
                    'title' => (string) ($data['title'] ?? $record->title),
                    'category' => (string) ($data['category'] ?? $record->category),
                ],
                new ResourceFileInput((string) $original->getRealPath(), $original->getClientOriginalName()),
                $preview instanceof TemporaryUploadedFile
                    ? new ResourceFileInput((string) $preview->getRealPath(), $preview->getClientOriginalName())
                    : null,
                auth()->id(),
                'admin',
            );
        } catch (ResourceImportException $exception) {
            Notification::make()->danger()->title('The file was not replaced')->body($exception->getMessage())->persistent()->send();

            throw new Halt;
        }

        $notification = Notification::make()
            ->title($result->outcome === ResourceImportResult::UNCHANGED ? 'That file is already in the library' : 'File replaced')
            ->body($result->warnings === [] ? null : implode(' ', $result->warnings));
        ($result->warnings === [] ? $notification->success() : $notification->warning())->send();
    }
}
