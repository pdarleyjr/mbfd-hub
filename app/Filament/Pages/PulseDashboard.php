<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Exports\ExportDataset;
use App\Services\Exports\ExportFailedException;
use App\Services\Exports\TableExportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Laravel\Pulse\Contracts\Storage;

class PulseDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Laravel Pulse';

    protected static ?string $title = 'Laravel Pulse';

    protected static ?string $slug = 'pulse';

    protected static ?int $navigationSort = 50;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.pulse-dashboard';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.system.view') ?? false;
    }

    public function canExportData(User $actor): bool
    {
        return $actor->can('admin.system.view') && $actor->can('viewPulse');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('exportData')->label('Export telemetry snapshot')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (): bool => auth()->user() instanceof User && $this->canExportData(auth()->user()))
            ->modalDescription('Exports the underlying Pulse data for this range. Dashboard card filters inside the embedded view are independent.')
            ->form([
                Radio::make('format')->label('File type')->options(['xlsx' => 'Excel (.xlsx)', 'pdf' => 'PDF'])->default('xlsx')->required(),
                Select::make('minutes')->label('Time range')->options([60 => 'Last hour', 360 => 'Last 6 hours', 1440 => 'Last 24 hours', 10080 => 'Last 7 days'])->default(60)->required(),
            ])->modalSubmitActionLabel('Download')
            ->action(function (array $data, Action $action) {
                try {
                    return app(TableExportService::class)->downloadDataset(
                        auth()->user(), static::class, (string) ($data['format'] ?? ''),
                        fn (): bool => $this->canExportData(auth()->user()),
                        fn (): ExportDataset => $this->exportDataset(auth()->user(), (int) ($data['minutes'] ?? 60)),
                    );
                } catch (ExportFailedException $exception) {
                    Notification::make()->title('Export not created')->body($exception->getMessage())->danger()->send();
                    $action->halt();

                    return null;
                }
            })];
    }

    protected function exportDataset(User $actor, int $minutes): ExportDataset
    {
        abort_unless($this->canExportData($actor), 403);
        if (! in_array($minutes, [60, 360, 1440, 10080], true)) {
            throw new ExportFailedException('Choose a supported Pulse time range.');
        }
        $storage = app(Storage::class);
        $interval = CarbonInterval::minutes($minutes);
        $now = CarbonImmutable::now((string) config('hub.timezone'));
        $rows = [];
        foreach ($storage->values('system') as $sample) {
            try {
                $system = json_decode($sample->value, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new ExportFailedException('A stored Pulse server sample could not be read.', previous: $exception);
            }
            $observedAt = CarbonImmutable::createFromTimestamp($sample->timestamp);
            foreach (['cpu', 'memory_used', 'memory_total'] as $metric) {
                $rows[] = [$metric, $system['name'], 'Latest sample', $system[$metric], $observedAt];
            }
            foreach ($system['storage'] as $disk) {
                foreach (['used', 'total'] as $metric) {
                    $rows[] = ['storage_'.$metric, $system['name'].' '.$disk['directory'], 'Latest sample (MB)', $disk[$metric], $observedAt];
                }
            }
        }
        foreach (['avg' => ['cpu', 'memory'], 'count' => ['queued', 'processing', 'processed', 'released', 'failed']] as $aggregate => $types) {
            foreach ($storage->graph($types, $aggregate, $interval) as $key => $series) {
                foreach ($series as $type => $points) {
                    foreach ($points as $bucket => $value) {
                        $rows[] = [$type, $key, $aggregate, $value, CarbonImmutable::parse($bucket, 'UTC')];
                    }
                }
            }
        }
        foreach (['user_request', 'slow_user_request', 'user_job', 'cache_hit', 'cache_miss', 'slow_query', 'exception', 'slow_request', 'slow_job', 'slow_outgoing_request'] as $type) {
            $aggregates = in_array($type, ['slow_query', 'exception', 'slow_request', 'slow_job', 'slow_outgoing_request'], true) ? ['count', 'max'] : ['count'];
            foreach ($storage->aggregate($type, $aggregates, $interval, limit: 10001) as $reading) {
                foreach ($aggregates as $aggregate) {
                    $value = $reading->{$aggregate} ?? null;
                    $rows[] = [$type, $reading->key, $type === 'exception' && $aggregate === 'max' ? 'Latest occurrence' : $aggregate,
                        $type === 'exception' && $aggregate === 'max' && $value !== null ? CarbonImmutable::createFromTimestamp((int) $value) : $value, null];
                }
            }
        }

        return ExportDataset::fromRows($actor, 'Pulse Telemetry', ['Metric', 'Label', 'Aggregate', 'Value', 'Bucket time'], $rows,
            'Pulse telemetry snapshot', [
                'Requested range: '.$now->subMinutes($minutes)->format('Y-m-d H:i T').' to '.$now->format('Y-m-d H:i T'),
                'Native Pulse bucket alignment applies. Embedded dashboard filters are independent.',
                'Server gauges are the latest stored samples; their observation times are listed separately.',
            ]);
    }
}
