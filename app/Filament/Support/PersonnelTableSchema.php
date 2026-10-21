<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Employee;
use App\Services\Personnel\PersonnelSource;
use Filament\Forms\Components as Forms;
use Filament\Forms\Get;
use Illuminate\Support\Str;

final class PersonnelTableSchema
{
    public static function columnTypes(): array
    {
        return ['checkbox' => 'Checkbox', 'text' => 'Short text', 'notes' => 'Notes', 'number' => 'Number',
            'date' => 'Date', 'select' => 'Single select', 'multiselect' => 'Multiple select', 'status' => 'Status'];
    }

    public static function colors(): array
    {
        return ['' => 'None', 'gray' => 'Gray', 'info' => 'Blue', 'success' => 'Green', 'warning' => 'Amber', 'danger' => 'Red'];
    }

    public static function sourceColumnsForm(): Forms\Repeater
    {
        return Forms\Repeater::make('source_columns')->label('Personnel columns')
            ->simple(Forms\Select::make('field')->label('Personnel field')
                ->options(fn (): array => app(PersonnelSource::class)->fields())->required()->distinct()
                ->disableOptionsWhenSelectedInSiblingRepeaterItems())
            ->default(['name', 'employee_id', 'rank'])->minItems(1)->maxItems(30)
            ->reorderableWithButtons()->addActionLabel('Add personnel column')
            ->helperText('Use the arrows to change column order. These values stay linked to the personnel record.');
    }

    public static function columnsForm(): Forms\Repeater
    {
        return Forms\Repeater::make('columns')->label('Tracking columns')->schema([
            Forms\Hidden::make('id')->default(fn (): string => (string) Str::uuid()),
            Forms\TextInput::make('label')->label('Column name')->required()->maxLength(80),
            Forms\Select::make('type')->options(self::columnTypes())->default('checkbox')->required()->live(),
            Forms\TagsInput::make('options')->label('Choices')->separator(',')->rules(['array', 'max:30'])
                ->visible(fn (Get $get): bool => in_array($get('type'), ['select', 'multiselect', 'status'], true))
                ->required(fn (Get $get): bool => in_array($get('type'), ['select', 'multiselect', 'status'], true)),
            Forms\Select::make('color')->label('Column highlight')->options(self::colors())->default('')->live(),
            Forms\Select::make('highlight_when')->label('Apply highlight')->options(fn (Get $get): array => [
                'always' => 'Entire column', 'filled' => 'When a value is entered',
                ...($get('type') === 'checkbox' ? ['checked' => 'When checked'] : ['equals' => 'When value equals']),
            ])->default('always')->live()->visible(fn (Get $get): bool => filled($get('color'))),
            Forms\TextInput::make('highlight_value')->label('Matching value')->maxLength(100)
                ->visible(fn (Get $get): bool => filled($get('color')) && $get('highlight_when') === 'equals')
                ->required(fn (Get $get): bool => filled($get('color')) && $get('highlight_when') === 'equals'),
            Forms\Toggle::make('hidden')->label('Hidden by default')->default(false),
        ])->columns(2)->default([])->maxItems(30)->collapsible()
            ->itemLabel(fn (array $state): string => $state['label'] ?? 'Tracking column')
            ->addActionLabel('Add tracking column')->reorderableWithButtons()
            ->deleteAction(fn ($action) => $action->requiresConfirmation()
                ->modalDescription('Remove this column from the table? Stored tracking values are retained for recovery.'));
    }

    public static function criteriaForm(string $prefix = 'criteria.'): array
    {
        return [
            Forms\Toggle::make($prefix.'active_only')->label('Current personnel only')->default(true)->live(),
            ...collect(['shift' => 'Shift', 'rank' => 'Rank', 'station' => 'Station', 'division' => 'Division',
                'unit' => 'Unit / assignment', 'bid_position' => 'Bid position'])
                ->map(fn (string $label, string $key) => Forms\Select::make($prefix.$key)->label($label)
                    ->multiple()->searchable()->options(fn (): array => self::sourceOptions($key))->live())
                ->values()->all(),
        ];
    }

    public static function sourceOptions(string $key): array
    {
        $request = request();
        $records = $request->attributes->get('personnel.table.source-options');
        if ($records === null) {
            $records = app(PersonnelSource::class)->query()->get();
            $request->attributes->set('personnel.table.source-options', $records);
        }

        return $records->map(fn (Employee $employee) => app(PersonnelSource::class)->value($employee, $key))
            ->filter(fn ($value): bool => $value !== null && $value !== '')
            ->unique()->sort()->mapWithKeys(fn ($value): array => [(string) $value => (string) $value])->all();
    }

    public static function memberSelect(string $name = 'employee_ids'): Forms\Select
    {
        return Forms\Select::make($name)->label('Members')->multiple()->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Employee::query()
                ->where(fn ($query) => $query->whereLike('name', '%'.$search.'%')->orWhereLike('employee_id', '%'.$search.'%'))
                ->orderBy('name')->limit(50)->get()->mapWithKeys(fn (Employee $employee): array => [
                    $employee->id => $employee->name.' · '.$employee->employee_id,
                ])->all())
            ->getOptionLabelsUsing(fn (array $values): array => Employee::query()->whereIn('id', $values)->get()
                ->mapWithKeys(fn (Employee $employee): array => [$employee->id => $employee->name.' · '.$employee->employee_id])->all())
            ->maxItems(1000)->helperText('Search by name or Employee ID. Selecting a row never creates an account.');
    }

    public static function trackingInput(array $column, string $prefix = 'values.')
    {
        $name = $prefix.$column['id'];
        $options = array_combine($column['options'] ?? [], $column['options'] ?? []) ?: [];
        $input = match ($column['type']) {
            'checkbox' => Forms\Checkbox::make($name),
            'notes' => Forms\Textarea::make($name)->maxLength(5000)->rows(3),
            'number' => Forms\TextInput::make($name)->numeric(),
            'date' => Forms\DatePicker::make($name)->native(),
            'select', 'status' => Forms\Select::make($name)->options($options),
            'multiselect' => Forms\Select::make($name)->options($options)->multiple(),
            default => Forms\TextInput::make($name)->maxLength(500),
        };

        return $input->label($column['label']);
    }
}
