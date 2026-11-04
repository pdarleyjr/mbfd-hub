<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\HasPageTableExportActions;
use App\Models\AiAutomation\AiAutomationPlaybook;
use App\Models\AiAutomation\AiIntakeEvent;
use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Services\AiAutomation\Admin\AdminLabels;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\Exports\ExportableTable;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Activity & History: every automated reply, what came in, what was sent, how it was written and whether a person
 * still needs to act. The original submission always stays with staff.
 */
class AiActivityHistory extends Page implements ExportableTable, HasTable
{
    use \App\Filament\Concerns\RegistersSupportAutomationNavigation;
    use HasPageTableExportActions;
    use InteractsWithTable;

    protected static ?string $navigationGroup = AdminLabels::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'Activity & History';

    protected static ?string $title = 'Activity & History';

    protected static ?string $slug = 'ai-activity';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.admin.pages.ai-activity-history';

    protected ?string $maxContentWidth = MaxWidth::SevenExtraLarge->value;

    /** @var array<int, string>|null */
    private ?array $articleTitles = null;

    /** @var array<string, string>|null */
    private ?array $messageLabels = null;

    public static function canAccess(): bool
    {
        return AiAutomationAccess::allowsCurrentUser();
    }

    public function table(Table $table): Table
    {
        return self::withExportActions($table
            ->query(AiIntakeEvent::query())
            ->defaultSort('id', 'desc')
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('domain')->label('Area')->formatStateUsing(fn (string $state): string => AdminLabels::area($state)),
                TextColumn::make('source_type')->label('What came in')
                    ->formatStateUsing(fn (AiIntakeEvent $record): string => match ((string) $record->source_type) {
                        'apparatus_inspection' => 'Vehicle checkout',
                        'apparatus_issue_message' => 'Vehicle message',
                        'station_request' => 'Station request',
                        'station_supply_request' => 'Supply request',
                        'employee_logistics_message' => 'Message to Support Services',
                        default => AdminLabels::headline($record->source_type),
                    }.' #'.$record->source_id),
                TextColumn::make('priority')->label('Priority')->badge()
                    ->state(fn (AiIntakeEvent $record): ?string => self::priorityOf($record))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : AdminLabels::level($state))
                    ->color(fn (?string $state): string => AdminLabels::levelColor($state))
                    ->placeholder('-'),
                TextColumn::make('state')->label('Result')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent' => 'Reply sent',
                        'skipped' => 'No reply needed',
                        'cancelled' => 'Stopped by OFF',
                        'failed' => 'Could not send',
                        'queued', 'claimed' => 'Waiting',
                        'retry' => 'Trying again',
                        default => AdminLabels::headline($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'cancelled', 'retry' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('reply_text')->label('Reply sent')->wrap()->placeholder('Nothing sent')->limit(140),
                TextColumn::make('written_by')->label('How it was written')->wrap()
                    ->state(fn (AiIntakeEvent $record): string => $this->writtenBy($record))
                    ->toggleable(),
                TextColumn::make('guidance')->label('Guidance used')->wrap()
                    ->state(fn (AiIntakeEvent $record): string => $this->guidanceUsed($record))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('staff_action')->label('Staff action')
                    ->state(fn (AiIntakeEvent $record): string => $this->staffAction($record))
                    ->badge()
                    ->color(fn (string $state): string => str_starts_with($state, 'Follow up') ? 'warning' : 'gray')
                    ->toggleable(),
                TextColumn::make('why')->label('Why')
                    ->state(fn (AiIntakeEvent $record): string => AdminLabels::reasons(
                        collect($record->reason_codes ?? [])->reject(fn ($code): bool => str_starts_with((string) $code, 'level_') || str_starts_with((string) $code, 'handler_') || str_starts_with((string) $code, 'category_') || str_starts_with((string) $code, 'rule_'))->values()->all(),
                        $record->last_error,
                    ))
                    ->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('origin')->label('Source')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'backlog' ? 'Older submission' : 'New submission')
                    ->color(fn (string $state): string => $state === 'backlog' ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')->label('Result')->options([
                    'sent' => 'Reply sent', 'skipped' => 'No reply needed', 'cancelled' => 'Stopped by OFF',
                    'failed' => 'Could not send', 'queued' => 'Waiting', 'retry' => 'Trying again',
                ]),
                SelectFilter::make('domain')->label('Area')->options(AdminLabels::areas()),
                SelectFilter::make('origin')->label('Source')->options(['live' => 'New submission', 'backlog' => 'Older submission']),
                SelectFilter::make('priority')->label('Priority')->options(AdminLabels::levels())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('reason_codes', 'like', '%"level_'.$data['value'].'"%')
                        : $query),
                TernaryFilter::make('needs_staff')->label('Staff still need to act')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('state', AiIntakeEvent::STATE_SENT)->where('needs_human', true)->whereIn('risk_tier', ['critical', 'elevated']),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $inner) => $inner->where('needs_human', false)->orWhereNotIn('risk_tier', ['critical', 'elevated'])->orWhereNull('risk_tier')),
                    ),
            ]));
    }

    private static function priorityOf(AiIntakeEvent $record): ?string
    {
        foreach ((array) $record->reason_codes as $code) {
            if (preg_match('/^level_(low|medium|high)$/', $code, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function writtenBy(AiIntakeEvent $record): string
    {
        if ($record->state !== AiIntakeEvent::STATE_SENT) {
            return '-';
        }
        if ($record->model_call_id !== null) {
            return 'AI assistant, using Knowledge & Instructions';
        }
        if ($record->template_key === null) {
            return $record->knowledge_refs ? 'Knowledge & Instructions' : 'Reply written by the equipment rule';
        }
        $this->messageLabels ??= AiAutomationPlaybook::query()->pluck('label', 'key')->all();

        return 'Standard message: '.Str::of($this->messageLabels[$record->template_key] ?? AdminLabels::headline($record->template_key));
    }

    private function guidanceUsed(AiIntakeEvent $record): string
    {
        $refs = collect((array) $record->knowledge_refs)->pluck('article_id')->filter()->unique()->values();
        if ($refs->isEmpty()) {
            return '';
        }
        $this->articleTitles ??= AiKnowledgeArticle::query()->pluck('title', 'id')->all();

        return $refs->map(fn ($id): string => $this->articleTitles[(int) $id] ?? 'An article')->implode(', ');
    }

    private function staffAction(AiIntakeEvent $record): string
    {
        return match (true) {
            $record->state === AiIntakeEvent::STATE_FAILED => 'Follow up: no reply went out',
            $record->state === AiIntakeEvent::STATE_SENT && $record->needs_human && in_array($record->risk_tier, ['critical', 'elevated'], true) => 'Follow up: staff must handle this',
            $record->state === AiIntakeEvent::STATE_SENT => 'None, the reply covers it',
            default => 'None',
        };
    }
}
