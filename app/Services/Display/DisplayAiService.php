<?php

declare(strict_types=1);

namespace App\Services\Display;

use App\Jobs\GenerateDisplayAiSnapshotJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Descriptive-only AI brief for the command display.
 *
 * Strict guarantees:
 *   - NEVER calls the LLM inline in the request path. The request thread only
 *     reads cache and (when stale) dispatches a background job.
 *   - The snapshot fed to the model is sanitised: it is built from
 *     {@see DisplaySnapshotService::overview()} (already redacted) with any
 *     residual identity / free text stripped again here.
 *   - The model is instructed to be purely DESCRIPTIVE — no recommendations,
 *     no imperatives. A post-generation guard re-checks the output and strips
 *     any sentence containing forbidden words, lowering confidence if it fires.
 */
final class DisplayAiService
{
    public const CACHE_KEY = 'display.ai_snapshot';   // ['fp','brief','at']

    public const PENDING_KEY = 'display.ai_pending';  // fingerprint currently generating

    public const CACHE_TTL = 1800;                    // 30 minutes

    public const PENDING_TTL = 600;                   // 10 minutes guard

    /**
     * Words that betray a prescriptive (rather than descriptive) statement.
     *
     * @var list<string>
     */
    public const FORBIDDEN_WORDS = ['should', 'must', 'recommend', 'need to'];

    public function __construct(private readonly DisplaySnapshotService $snapshots) {}

    /**
     * Return the current brief envelope for the endpoint.
     *
     * @return array{state: string, brief: ?array<string, mixed>, fingerprint: ?string}
     *                                                                                  state ∈ {'fresh','stale','generating'}
     */
    public function brief(): array
    {
        $sanitized = $this->buildSanitizedSnapshot();
        $fingerprint = $this->fingerprint($sanitized);

        /** @var array{fp: string, brief: array<string, mixed>, at: string}|null $cached */
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && ($cached['fp'] ?? null) === $fingerprint) {
            return [
                'state' => 'fresh',
                'brief' => $cached['brief'] ?? null,
                'fingerprint' => $fingerprint,
            ];
        }

        // Data changed (or no cache): schedule a non-blocking regeneration.
        $this->ensureFresh($fingerprint, $sanitized);

        if (is_array($cached) && isset($cached['brief'])) {
            return [
                'state' => 'stale',
                'brief' => $cached['brief'],
                'fingerprint' => $fingerprint,
            ];
        }

        return [
            'state' => 'generating',
            'brief' => null,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Non-blocking: dispatch a regeneration job only when nothing is already
     * pending for this exact fingerprint.
     *
     * @param  array<string, mixed>  $sanitized
     */
    public function ensureFresh(string $fingerprint, array $sanitized): void
    {
        try {
            if (Cache::get(self::PENDING_KEY) === $fingerprint) {
                return;
            }

            Cache::put(self::PENDING_KEY, $fingerprint, self::PENDING_TTL);
            GenerateDisplayAiSnapshotJob::dispatch($fingerprint, $sanitized);
        } catch (\Throwable $e) {
            Log::debug('[Display AI] ensureFresh skipped: '.$e->getMessage());
        }
    }

    /**
     * Build the sanitised snapshot the model is allowed to see. Identity and
     * free-text fields are stripped from the (already redacted) overview so no
     * PII can ever reach the LLM, even if a future overview field leaks one.
     *
     * @return array<string, mixed>
     */
    public function buildSanitizedSnapshot(): array
    {
        $overview = $this->snapshots->overview();

        $stations = array_map(static function (array $row): array {
            return [
                'name' => $row['name'] ?? null,
                'apparatus_count' => $row['apparatus_count'] ?? 0,
                'in_service' => $row['in_service'] ?? 0,
                'out_of_service' => $row['out_of_service'] ?? 0,
                'maintenance' => $row['maintenance'] ?? 0,
                'open_defects' => $row['open_defects'] ?? 0,
                'readiness_percent' => $row['readiness_percent'] ?? 0,
                'readiness_status' => $row['readiness_status'] ?? 'UNKNOWN',
            ];
        }, $overview['stations'] ?? []);

        $defectItems = array_map(static function (array $item): array {
            return [
                'apparatus' => $item['apparatus_name'] ?? 'Unknown',
                'item' => $item['item'] ?? null,
                'status' => $item['status'] ?? null,
            ];
        }, $overview['defects']['items'] ?? []);

        return [
            'organization' => $overview['organization'] ?? [],
            'overview' => $overview['overview'] ?? [],
            'stations' => $stations,
            'defects' => [
                'total_open' => $overview['defects']['total_open'] ?? 0,
                'critical_missing' => $overview['defects']['critical_missing'] ?? 0,
                'items' => $defectItems,
            ],
            'submissions' => $overview['submissions'] ?? [],
            'requests' => $overview['requests'] ?? [],
            'inventory_exceptions' => [
                'total_active_items' => $overview['inventory_exceptions']['total_active_items'] ?? 0,
                'out_of_stock' => $overview['inventory_exceptions']['out_of_stock'] ?? 0,
                'low_stock' => $overview['inventory_exceptions']['low_stock'] ?? 0,
            ],
            'source_health' => $overview['source_health'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $sanitized
     */
    public function fingerprint(array $sanitized): string
    {
        return md5((string) json_encode($sanitized));
    }

    /**
     * Post-generation guard: remove any sentence containing a forbidden word
     * from every string field and lower confidence if the guard fired. Pure;
     * used by the job after parsing the model output.
     *
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    public static function enforceDescriptive(array $brief): array
    {
        $fired = false;

        $scrub = static function (string $text) use (&$fired): string {
            // Split into sentences, keeping it simple and locale-agnostic.
            $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];
            $kept = array_filter($sentences, static function (string $sentence) use (&$fired): bool {
                foreach (self::FORBIDDEN_WORDS as $word) {
                    if (stripos($sentence, $word) !== false) {
                        $fired = true;

                        return false;
                    }
                }

                return trim($sentence) !== '';
            });

            return trim(implode(' ', $kept));
        };

        if (isset($brief['briefing']) && is_string($brief['briefing'])) {
            $brief['briefing'] = $scrub($brief['briefing']);
        }

        if (isset($brief['active_run_summary']) && is_string($brief['active_run_summary'])) {
            $brief['active_run_summary'] = $scrub($brief['active_run_summary']);
        }

        if (isset($brief['camera_source_summary']) && is_string($brief['camera_source_summary'])) {
            $brief['camera_source_summary'] = $scrub($brief['camera_source_summary']);
        }

        if (isset($brief['station_summaries']) && is_array($brief['station_summaries'])) {
            $brief['station_summaries'] = array_values(array_map(
                static function ($entry) use ($scrub): array {
                    if (! is_array($entry)) {
                        return ['station' => 'Unknown', 'summary' => ''];
                    }

                    return [
                        'station' => (string) ($entry['station'] ?? 'Unknown'),
                        'summary' => is_string($entry['summary'] ?? null)
                            ? $scrub($entry['summary'])
                            : '',
                    ];
                },
                $brief['station_summaries']
            ));
        }

        if ($fired) {
            $current = isset($brief['confidence']) && is_numeric($brief['confidence'])
                ? (float) $brief['confidence']
                : 0.5;
            $brief['confidence'] = round(max(0.0, $current - 0.3), 2);
            $gaps = isset($brief['data_gaps']) && is_array($brief['data_gaps']) ? $brief['data_gaps'] : [];
            $gaps[] = 'Prescriptive statements were removed from the generated brief.';
            $brief['data_gaps'] = array_values($gaps);
        }

        return $brief;
    }
}
