<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnitMasterVehicleResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\VehicleDocument;
use App\Services\Exports\ExportableTable;
use App\Services\Fleet\VehicleDocumentLibrary;
use App\Support\Fleet\VehicleDocumentAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class DocumentsRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    protected static ?string $icon = 'heroicon-o-folder-open';

    protected static bool $isLazy = false;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        if (! $ownerRecord instanceof UnitMasterVehicle) {
            return null;
        }
        $documents = $ownerRecord->documents();
        if (! VehicleDocumentAccess::canSensitive(auth()->user())) {
            $documents->where('is_sensitive', false);
        }

        return (string) $documents->count();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof UnitMasterVehicle && VehicleDocumentAccess::canViewVehicle(auth()->user(), $ownerRecord);
    }

    public function form(Form $form): Form
    {
        return $form->schema(self::metadataFields());
    }

    public function table(Table $table): Table
    {
        return self::withExportActions($table
            ->heading('Documents')
            ->description('Original records, photographs and supporting files for this vehicle.')
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with('uploader');
                if (! VehicleDocumentAccess::canSensitive(auth()->user())) {
                    $query->where('is_sensitive', false);
                }

                return $query;
            })
            ->columns([
                Split::make([
                    Stack::make([
                        Tables\Columns\TextColumn::make('title')->searchable()->sortable()->weight('semibold')->wrap(),
                        Tables\Columns\TextColumn::make('original_filename')->searchable()->color('gray')->wrap(),
                        Tables\Columns\TextColumn::make('report_number')->label('Report / incident #')->searchable()->color('gray'),
                    ]),
                    Stack::make([
                        Tables\Columns\TextColumn::make('category')->badge()
                            ->formatStateUsing(fn (string $state): string => VehicleDocument::CATEGORY_LABELS[$state] ?? Str::headline($state)),
                        Tables\Columns\TextColumn::make('document_date')->date('M j, Y')->placeholder('Date not recorded')->sortable(),
                    ])->grow(false),
                    Tables\Columns\TextColumn::make('review_status')->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'verified' => 'Verified',
                            'needs_review' => 'Needs review',
                            default => 'Unmatched',
                        })
                        ->color(fn (string $state): string => $state === 'verified' ? 'gray' : 'warning')
                        ->grow(false),
                ])->from('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')->options(fn (): array => $this->categoryCounts()),
                Tables\Filters\SelectFilter::make('review_status')->label('Review status')
                    ->options(['verified' => 'Verified', 'needs_review' => 'Needs review', 'unmatched' => 'Unmatched']),
            ])
            ->headerActions([$this->uploadAction()])
            ->actions([
                Tables\Actions\Action::make('preview')->label('Preview')->icon('heroicon-o-eye')->color('gray')
                    ->visible(fn (VehicleDocument $record): bool => in_array($record->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true))
                    ->url(fn (VehicleDocument $record): string => route('vehicle-documents.preview', [$this->getOwnerRecord(), $record]))
                    // The viewer shows it on this page; the new tab is only the no-script fallback.
                    ->extraAttributes(fn (VehicleDocument $record): array => [
                        'data-hub-viewer' => 'auto',
                        'data-hub-viewer-title' => $record->original_filename ?: 'Vehicle document',
                        'data-hub-viewer-download' => route('vehicle-documents.download', [$this->getOwnerRecord(), $record]),
                    ])
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('download')->label('Download')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->url(fn (VehicleDocument $record): string => route('vehicle-documents.download', [$this->getOwnerRecord(), $record])),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('details')->label('Provenance')->icon('heroicon-o-information-circle')
                        ->modalHeading('Document provenance')->modalSubmitAction(false)
                        ->form([
                            Forms\Components\TextInput::make('original_filename')->disabled(),
                            Forms\Components\TextInput::make('source_type')->label('Origin')->disabled(),
                            Forms\Components\TextInput::make('uploaded_by_name')->label('Uploaded by')->disabled(),
                            Forms\Components\TextInput::make('uploaded_at')->disabled(),
                            Forms\Components\Textarea::make('source_references')->label('Original source references')->disabled(),
                            Forms\Components\TextInput::make('sha256')->label('Original SHA-256')->disabled(),
                        ])
                        ->fillForm(fn (VehicleDocument $record): array => [
                            ...$record->only(['original_filename', 'source_type', 'sha256']),
                            'uploaded_by_name' => $record->uploader->name ?? 'Historical import',
                            'uploaded_at' => $record->created_at?->timezone(\App\Support\HubTimezone::NAME)->format('M j, Y g:i A'),
                            'source_references' => $record->sources()->pluck('source_relative_path')->implode("\n"),
                        ]),
                    Tables\Actions\Action::make('editMetadata')->label('Edit details / vehicle links')->icon('heroicon-o-pencil-square')
                        ->visible(fn (VehicleDocument $record): bool => VehicleDocumentAccess::canManageDocument(auth()->user(), $record))
                        ->form([
                            ...self::metadataFields(),
                            Forms\Components\Select::make('vehicle_ids')->label('Linked vehicles')->multiple()->searchable()->required()
                                ->options(fn (): array => UnitMasterVehicle::query()->orderBy('veh_number')->get()->mapWithKeys(fn (UnitMasterVehicle $vehicle): array => [$vehicle->id => $vehicle->displayName()])->all()),
                        ])
                        ->fillForm(fn (VehicleDocument $record): array => [
                            ...$record->only(['title', 'category', 'description', 'document_date', 'report_number', 'is_sensitive', 'legal_hold', 'retention_series']),
                            'vehicle_ids' => $record->vehicles()->pluck('unit_master_vehicles.id')->all(),
                        ])
                        ->action(function (VehicleDocument $record, array $data): void {
                            app(VehicleDocumentLibrary::class)->updateMetadata($record, $this->actor(), $data, $data['vehicle_ids']);
                            Notification::make()->title('Document details saved')->success()->send();
                        }),
                ])->label('More')->button()->color('gray'),
            ])
            ->emptyStateHeading('No documents available')
            ->emptyStateDescription('Add vehicle records here. Sensitive documents appear only for authorized Fleet staff.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->defaultSort('document_date', 'desc')
            ->paginated([10, 25, 50]));
    }

    private function uploadAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('addDocuments')->label('Add Documents')->icon('heroicon-o-plus')
            ->visible(fn (): bool => VehicleDocumentAccess::canManage(auth()->user(), $this->vehicle()) && VehicleDocumentAccess::canSensitive(auth()->user()))
            ->modalHeading('Add documents to '.$this->vehicle()->displayName())
            ->modalSubmitActionLabel('Upload documents')
            ->form([
                Forms\Components\FileUpload::make('files')->label('Select files')->multiple()->required()
                    ->storeFiles(false)->previewable(false)->maxFiles(10)->maxSize(25600)->maxParallelUploads(1)
                    ->helperText('Up to 10 files, 25 MB each. Originals are kept privately. Adjust each title or category below.')
                    ->live()->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get): void {
                        $old = collect($get('metadata') ?? [])->keyBy('upload_key');
                        $rows = [];
                        foreach ((array) $state as $file) {
                            if (! $file instanceof TemporaryUploadedFile) {
                                continue;
                            }
                            $key = $file->getFilename();
                            $rows[] = $old->get($key) ?? [
                                'upload_key' => $key,
                                'title' => Str::headline(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)),
                                'category' => self::proposedCategory($file->getClientOriginalName()),
                                'is_sensitive' => true,
                                'legal_hold' => false,
                            ];
                        }
                        $set('metadata', $rows);
                    }),
                Forms\Components\Repeater::make('metadata')->label('Document details')
                    ->schema([Forms\Components\Hidden::make('upload_key'), ...self::metadataFields()])
                    ->addable(false)->deletable(false)->reorderable(false)->defaultItems(0)
                    ->itemLabel(fn (array $state): string => $state['title'] ?? 'Document')
                    ->collapsible(),
            ])
            ->action(function (array $data): void {
                abort_unless(VehicleDocumentAccess::canManage($this->actor(), $this->vehicle()), 403);
                $metadata = collect($data['metadata'] ?? [])->keyBy('upload_key');
                if (count($data['files']) > 10) {
                    throw ValidationException::withMessages(['files' => 'Choose up to 10 files per upload.']);
                }
                $completed = 0;
                $reused = 0;
                $failures = [];
                $position = 0;
                foreach ($data['files'] as $file) {
                    $position++;
                    if (! $file instanceof TemporaryUploadedFile || ! $metadata->has($file->getFilename())) {
                        throw ValidationException::withMessages(['files' => 'Wait for each file to finish uploading, then try again.']);
                    }
                    try {
                        $document = app(VehicleDocumentLibrary::class)->upload($file, $this->vehicle(), $this->actor(), $metadata->get($file->getFilename()));
                        $reused += (int) (! $document->wasRecentlyCreated);
                        $completed++;
                    } catch (ValidationException $exception) {
                        $failures[] = 'File '.$position.': '.collect($exception->errors())->flatten()->first();
                    } catch (\Throwable $exception) {
                        \Illuminate\Support\Facades\Log::warning('Vehicle document upload failed', [
                            'vehicle_id' => $this->getOwnerRecord()->getKey(), 'error_type' => class_basename($exception),
                        ]);
                        $failures[] = 'File '.$position.' could not be saved. Retry the upload; documents already saved will be kept.';
                    }
                }
                if ($failures !== []) {
                    Notification::make()->title($completed.' document(s) saved; '.count($failures).' need attention')->warning()->send();
                    throw ValidationException::withMessages(['files' => implode(' ', $failures)]);
                }
                Notification::make()->title(($completed - $reused).' document(s) added'.($reused ? '; '.$reused.' existing original(s) reused' : ''))
                    ->body($reused ? 'Identical originals keep their existing title and metadata.' : null)->success()->send();
            });
    }

    /** @return array<Forms\Components\Component> */
    public static function metadataFields(): array
    {
        return [
            Forms\Components\TextInput::make('title')->required()->maxLength(200),
            Forms\Components\Select::make('category')->required()->searchable()
                ->options(fn (): array => VehicleDocument::categoryOptions())
                ->createOptionForm([Forms\Components\TextInput::make('name')->label('Category name')->required()->maxLength(48)])
                ->createOptionUsing(fn (array $data): string => Str::slug(trim($data['name']))),
            Forms\Components\DatePicker::make('document_date')->label('Document date')->native(false),
            Forms\Components\Textarea::make('description')->label('Notes')->rows(2)->maxLength(2000),
            Forms\Components\Toggle::make('is_sensitive')->label('Restricted document')->default(true)
                ->helperText('Only Fleet staff with sensitive-document access can see this record.')
                ->visible(fn (): bool => VehicleDocumentAccess::canSensitive(auth()->user())),
            Forms\Components\Section::make('Records controls')->collapsible()->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('retention_series')->label('Approved retention series')->maxLength(100),
                    Forms\Components\TextInput::make('report_number')->label('Report / incident number')->maxLength(100),
                    Forms\Components\Toggle::make('legal_hold')->label('Legal hold')->default(false)->helperText('Originals are retained; releasing a hold requires the City records process.'),
                ]),
        ];
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return array<string, string> */
    private function categoryCounts(): array
    {
        $query = $this->vehicle()->documents();
        if (! VehicleDocumentAccess::canSensitive(auth()->user())) {
            $query->where('is_sensitive', false);
        }

        return $query->get(['category'])->countBy('category')->mapWithKeys(fn (int $count, string $category): array => [
            $category => (VehicleDocument::CATEGORY_LABELS[$category] ?? Str::headline($category)).' ('.$count.')',
        ])->all();
    }

    private function vehicle(): UnitMasterVehicle
    {
        $vehicle = $this->getOwnerRecord();
        abort_unless($vehicle instanceof UnitMasterVehicle, 404);
        abort_unless(VehicleDocumentAccess::canViewVehicle(auth()->user(), $vehicle), 403);

        return $vehicle;
    }

    private static function proposedCategory(string $name): string
    {
        $name = strtolower($name);

        return match (true) {
            Str::contains($name, ['estimate', 'repair', 'invoice']) => 'repairs',
            Str::contains($name, ['insurance', 'claim']) => 'insurance',
            Str::contains($name, ['photo', 'damage']) => 'damage',
            Str::contains($name, ['accident', 'collision', 'incident']) => 'accidents',
            Str::contains($name, ['inspection']) => 'inspections',
            default => 'other',
        };
    }
}
