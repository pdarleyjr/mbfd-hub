<?php

declare(strict_types=1);

namespace App\Services\HubSupport;

final class HubIssueSanitizer
{
    private const MAX_BYTES = 65_536;

    /** @return array<string, mixed> */
    public function sanitizeDiagnostics(mixed $input): array
    {
        if (! is_array($input)) {
            return ['events' => []];
        }

        $events = [];
        foreach (array_slice(is_array($input['events'] ?? null) ? $input['events'] : [], -25) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $safe = $this->event($event);
            if ($safe !== []) {
                $events[] = $safe;
            }
        }

        $result = ['events' => $events];
        foreach (['path', 'source'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $result[$key] = $this->path($input[$key]);
            }
        }
        if (isset($input['message']) && is_string($input['message'])) {
            $result['message'] = $this->redact($input['message'], 512);
        }

        while ($result['events'] !== [] && strlen((string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE)) > self::MAX_BYTES) {
            array_shift($result['events']);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function sanitizeMetadata(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $safe = [];
        foreach (['userAgent' => 512, 'language' => 32, 'timezone' => 64] as $field => $max) {
            if (is_string($input[$field] ?? null)) {
                $safe[$field] = $this->redact($input[$field], $max);
            }
        }
        foreach (['viewport', 'screen'] as $field) {
            if (is_array($input[$field] ?? null)) {
                foreach (['width', 'height'] as $axis) {
                    $value = $input[$field][$axis] ?? null;
                    if (is_numeric($value) && (int) $value >= 0 && (int) $value <= 16_384) {
                        $safe[$field][$axis] = (int) $value;
                    }
                }
            }
        }
        foreach (['online', 'standalone'] as $field) {
            if (is_bool($input[$field] ?? null)) {
                $safe[$field] = $input[$field];
            }
        }
        if (is_numeric($input['devicePixelRatio'] ?? null)) {
            $safe['devicePixelRatio'] = max(0.5, min(8, (float) $input['devicePixelRatio']));
        }

        return $safe;
    }

    public function path(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 4_096) {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);

        return substr((string) $path, 0, 2_048);
    }

    public function redact(string $input, int $max = 512): string
    {
        $text = mb_substr($input, 0, $max * 2);
        $patterns = [
            '/\b(?:authorization|bearer)\s*[:=]?\s*bearer\s+\S+/iu',
            '/\bauthorization\s*[:=]\s*(?:[a-z][a-z0-9_-]*\s+)?[^\s,;]+/iu',
            '/\b(?:set-)?cookie\s*[:=]\s*[^\r\n]+/iu',
            '/\bbearer\s+\S+/iu',
            '/\b(?:password|passwd|pwd|api[_-]?key|session[_-]?id|cookie|csrf(?:[_-]?token)?|authorization|token)\s*[:=]\s*[^\s,;]+/iu',
            '/\beyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/u',
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu',
        ];
        $text = (string) preg_replace($patterns, '[redacted]', $text);
        $text = (string) preg_replace('~(?<![[:alnum:]_])((?:https?://[^\s?#]+|/[^\s?#]+))(?:\?[^\s#]*)?(?:#[^\s]*)?~iu', '$1', $text);

        return mb_substr($text, 0, $max);
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function event(array $event): array
    {
        $type = $event['type'] ?? null;
        if (! in_array($type, ['error', 'rejection', 'request'], true)) {
            return [];
        }

        $safe = ['type' => $type];
        foreach (['timestamp' => 40, 'name' => 80, 'message' => 512, 'stack' => 2_048] as $key => $max) {
            if (is_string($event[$key] ?? null)) {
                $safe[$key] = $this->redact($event[$key], $max);
            }
        }
        foreach (['path', 'source'] as $key) {
            if (isset($event[$key])) {
                $safe[$key] = $this->path($event[$key]);
            }
        }
        if ($type === 'request') {
            $method = strtoupper((string) ($event['method'] ?? ''));
            if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
                $safe['method'] = $method;
            }
            $status = $event['status'] ?? null;
            if (is_numeric($status) && ((int) $status === 0 || ((int) $status >= 400 && (int) $status <= 599))) {
                $safe['status'] = (int) $status;
            }
            if (is_numeric($event['duration'] ?? null)) {
                $safe['duration'] = max(0, min(120_000, (int) $event['duration']));
            }
        } else {
            foreach (['line', 'column'] as $key) {
                if (is_numeric($event[$key] ?? null)) {
                    $safe[$key] = max(0, min(1_000_000, (int) $event[$key]));
                }
            }
        }

        return $safe;
    }
}
