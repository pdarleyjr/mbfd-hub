<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Support\DefectCorrectiveActions;
use App\Models\Training\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Tables\Columns\TextColumn;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HubTimezoneTest extends TestCase
{
    #[DataProvider('easternInstants')]
    public function test_filament_timestamp_labels_are_eastern_without_changing_storage(string $instant, string $label): void
    {
        self::assertSame('UTC', config('app.timezone'));
        $column = TextColumn::make('created_at')->dateTime('Y-m-d H:i:s T');
        $entry = TextEntry::make('created_at')->dateTime('Y-m-d H:i:s T');
        $record = new User;
        $record->created_at = CarbonImmutable::parse($instant);
        $entry->container(Infolist::make()->record($record));

        self::assertSame($label, $column->formatState($record->created_at));
        self::assertSame($label, $entry->formatState($record->created_at));
        self::assertSame(CarbonImmutable::parse($instant)->utc()->format('Y-m-d H:i:s'), $record->getAttributes()['created_at']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function easternInstants(): iterable
    {
        yield 'before midnight' => ['2026-10-26T03:59:59Z', '2026-10-25 23:59:59 EDT'];
        yield 'after midnight' => ['2026-10-26T04:00:00Z', '2026-10-26 00:00:00 EDT'];
        yield 'before spring change' => ['2026-03-08T06:59:59Z', '2026-03-08 01:59:59 EST'];
        yield 'after spring change' => ['2026-03-08T07:00:00Z', '2026-03-08 03:00:00 EDT'];
        yield 'before fall change' => ['2026-11-01T05:59:59Z', '2026-11-01 01:59:59 EDT'];
        yield 'after fall change' => ['2026-11-01T06:00:00Z', '2026-11-01 01:00:00 EST'];
    }

    public function test_calendar_dates_and_time_only_inputs_keep_their_existing_semantics(): void
    {
        $record = new TrainingSession(['held_on' => '2026-10-26']);
        self::assertSame('2026-10-26', TextColumn::make('held_on')->date('Y-m-d')->formatState($record->held_on));
        self::assertSame('2026-10-26', TextEntry::make('held_on')->container(Infolist::make()->record($record))->date('Y-m-d')->formatState($record->held_on));
        self::assertSame('UTC', DatePicker::make('held_on')->getTimezone());
        self::assertSame('UTC', TimePicker::make('time')->getTimezone());
    }

    public function test_datetime_picker_round_trips_eastern_input_to_utc_storage(): void
    {
        $host = new HubTimezoneForm;
        $picker = DateTimePicker::make('starts_at')->seconds(false);
        $form = Form::make($host)->schema([$picker]);
        $form->fill(['starts_at' => '2026-03-08 07:30:00']);

        self::assertSame('2026-03-08 03:30', $picker->getState());
        self::assertSame(['starts_at' => '2026-03-08 07:30'], $picker->getStateToDehydrate());

        $date = DatePicker::make('held_on');
        Form::make($host)->schema([$date])->fill(['held_on' => '2026-03-08']);
        self::assertSame('2026-03-08', $date->getState());
        self::assertSame(['held_on' => '2026-03-08'], $date->getStateToDehydrate());
    }

    public function test_repair_appointment_keeps_its_explicit_eastern_json_offset(): void
    {
        $host = new HubTimezoneForm;
        $action = DefectCorrectiveActions::update(\Filament\Actions\Action::make('update'), static fn () => null);
        $form = $action->getForm(Form::make($host)->statePath('data'));
        $form->fill(['details' => ['repair_appointment' => '2026-11-02T09:30:00-05:00']]);
        $picker = collect($form->getFlatComponents())->first(fn ($component) => $component instanceof DateTimePicker && $component->getName() === 'details.repair_appointment');

        self::assertInstanceOf(DateTimePicker::class, $picker);
        self::assertSame('2026-11-02 09:30:00', $picker->getState());
        self::assertSame(['data.details.repair_appointment' => '2026-11-02T09:30:00-05:00'], $picker->getStateToDehydrate());
    }
}

final class HubTimezoneForm extends Component implements HasForms
{
    use InteractsWithForms;

    public ?string $starts_at = null;

    public ?string $held_on = null;

    public array $data = [];
}
