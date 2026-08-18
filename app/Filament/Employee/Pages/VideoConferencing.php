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
        $connectivityUrl = preg_replace(
            '/^wss:/i',
            'https:',
            preg_replace('/^ws:/i', 'http:', (string) config('video-conferencing.livekit.url')) ?? '',
        ) ?? '';

        return [
            'enabled' => (bool) config('video-conferencing.enabled'),
            'conferenceBootstrap' => [
                'roles' => collect(ConferenceJoinRole::cases())->map(fn (ConferenceJoinRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'station' => $role->isStation(),
                ])->all(),
                'lineup_time' => config('video-conferencing.lineup_time'),
                'connectivity_url' => $connectivityUrl,
                'connectivity_timeout_ms' => max(
                    1000,
                    min(10000, (int) config('video-conferencing.client_connectivity_timeout_ms', 5000)),
                ),
                'connectivity_help' => (string) config('video-conferencing.client_connectivity_help'),
                'endpoints' => [
                    'sessions' => route('employee.video-conferencing.api.sessions'),
                    'api_base' => url('/employee/video-conferencing/api'),
                    'connectivity_failures' => route('employee.video-conferencing.api.connectivity-failures'),
                ],
                'csrf_token' => csrf_token(),
            ],
        ];
    }
}
