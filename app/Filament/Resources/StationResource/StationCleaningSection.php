<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationResource;

use App\Models\Station;
use App\Services\Stations\StationCleaningSchedule;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;

/**
 * "Today's Station Cleaning" on the Admin station profile: today's rotating focus with its instructions, any
 * additional tasks, and the every-day duties folded away. Read from the schedule configuration at render time.
 */
final class StationCleaningSection
{
    /**
     * One read per loaded station record and day. Keyed by the record object, so the next request (which loads a
     * fresh record) always sees the latest saved schedule.
     *
     * @var \WeakMap<Station, array<string, array<string, mixed>>>|null
     */
    private static ?\WeakMap $memo = null;

    public static function make(): Section
    {
        return Section::make("Today's Station Cleaning")
            ->id('station-cleaning')
            ->description(fn (Station $record): string => self::schedule($record)['date_label'])
            ->schema([
                TextEntry::make('cleaning_focus')
                    ->label('Focus')
                    ->state(fn (Station $record): string => self::schedule($record)['focus']['title'] ?? self::schedule($record)['note'] ?? '')
                    ->weight(fn (Station $record): ?string => self::schedule($record)['focus'] !== null ? 'bold' : null)
                    ->color(fn (Station $record): ?string => self::schedule($record)['focus'] !== null ? null : 'gray')
                    ->helperText(fn (Station $record): ?string => (self::schedule($record)['focus']['date_exception'] ?? false) ? 'Changed for this date only.' : null),
                TextEntry::make('cleaning_extras')
                    ->label('Also today')
                    ->state(fn (Station $record): array => array_map(
                        static fn (array $extra): string => $extra['instructions'] === [] ? $extra['title'] : $extra['title'].': '.implode(' ', $extra['instructions']),
                        self::schedule($record)['extras'],
                    ))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->visible(fn (Station $record): bool => self::schedule($record)['extras'] !== []),
                Section::make('Instructions')
                    ->compact()
                    ->collapsible()
                    ->schema([
                        TextEntry::make('cleaning_instructions')
                            ->hiddenLabel()
                            ->state(fn (Station $record): array => self::schedule($record)['focus']['instructions'] ?? [])
                            ->listWithLineBreaks()
                            ->bulleted(),
                    ])
                    ->visible(fn (Station $record): bool => (self::schedule($record)['focus']['instructions'] ?? []) !== []),
                Section::make('Every day')
                    ->compact()
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('cleaning_baseline')
                            ->hiddenLabel()
                            ->state(fn (Station $record): array => self::schedule($record)['baseline']['instructions'] ?? [])
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->size(TextEntry\TextEntrySize::Small),
                    ])
                    ->visible(fn (Station $record): bool => (self::schedule($record)['baseline']['instructions'] ?? []) !== []),
                TextEntry::make('cleaning_source')
                    ->hiddenLabel()
                    ->state(fn (Station $record): string => 'Source: '.self::schedule($record)['source'])
                    ->size(TextEntry\TextEntrySize::ExtraSmall)
                    ->color('gray'),
            ])
            ->collapsible();
    }

    /** @return array<string, mixed> */
    private static function schedule(Station $record): array
    {
        $service = app(StationCleaningSchedule::class);
        $day = $service->calendarDay()->toDateString();
        self::$memo ??= new \WeakMap;
        $cached = self::$memo[$record] ?? [];
        if (! isset($cached[$day])) {
            $cached[$day] = $service->forStation($record);
            self::$memo[$record] = $cached;
        }

        return $cached[$day];
    }
}
