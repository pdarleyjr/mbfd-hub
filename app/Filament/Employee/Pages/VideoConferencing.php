<?php

namespace App\Filament\Employee\Pages;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use Filament\Pages\Page;

class VideoConferencing extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static string $view = 'filament.employee.pages.video-conferencing';

    protected static ?string $title = 'Video Conferencing';

    protected static ?string $navigationLabel = 'Video Conferencing';

    protected static ?string $slug = 'video-conferencing';

    protected static ?int $navigationSort = 2;

    public function getViewData(): array
    {
        return [
            'enabled' => (bool) config('video-conferencing.enabled'),
            'conferenceBootstrap' => [
                'roles' => collect(ConferenceJoinRole::cases())->map(fn (ConferenceJoinRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'station' => $role->isStation(),
                ])->all(),
                'lineup_time' => config('video-conferencing.lineup_time'),
                'endpoints' => [
                    'sessions' => route('employee.video-conferencing.api.sessions'),
                    'api_base' => url('/employee/video-conferencing/api'),
                ],
                'csrf_token' => csrf_token(),
            ],
        ];
    }
}
