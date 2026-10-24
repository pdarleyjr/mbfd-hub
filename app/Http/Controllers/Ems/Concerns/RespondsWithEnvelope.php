<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ems\Concerns;

use App\Exceptions\PasswordChangeRequired;
use App\Services\Ems\EmsException;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One JSON envelope for the whole EMS API:
 *   success: {"ok": true,  "data": ..., "meta": {...}}
 *   failure: {"ok": false, "error": {"code": "...", "message": "plain language", "fields": {...}}}
 * Responses are never cached.
 */
trait RespondsWithEnvelope
{
    private const NO_STORE = ['Cache-Control' => 'private, no-store'];

    /** @param array<string, mixed> $meta */
    protected function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data, 'meta' => (object) $meta], $status, self::NO_STORE);
    }

    /**
     * An Idempotency::run() result: 201 for the first call of a create, 200 for a replay.
     *
     * @param  array{result: array<string, mixed>, replayed: bool}  $outcome
     */
    protected function written(array $outcome, bool $creates = false): JsonResponse
    {
        // 201 only when something new was created: not for a replay, a resumed draft or an unchanged reconciliation.
        $created = $creates && ! $outcome['replayed'] && ! ($outcome['result']['resumed'] ?? false) && ! ($outcome['result']['unchanged'] ?? false);

        return $this->ok($outcome['result'], ['replayed' => $outcome['replayed']], $created ? 201 : 200);
    }

    /** @param array<string, list<string>> $fields */
    protected function fail(string $message, string $code, int $status, array $fields = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields]], $status, self::NO_STORE);
    }

    /** Run a handler and turn every refusal into the standard failure envelope. */
    protected function guarded(Closure $handler): JsonResponse
    {
        try {
            return $handler();
        } catch (EmsException $exception) {
            return $this->fail($exception->getMessage(), $exception->errorCode, $exception->status, $exception->fields);
        } catch (ValidationException $exception) {
            return $this->fail((string) collect($exception->errors())->flatten()->first(), 'validation_failed', 422, $exception->errors());
        } catch (ModelNotFoundException) {
            return $this->fail('That record could not be found.', 'not_found', 404);
        } catch (AuthorizationException $exception) {
            return $this->fail($exception->getMessage() !== '' ? $exception->getMessage() : 'You do not have access to do that.', 'forbidden', 403);
        } catch (HttpExceptionInterface $exception) {
            return $this->fail($exception->getMessage() !== '' ? $exception->getMessage() : 'The request could not be completed.', 'http_'.$exception->getStatusCode(), $exception->getStatusCode());
        } catch (AuthenticationException|PasswordChangeRequired $exception) {
            throw $exception; // the framework's sign-in and password-change handling owns these
        } catch (Throwable $exception) {
            // Log what support needs (who, where, what failed); never the request body, headers or session.
            Log::error('EMS API request failed', [
                'route' => request()->route()?->getName(), 'method' => request()->method(), 'user_id' => request()->user()?->getKey(),
                'exception' => $exception::class, 'message' => $exception->getMessage(), 'at' => basename($exception->getFile()).':'.$exception->getLine(),
            ]);

            return $this->fail('Something went wrong on our side and nothing was saved. Try again in a moment; if it keeps happening, tell the EMS office.', 'server_error', 500);
        }
    }

    /** The client's idempotency key: header first, then the body. */
    protected function idempotencyKey(Request $request): string
    {
        return trim((string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', '')));
    }

    /** @return array<string, mixed> */
    protected function pageMeta(int $page, int $perPage, int $total): array
    {
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / max(1, $perPage)))];
    }
}
