<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Filament\Resources\EditionResource;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\ImportSubmissionService;

final class ListEditions extends ListRecords
{
    protected static string $resource = EditionResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Create draft edition'),
            Actions\Action::make('import_package')->label('Import manual / section')->form([
                Forms\Components\FileUpload::make('package')->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])->maxSize(config('policy-library.max_upload_kb'))->storeFiles(false)->required(),
                Forms\Components\Select::make('section_id')->label('Replace current section (optional)')->options(fn () => ManualNode::query()->where('type', 'section')->whereHas('manual', fn ($query) => $query->whereColumn('policy_manuals.active_edition_id', 'policy_nodes.edition_id'))->with('manual')->get()->mapWithKeys(fn ($node) => [$node->id => $node->manual->name.' — '.$node->title]))->searchable()->helperText('Leave empty to stage a complete manual replacement.'),
            ])->action(function (array $data): void {
                abort_unless(EditionResource::canViewAny(), 403);
                $file = $data['package'] ?? null;
                if (! $file instanceof UploadedFile) {
                    throw ValidationException::withMessages(['package' => 'Upload an import ZIP.']);
                }
                app(ImportSubmissionService::class)->submit($file, 'package', auth('web')->id(), empty($data['section_id']) ? null : (int) $data['section_id']);
                Notification::make()->title('Import queued')->body('Follow Import Progress. The current manual remains available.')->success()->send();
            }),
            Actions\Action::make('upload_moms')->label('Upload complete MOMS')->form([
                Forms\Components\FileUpload::make('pdf')->acceptedFileTypes(['application/pdf'])->maxSize(config('policy-library.max_upload_kb'))->storeFiles(false)->required(),
            ])->action(function (array $data): void {
                abort_unless(EditionResource::canViewAny(), 403);
                $file = $data['pdf'] ?? null;
                if (! $file instanceof UploadedFile) {
                    throw ValidationException::withMessages(['pdf' => 'Upload a PDF document.']);
                }
                app(ImportSubmissionService::class)->submit($file, 'moms', auth('web')->id());
                Notification::make()->title('Manual analysis queued')->body('Follow Import Progress. Review the draft before publishing.')->success()->send();
            }),
        ];
    }
}
