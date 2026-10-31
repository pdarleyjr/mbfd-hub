<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\RosterImportResource\Pages;
use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterImportActions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exception-only view of TeleStaff roster imports. Imports run on their own; an administrator only
 * comes here when a roster is late or held. The ledger holds provenance and counts, never member data.
 */
final class RosterImportResource extends Resource
{
    use HasExportActions;

    protected static ?int $navigationSort = 45;

    protected static ?string $model = RosterImport::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Roster Imports';

    protected static ?string $modelLabel = 'roster import';

    public const ATTEST_DESCRIPTION = 'The mail check could not vouch for this email, or its report layout is new. Only continue if you know it really came from TeleStaff. It goes back through every roster check, and accepting a new layout makes it the one later exports are compared with.';

    public const RETRY_CLEANUP_DESCRIPTION = 'Tries again to remove this email and its attachment. Everything needed to explain the import stays on this page.';

    public static function table(Table $table): Table
    {
        return self::withExportActions($table
            ->columns([
                Tables\Columns\TextColumn::make('received_at')->label('Received')->dateTime('M j, Y g:i A', 'America/New_York')->sortable(),
                Tables\Columns\TextColumn::make('duty_date')->label('Duty date')->date('M j, Y')->placeholder('Not read')->sortable(),
                Tables\Columns\TextColumn::make('shift_letter')->label('Shift')->placeholder('Not read'),
                Tables\Columns\TextColumn::make('state')->label('Status')->badge()
                    ->formatStateUsing(static fn (string $state): string => self::stateLabel($state))
                    ->color(static fn (string $state): string => self::stateColor($state)),
                Tables\Columns\TextColumn::make('section_count')->label('Sections')->placeholder('Not read'),
                Tables\Columns\TextColumn::make('assignment_count')->label('Entries')->placeholder('Not read'),
                Tables\Columns\TextColumn::make('error_code')->label('Reason')
                    ->formatStateUsing(static fn (?string $state): string => self::reasonLabel($state))
                    ->placeholder('None')->wrap(),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('state')->label('Status')->options(self::stateOptions()),
                // Exports that confirmed what was already showing outnumber everything else at a few
                // minutes between them, and they need nobody. Off by a click when one has to be found.
                Tables\Filters\Filter::make('took_effect')
                    ->label('Hide exports that changed nothing')
                    ->default()
                    ->query(static fn (Builder $query): Builder => $query->where(static fn (Builder $outcome): Builder => $outcome
                        ->whereNull('outcome')
                        ->orWhereNotIn('outcome', [RosterImport::OUTCOME_CONFIRMED, RosterImport::OUTCOME_DUPLICATE, RosterImport::OUTCOME_SUPERSEDED]))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::retryTableAction(),
                self::attestTableAction(),
                self::retryCleanupTableAction(),
                self::activateTableAction(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No roster imports yet')
            ->emptyStateDescription('The first TeleStaff email creates a row here on its own.'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Status')->columns(3)->schema([
                Infolists\Components\TextEntry::make('state')->label('Status')->badge()
                    ->formatStateUsing(static fn (string $state): string => self::stateLabel($state))
                    ->color(static fn (string $state): string => self::stateColor($state)),
                Infolists\Components\TextEntry::make('outcome')->placeholder('Not finished'),
                Infolists\Components\TextEntry::make('error_code')->label('Reason')
                    ->formatStateUsing(static fn (?string $state): string => self::reasonLabel($state))
                    ->placeholder('None'),
                Infolists\Components\TextEntry::make('attempts'),
                Infolists\Components\TextEntry::make('cleanup_state')->label('Email cleanup'),
                Infolists\Components\TextEntry::make('correlation_id')->label('Reference')->copyable(),
            ]),
            Infolists\Components\Section::make('Report')->columns(3)->schema([
                Infolists\Components\TextEntry::make('duty_date')->date('l, F j, Y')->placeholder('Not read'),
                Infolists\Components\TextEntry::make('shift_letter')->label('Shift')->placeholder('Not read'),
                Infolists\Components\TextEntry::make('source_generated_at')->label('Report generated')->dateTime('M j, Y g:i:s A', 'America/New_York')->placeholder('Not read'),
                Infolists\Components\TextEntry::make('section_count')->label('Sections')->placeholder('Not read'),
                Infolists\Components\TextEntry::make('assignment_count')->label('Entries')->placeholder('Not read'),
                Infolists\Components\TextEntry::make('unique_employee_count')->label('Members')->placeholder('Not read'),
                Infolists\Components\TextEntry::make('parser_version')->placeholder('Not read'),
                Infolists\Components\TextEntry::make('published_at')->dateTime('M j, Y g:i:s A', 'America/New_York')->placeholder('Not published'),
                Infolists\Components\TextEntry::make('verified_at')->dateTime('M j, Y g:i:s A', 'America/New_York')->placeholder('Not verified'),
            ]),
            Infolists\Components\Section::make('Validation findings')->schema([
                Infolists\Components\TextEntry::make('warnings')->label('Warnings')->listWithLineBreaks()->placeholder('None'),
                Infolists\Components\TextEntry::make('classification.reasons')->label('Mail check')->listWithLineBreaks()->placeholder('Passed'),
                Infolists\Components\TextEntry::make('classification.attestation_reason')->label('Confirmed genuine because')->placeholder('Not confirmed by an administrator'),
            ]),
            Infolists\Components\Section::make('Mail check evidence')->columns(1)->collapsed()->schema([
                Infolists\Components\TextEntry::make('classification.evidence.envelope_from')->label('Envelope sender')->placeholder('Not recorded'),
                Infolists\Components\TextEntry::make('classification.evidence.authentication_results')->label('Authentication-Results')->placeholder('Not recorded'),
                Infolists\Components\TextEntry::make('classification.evidence.arc_authentication_results')->label('ARC-Authentication-Results')->placeholder('Not recorded'),
                Infolists\Components\TextEntry::make('classification.evidence.received_spf')->label('Received-SPF')->placeholder('Not recorded'),
            ]),
            Infolists\Components\Section::make('Source email')->columns(2)->collapsed()->schema([
                Infolists\Components\TextEntry::make('sender'),
                Infolists\Components\TextEntry::make('subject')->placeholder('None'),
                Infolists\Components\TextEntry::make('filename')->placeholder('None'),
                Infolists\Components\TextEntry::make('attachment_bytes')->label('Size (bytes)')->placeholder('None'),
                Infolists\Components\TextEntry::make('received_at')->dateTime('M j, Y g:i:s A', 'America/New_York'),
                Infolists\Components\TextEntry::make('cleaned_at')->label('Email removed')->dateTime('M j, Y g:i:s A', 'America/New_York')->placeholder('Still in the inbox'),
                Infolists\Components\TextEntry::make('attachment_sha256')->label('Attachment SHA-256')->placeholder('None')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('History')->collapsed()->schema([
                Infolists\Components\RepeatableEntry::make('events')->label('')->schema([
                    Infolists\Components\TextEntry::make('created_at')->label('When')->dateTime('M j g:i:s A', 'America/New_York'),
                    Infolists\Components\TextEntry::make('action'),
                    Infolists\Components\TextEntry::make('to_state')->label('Status')->placeholder('Unchanged'),
                    Infolists\Components\TextEntry::make('reason')->placeholder('None'),
                ])->columns(4),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRosterImports::route('/'),
            'view' => Pages\ViewRosterImport::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.system.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canManage(): bool
    {
        return auth()->user()?->can('admin.system.manage') ?? false;
    }

    public static function stateLabel(string $state): string
    {
        return self::stateOptions()[$state] ?? $state;
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        return [
            RosterImport::STATE_CLASSIFIED => 'Received',
            RosterImport::STATE_QUEUED => 'Waiting to process',
            RosterImport::STATE_PARSING => 'Reading workbook',
            RosterImport::STATE_VALIDATED => 'Validated',
            RosterImport::STATE_SHADOWED => 'Checked, not published',
            RosterImport::STATE_PUBLISHED => 'Published',
            RosterImport::STATE_VERIFIED => 'Published and verified',
            RosterImport::STATE_CLEANED => 'Complete, email removed',
            RosterImport::STATE_DUPLICATE => 'Same as a published roster',
            RosterImport::STATE_SUPERSEDED => 'Older than the current roster',
            RosterImport::STATE_REJECTED => 'Rejected by the mail check',
            RosterImport::STATE_NEEDS_REVIEW => 'Needs review',
            RosterImport::STATE_RETRYABLE_FAILED => 'Will retry',
            RosterImport::STATE_PERMANENT_FAILED => 'Failed',
        ];
    }

    public static function stateColor(string $state): string
    {
        return match ($state) {
            RosterImport::STATE_VERIFIED, RosterImport::STATE_CLEANED, RosterImport::STATE_PUBLISHED => 'success',
            RosterImport::STATE_NEEDS_REVIEW, RosterImport::STATE_RETRYABLE_FAILED, RosterImport::STATE_SHADOWED => 'warning',
            RosterImport::STATE_REJECTED, RosterImport::STATE_PERMANENT_FAILED => 'danger',
            default => 'gray',
        };
    }

    public static function reasonLabel(?string $code): string
    {
        return match ($code) {
            null, '' => 'None',
            'pinned' => 'An administrator pinned this duty date',
            'layout_changed' => 'TeleStaff changed the report layout',
            'shift_calendar_mismatch' => 'The shift does not match the duty calendar',
            'generated_after_received' => 'The report time is later than the email',
            'section_missing_command_300', 'section_missing_captain_5' => 'A command section is missing from the report',
            'source_file_missing' => 'The stored attachment is missing',
            'source_hash_mismatch' => 'The stored attachment changed',
            'lease_expired' => 'Processing stopped unexpectedly',
            default => str_replace('_', ' ', ucfirst($code)),
        };
    }

    public static function retryTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('retry')
            ->label('Retry')
            ->icon('heroicon-o-arrow-path')
            ->visible(static fn (RosterImport $record): bool => self::canManage() && app(RosterImportActions::class)->canRetry($record))
            ->action(static fn (RosterImport $record) => app(RosterImportActions::class)->retry($record, auth()->user()));
    }

    public static function attestTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('retryAttested')
            ->label('Confirm genuine and retry')
            ->icon('heroicon-o-shield-check')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Confirm this email is the genuine TeleStaff export?')
            ->modalDescription(self::ATTEST_DESCRIPTION)
            ->form([Forms\Components\Textarea::make('reason')->label('How you confirmed it')->required()->maxLength(500)])
            ->visible(static fn (RosterImport $record): bool => self::canManage() && app(RosterImportActions::class)->canRetryAttested($record))
            ->action(static fn (RosterImport $record, array $data) => app(RosterImportActions::class)->retryAttested($record, auth()->user(), (string) $data['reason']));
    }

    public static function retryCleanupTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('retryCleanup')
            ->label('Retry email cleanup')
            ->icon('heroicon-o-trash')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(self::RETRY_CLEANUP_DESCRIPTION)
            ->visible(static fn (RosterImport $record): bool => self::canManage() && app(RosterImportActions::class)->canRetryCleanup($record))
            ->action(static fn (RosterImport $record) => app(RosterImportActions::class)->retryCleanup($record, auth()->user()));
    }

    public static function activateTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('activate')
            ->label('Show this version')
            ->icon('heroicon-o-backward')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Show this roster version to members?')
            ->modalDescription('The duty date stays on this version and newer TeleStaff exports for it are held until you release it. Nothing is deleted.')
            ->form([Forms\Components\Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
            ->visible(static fn (RosterImport $record): bool => self::canManage() && app(RosterImportActions::class)->canActivate($record))
            ->action(static fn (RosterImport $record, array $data) => app(RosterImportActions::class)->activate($record, auth()->user(), (string) $data['reason']));
    }
}
