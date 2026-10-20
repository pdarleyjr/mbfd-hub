<?php

declare(strict_types=1);

namespace App\Services\CommandBoard;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hub -> Command Board internal command-PIN API. Every call carries the
 * dedicated service bearer token. Only bcrypt hashes are ever sent; request
 * and response bodies are never logged.
 */
final class CommandBoardPinClient
{
    public const TIMEOUT_SECONDS = 10;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const PIN_PATH = '/api/internal/command-pin';

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null && $this->token() !== null;
    }

    public function status(): CommandBoardPinStatus
    {
        return $this->send('GET');
    }

    /**
     * @param  array{sub: string, name: string}  $updatedBy
     */
    public function replace(string $pinHash, array $updatedBy): CommandBoardPinStatus
    {
        return $this->send('PUT', ['pinHash' => $pinHash, 'updatedBy' => $updatedBy]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function send(string $method, ?array $payload = null): CommandBoardPinStatus
    {
        $base = $this->baseUrl();
        $token = $this->token();
        if ($base === null || $token === null) {
            throw CommandBoardRequestFailed::notConfigured();
        }

        $write = $method !== 'GET';
        $context = ['method' => $method, 'path' => self::PIN_PATH];
        try {
            $response = $write
                ? $this->request($token)->put($base.self::PIN_PATH, $payload ?? [])
                : $this->request($token)->get($base.self::PIN_PATH);
        } catch (ConnectionException $exception) {
            $timedOut = self::isTimeout($exception);
            Log::warning('command_board.pin.unreachable', $context + ['timed_out' => $timedOut]);

            throw $timedOut
                ? CommandBoardRequestFailed::timedOut(self::TIMEOUT_SECONDS, $write)
                : CommandBoardRequestFailed::unreachable($write);
        }

        return $this->decode($response, $write, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function decode(Response $response, bool $write, array $context): CommandBoardPinStatus
    {
        if (! $response->successful()) {
            $body = $response->json();
            $errorCode = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;
            Log::warning('command_board.pin.rejected', $context + ['status' => $response->status(), 'error' => $errorCode]);

            throw CommandBoardRequestFailed::forStatus($response->status(), $errorCode, $write);
        }

        $body = $response->json();
        $status = is_array($body) ? CommandBoardPinStatus::fromPayload($body) : null;
        if ($status === null) {
            Log::warning('command_board.pin.invalid_response', $context + ['status' => $response->status()]);

            throw CommandBoardRequestFailed::invalidResponse($write);
        }

        return $status;
    }

    /** Redirects are never followed, so the bearer token and PIN hash only ever go to COMMAND_BOARD_URL. */
    private function request(string $token): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->withToken($token)
            ->acceptJson()
            ->asJson();
    }

    private static function isTimeout(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'curl error 28') || str_contains($message, 'timed out');
    }

    /** The configured board origin; only https is accepted because the service token travels with every call. */
    private function baseUrl(): ?string
    {
        $url = trim((string) config('services.command_board.url'));
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme) && strtolower($scheme) === 'https' && is_string($host) && $host !== ''
            ? rtrim($url, '/')
            : null;
    }

    private function token(): ?string
    {
        $token = config('services.command_board.service_token');

        return is_string($token) && trim($token) !== '' ? $token : null;
    }
}
