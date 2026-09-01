<?php

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Services\VideoConferencing\ConferenceBootstrapFactory;
use Filament\Pages\Page;

class VideoConferencing extends Page
{
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static string $view = 'filament.employee.pages.video-conferencing';

    protected static ?string $title = 'Video Conferencing';

    protected static ?string $navigationLabel = 'Video Conferencing';

    protected static ?string $slug = 'video-conferencing';

    protected static ?int $navigationSort = 2;

    public function getViewData(): array
    {
        $employee = $this->authenticatedEmployee();

        return [
            'enabled' => (bool) config('video-conferencing.enabled'),
            'conferenceBootstrap' => app(ConferenceBootstrapFactory::class)
                ->make('self', ConferenceJoinRole::Self, employee: $employee),
        ];
    }
}
