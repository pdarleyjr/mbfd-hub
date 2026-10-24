<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Employee;
use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use App\Services\Personnel\Promotions\EmployeeNameMatcher;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components as Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Shared form fields and the error handling for promotional list actions. */
final class PromotionalListForms
{
    /** @return list<Forms\Component> */
    public static function listFields(bool $withStatus): array
    {
        return [
            Forms\Select::make('list_type')->label('List type')->options(PromotionalList::types())->required()->live(),
            Forms\TextInput::make('type_label')->label('Name of this kind of list')->maxLength(80)
                ->visible(fn (Get $get): bool => $get('list_type') === PromotionalList::TYPE_OTHER)
                ->required(fn (Get $get): bool => $get('list_type') === PromotionalList::TYPE_OTHER),
            Forms\TextInput::make('title')->label('Title as printed')->required()->maxLength(200)
                ->placeholder('Lieutenant Promotional Results 2025')->columnSpanFull(),
            Forms\DatePicker::make('effective_date')->label('Effective date')->required()->native(),
            Forms\DatePicker::make('expires_on')->label('Expires on')->native()
                ->helperText('Leave blank when the results state no expiry date.'),
            ...($withStatus ? [Forms\Select::make('status')->label('Status')->options(PromotionalList::statuses())->default(PromotionalList::STATUS_DRAFT)->required()->hiddenOn('edit')
                ->helperText('Keep a list in draft until its effective date. Only active lists show in the personnel directory.')] : []),
            Forms\Textarea::make('source_reference')->label('Source document')->required()->rows(2)->maxLength(2000)->columnSpanFull()
                ->helperText('For example the document title, who issued it, and its date.'),
            Forms\Textarea::make('notes')->label('Notes')->rows(2)->maxLength(5000)->columnSpanFull(),
        ];
    }

    /** @return list<Forms\Component> */
    public static function entryFields(): array
    {
        $scores = [];
        foreach ((array) config('promotional_lists.score_columns') as $key => $label) {
            $scores[] = Forms\TextInput::make($key)->label($label)->maxLength(20);
        }

        return [Forms\Grid::make(['default' => 1, 'md' => 2])->schema([
            Forms\TextInput::make('original_rank')->label('Original rank')->numeric()->integer()->minValue(1)->required()
                ->helperText('The rank as printed on the official list.'),
            Forms\TextInput::make('soi_id')->label('SOI ID')->maxLength(40),
            Forms\TextInput::make('last_name')->label('Last name')->required()->maxLength(120),
            Forms\TextInput::make('first_name')->label('First name')->required()->maxLength(120),
            Forms\Fieldset::make('Scores exactly as printed')->columnSpanFull()->columns(['default' => 2, 'md' => 4])
                ->statePath('scores')->schema($scores),
        ])];
    }

    /** @return list<Forms\Component> */
    public static function linkFields(): array
    {
        return [
            Forms\Placeholder::make('candidates')->label('Exact name matches on the roster')
                ->content(fn (PromotionalListEntry $record): string => self::candidateText($record)),
            Forms\Select::make('employee_id')->label('Personnel record')->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::employeeOptions($search))
                ->getOptionLabelUsing(fn ($value): ?string => self::employeeLabel(Employee::query()->find($value)))
                ->helperText('Search by name or employee ID. Leave empty to unlink. Nothing is linked automatically.'),
        ];
    }

    /** @return list<Forms\Component> */
    public static function statusFields(): array
    {
        return [
            Forms\Select::make('status')->label('New status')->options(PromotionalListEntry::statuses())->required(),
            Forms\Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(1000)
                ->placeholder('Promoted 2026-11-01')->helperText('Kept in the list history.'),
        ];
    }

    /** @return list<Forms\Component> */
    public static function listStatusFields(): array
    {
        return [
            Forms\Select::make('status')->label('New list status')->options(PromotionalList::statuses())->required(),
            Forms\Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(1000)
                ->placeholder('Superseded by the 2027 list')->helperText('Kept in the list history.'),
        ];
    }

    /** Run a change, and turn service errors into a message that keeps the dialog open. */
    public static function run(PageAction|TableAction $action, \Closure $work, string $success): void
    {
        try {
            $work();
        } catch (ValidationException $exception) {
            Notification::make()->title('Not saved')->body(collect($exception->errors())->flatten()->first())->danger()->send();
            $action->halt();

            return;
        } catch (AuthorizationException) {
            Notification::make()->title('Not allowed')->body('You do not have permission to change promotional lists.')->danger()->send();
            $action->halt();

            return;
        }
        Notification::make()->title($success)->success()->send();
    }

    public static function employeeLabel(?Employee $employee): ?string
    {
        return $employee === null ? null : $employee->name.' ('.$employee->employee_id.')'.($employee->roster_status === 'active' ? '' : ' - '.($employee->roster_status ?: 'not active'));
    }

    /** @return array<int, string> */
    private static function employeeOptions(string $search): array
    {
        return Employee::query()->where(fn ($query) => $query->whereLike('name', '%'.$search.'%')->orWhereLike('employee_id', '%'.$search.'%'))
            ->orderBy('name')->limit(30)->get()
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => self::employeeLabel($employee)])->all();
    }

    private static function candidateText(PromotionalListEntry $entry): string
    {
        $matches = app(EmployeeNameMatcher::class)->candidates($entry->first_name, $entry->last_name);
        if ($matches->isEmpty()) {
            return 'None. Search the roster below, or leave the entry unlinked.';
        }

        return $matches->map(fn (Employee $employee): string => (string) self::employeeLabel($employee))->implode('; ');
    }
}
