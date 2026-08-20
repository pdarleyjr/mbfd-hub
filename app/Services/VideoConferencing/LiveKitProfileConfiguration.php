<?php

namespace App\Services\VideoConferencing;

use App\Exceptions\VideoConferencing\ConferenceUnavailableException;

class LiveKitProfileConfiguration
{
    /** @var list<string> */
    private const PROFILES = ['cloud', 'self_hosted'];

    public function profile(): string
    {
        $profile = (string) config('video-conferencing.livekit.profile', 'self_hosted');
        if (! in_array($profile, self::PROFILES, true)) {
            throw new ConferenceUnavailableException('The LiveKit profile is invalid.');
        }

        return $profile;
    }

    public function label(): string
    {
        return $this->profile() === 'cloud' ? 'LiveKit Cloud' : 'Self-hosted LiveKit';
    }

    public function clientUrl(): string
    {
        $url = $this->value('url');
        if (! preg_match('#^wss?://[^\s]+$#i', $url)) {
            throw new ConferenceUnavailableException('LiveKit client URL is not configured.');
        }

        return rtrim($url, '/');
    }

    public function apiUrl(): string
    {
        $url = $this->value('api_url');
        if ($url === '') {
            $url = preg_replace('/^wss:/i', 'https:', $this->clientUrl()) ?? '';
            $url = preg_replace('/^ws:/i', 'http:', $url) ?? '';
        }

        if (! preg_match('#^https?://[^\s]+$#i', $url)) {
            throw new ConferenceUnavailableException('LiveKit API URL is not configured.');
        }

        return rtrim($url, '/');
    }

    public function apiKey(): string
    {
        return $this->requiredValue('api_key');
    }

    public function apiSecret(): string
    {
        return $this->requiredValue('api_secret');
    }

    private function requiredValue(string $key): string
    {
        $value = $this->value($key);
        if ($value === '') {
            throw new ConferenceUnavailableException("LiveKit {$key} is not configured.");
        }

        return $value;
    }

    private function value(string $key): string
    {
        // Direct keys remain a test/backward-compatibility shim. Production
        // configuration is isolated under the explicitly selected profile.
        $direct = config("video-conferencing.livekit.{$key}");
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        return trim((string) config(
            "video-conferencing.livekit.profiles.{$this->profile()}.{$key}",
        ));
    }
}
