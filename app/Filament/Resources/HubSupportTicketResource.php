<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\HubSupportTicketCategory;
use App\Enums\HubSupportTicketImpact;
use App\Enums\HubSupportTicketStatus;
use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\HubSupportTicketResource\Pages;
use App\Models\HubSupportTicket;
use App\Models\HubSupportTicketUpdate;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

final class HubSupportTicketResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = HubSupportTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Website / App Issue Reports';

    protected static ?string $modelLabel = 'Issue Report';

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('ticket_number')->label('Ticket')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('generated_title')->label('Issue')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $nested) use ($search): void {
                        $nested->where('generated_title', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('reporter_name_snapshot', 'like', "%{$search}%");
                    }))->limit(60),
                Tables\Columns\TextColumn::make('reporter_name_snapshot')->label('Reporter')->searchable(),
                Tables\Columns\TextColumn::make('affected_component')->label('Component')->badge(),
                Tables\Columns\TextColumn::make('impact')->badge()->formatStateUsing(fn (HubSupportTicketImpact|string $state): string => ($state instanceof HubSupportTicketImpact ? $state : HubSupportTicketImpact::from($state))->label()),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (HubSupportTicketStatus|string $state): string => ($state instanceof HubSupportTicketStatus ? $state : HubSupportTicketStatus::from($state))->memberLabel())->sortable(),
                Tables\Columns\TextColumn::make('assignedTo.name')->label('Assigned To')->placeholder('Unassigned'),
                Tables\Columns\TextColumn::make('created_at')->label('Submitted')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(HubSupportTicketStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->memberLabel()])->all()),
                Tables\Filters\SelectFilter::make('category')->options(collect(HubSupportTicketCategory::cases())->mapWithKeys(fn ($category) => [$category->value => $category->label()])->all()),
                Tables\Filters\SelectFilter::make('impact')->options(collect(HubSupportTicketImpact::cases())->mapWithKeys(fn ($impact) => [$impact->value => $impact->label()])->all()),
                Tables\Filters\SelectFilter::make('assigned_to_user_id')->relationship('assignedTo', 'name')->label('Assignee'),
                Tables\Filters\TernaryFilter::make('open')->queries(
                    true: fn (Builder $query): Builder => $query->whereNotIn('status', [HubSupportTicketStatus::Resolved->value, HubSupportTicketStatus::Closed->value]),
                    false: fn (Builder $query): Builder => $query->whereIn('status', ['resolved', 'closed']),
                ),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Issue')->schema([
                Infolists\Components\TextEntry::make('ticket_number')->label('Reference'),
                Infolists\Components\TextEntry::make('generated_title')->label('Summary'),
                Infolists\Components\TextEntry::make('description')->columnSpanFull(),
                Infolists\Components\TextEntry::make('status')->badge()->formatStateUsing(fn (HubSupportTicketStatus|string $state): string => ($state instanceof HubSupportTicketStatus ? $state : HubSupportTicketStatus::from($state))->memberLabel()),
                Infolists\Components\TextEntry::make('category')->formatStateUsing(fn (HubSupportTicketCategory|string $state): string => ($state instanceof HubSupportTicketCategory ? $state : HubSupportTicketCategory::from($state))->label()),
                Infolists\Components\TextEntry::make('impact')->formatStateUsing(fn (HubSupportTicketImpact|string $state): string => ($state instanceof HubSupportTicketImpact ? $state : HubSupportTicketImpact::from($state))->label()),
                Infolists\Components\TextEntry::make('affected_component')->label('Component'),
            ])->columns(3),
            Infolists\Components\Section::make('Reporter')->schema([
                Infolists\Components\TextEntry::make('reporter_name_snapshot')->label('Name'),
                Infolists\Components\TextEntry::make('reporter_employee_identifier_snapshot')->label('Employee ID')->placeholder('—'),
                Infolists\Components\TextEntry::make('created_at')->label('Submitted')->dateTime(),
            ])->columns(3),
            Infolists\Components\Section::make('Where It Happened')->schema([
                Infolists\Components\TextEntry::make('page_path')->label('Page')->placeholder('Unknown'),
                Infolists\Components\TextEntry::make('route_name')->label('Route')->placeholder('Unknown'),
                Infolists\Components\TextEntry::make('referrer_path')->label('Previous page')->placeholder('Unknown'),
                Infolists\Components\TextEntry::make('application_commit')->label('Build')->placeholder('Unknown'),
            ])->columns(2),
            Infolists\Components\Section::make('What MBFD Hub Detected')->schema([
                Infolists\Components\TextEntry::make('diagnostic_summary')->label('Recent failures')
                    ->state(fn (HubSupportTicket $record): string => self::diagnosticSummary($record)),
                Infolists\Components\TextEntry::make('issue_fingerprint')->label('Related issue fingerprint')->placeholder('—'),
            ])->columns(2),
            Infolists\Components\Section::make('Technical details')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('technical_details')->hiddenLabel()
                        ->state(fn (HubSupportTicket $record): array => self::technicalDetails($record))
                        ->schema([
                            Infolists\Components\TextEntry::make('type')->badge(),
                            Infolists\Components\TextEntry::make('timestamp')->label('When')->placeholder('—'),
                            Infolists\Components\TextEntry::make('name')->label('Error')->placeholder('—'),
                            Infolists\Components\TextEntry::make('message')->label('Message')->placeholder('—')->columnSpanFull(),
                            Infolists\Components\TextEntry::make('source')->label('Source')->placeholder('—'),
                            Infolists\Components\TextEntry::make('location')->label('Line / column')->placeholder('—'),
                            Infolists\Components\TextEntry::make('stack')->label('Sanitized stack')->placeholder('—')->columnSpanFull(),
                            Infolists\Components\TextEntry::make('method')->label('Method')->placeholder('—'),
                            Infolists\Components\TextEntry::make('path')->label('Path')->placeholder('—'),
                            Infolists\Components\TextEntry::make('status')->label('Status')->placeholder('—'),
                            Infolists\Components\TextEntry::make('duration')->label('Duration')->placeholder('—'),
                        ])->columns(4),
                ])
                ->visible(fn (HubSupportTicket $record): bool => self::technicalDetails($record) !== [])
                ->collapsible()
                ->collapsed(),
            Infolists\Components\Section::make('Environment')->schema([
                Infolists\Components\TextEntry::make('browser_summary')->label('Browser / display')
                    ->state(fn (HubSupportTicket $record): string => self::environmentSummary($record)),
            ]),
            Infolists\Components\Section::make('Attachments')->schema([
                Infolists\Components\TextEntry::make('attachment_links')->hiddenLabel()
                    ->state(fn (HubSupportTicket $record): HtmlString => new HtmlString(
                        $record->attachments->isEmpty() ? 'No files attached.' : $record->attachments
                            ->map(fn ($file): string => '<a href="'.e(route('admin.hub-support-attachments.download', $file)).'" class="text-primary-600 underline">'.e($file->original_filename).'</a>')
                            ->implode('<br>'),
                    )),
            ]),
            Infolists\Components\Section::make('Assignment & Resolution')->schema([
                Infolists\Components\TextEntry::make('assignedTo.name')->label('Assigned To')->placeholder('Unassigned'),
                Infolists\Components\TextEntry::make('resolution_summary')->label('Resolution')->placeholder('Open')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Activity')->schema([
                Infolists\Components\RepeatableEntry::make('updates')->hiddenLabel()->schema([
                    Infolists\Components\TextEntry::make('created_at')->label('When')->dateTime(),
                    Infolists\Components\TextEntry::make('status')->badge(),
                    Infolists\Components\TextEntry::make('changedBy.name')->label('By')->placeholder('System'),
                    Infolists\Components\TextEntry::make('public_response')->label('Member-visible reply')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('internal_note')->label('Internal note')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('metadata.resolution_summary')
                        ->label('Resolution recorded')
                        ->visible(fn (HubSupportTicketUpdate $record): bool => filled($record->metadata['resolution_summary'] ?? null))
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('metadata.previous_resolution_summary')
                        ->label('Previous resolution')
                        ->visible(fn (HubSupportTicketUpdate $record): bool => filled($record->metadata['previous_resolution_summary'] ?? null))
                        ->columnSpanFull(),
                ])->columns(3),
            ]),
        ]);
    }

    private static function diagnosticSummary(HubSupportTicket $record): string
    {
        $diagnostics = $record->diagnostics ?? [];
        $events = is_array($diagnostics['events'] ?? null) ? $diagnostics['events'] : [];
        $errors = 0;
        $requests = 0;
        $examples = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            if (in_array($event['type'] ?? null, ['error', 'rejection'], true)) {
                $errors++;
            }
            if (($event['type'] ?? null) !== 'request') {
                continue;
            }
            $requests++;
            if (count($examples) < 3) {
                $parts = [];
                foreach (['method', 'path'] as $key) {
                    if (is_string($event[$key] ?? null)) {
                        $parts[] = $event[$key];
                    }
                }
                if (is_int($event['status'] ?? null)) {
                    $parts[] = 'HTTP '.$event['status'];
                }
                $examples[] = implode(' ', $parts);
            }
        }

        return "{$errors} application errors; {$requests} failed requests".($examples !== [] ? ' — '.implode('; ', $examples) : '');
    }

    private static function environmentSummary(HubSupportTicket $record): string
    {
        $metadata = $record->client_metadata ?? [];
        $viewport = is_array($metadata['viewport'] ?? null) ? $metadata['viewport'] : [];
        $parts = [];
        foreach (['userAgent', 'timezone'] as $key) {
            if (is_string($metadata[$key] ?? null)) {
                $parts[] = $metadata[$key];
            }
        }
        if (is_int($viewport['width'] ?? null) && is_int($viewport['height'] ?? null)) {
            $parts[] = $viewport['width'].'×'.$viewport['height'];
        }
        if (($metadata['standalone'] ?? false) === true) {
            $parts[] = 'Installed app';
        }

        return $parts !== [] ? implode(' · ', $parts) : 'No environment details available';
    }

    /** @return list<array<string, string>> */
    private static function technicalDetails(HubSupportTicket $record): array
    {
        $events = data_get($record->diagnostics, 'events', []);
        if (! is_array($events)) {
            return [];
        }

        return collect($events)->filter(fn (mixed $event): bool => is_array($event))->map(function (array $event): array {
            $detail = [];
            foreach (['type', 'timestamp', 'name', 'message', 'source', 'stack', 'method', 'path'] as $field) {
                if (is_string($event[$field] ?? null) && $event[$field] !== '') {
                    $detail[$field] = $event[$field];
                }
            }
            if (isset($event['line']) || isset($event['column'])) {
                $detail['location'] = trim(($event['line'] ?? '—').' / '.($event['column'] ?? '—'));
            }
            if (is_int($event['status'] ?? null)) {
                $detail['status'] = 'HTTP '.$event['status'];
            }
            if (is_int($event['duration'] ?? null)) {
                $detail['duration'] = $event['duration'].' ms';
            }

            return $detail;
        })->filter(fn (array $event): bool => $event !== [])->values()->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['reporter', 'assignedTo', 'updates.changedBy', 'attachments']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', HubSupportTicket::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHubSupportTickets::route('/'),
            'view' => Pages\ViewHubSupportTicket::route('/{record}'),
        ];
    }
}
