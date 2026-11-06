<?php

declare(strict_types=1);

namespace App\Services\ChecklistTemplates;

use App\Models\DailyCheckoutChecklistTemplateVersion as Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * Where a Daily Checkout checklist baseline comes from (docs/checklist-template-editor.md section 3):
 * the published editor version of the type when there is one, otherwise the repository file,
 * read exactly as the resolver always read it.
 *
 * Bound `scoped`: the published row is memoized per request / queued job, so one checkout resolves
 * every apparatus against one consistent baseline and a publish is picked up by the next request.
 * The editor flushes it around a publish. File reads are never memoized (the file is the
 * deploy-managed source and tests rewrite it between resolves).
 */
final class ChecklistTemplateRepository
{
    /** @var array<string, bool> connection => templates table exists */
    private array $tableExists = [];

    /** @var array<string, Version|null> "connection|type" => published row */
    private array $published = [];

    /**
     * The repository file of a type. Error names match the resolver's historic load errors.
     *
     * @return array{path: string, checklist: array<string, mixed>|null, error: 'missing'|'unreadable'|'invalid_json'|null}
     */
    public function fileTemplate(string $type, ?string $directory = null): array
    {
        $directory ??= self::defaultDirectory();
        $path = "{$directory}/{$type}_checklist.json";
        if (preg_match('/^[a-z0-9_]+$/D', $type) !== 1) {
            return ['path' => $path, 'checklist' => null, 'error' => 'missing'];
        }

        if (! is_file($path) || ! is_readable($path)) {
            return ['path' => $path, 'checklist' => null, 'error' => is_file($path) ? 'unreadable' : 'missing'];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return ['path' => $path, 'checklist' => null, 'error' => 'unreadable'];
        }

        try {
            $checklist = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['path' => $path, 'checklist' => null, 'error' => 'invalid_json'];
        }

        if (! is_array($checklist)) {
            return ['path' => $path, 'checklist' => null, 'error' => 'invalid_json'];
        }

        return ['path' => $path, 'checklist' => $checklist, 'error' => null];
    }

    /**
     * The published version of a type on a connection (the apparatus's: the pre-activation audit
     * resolves a candidate database). A database without the table (not yet migrated) has none.
     */
    public function published(string $type, ?string $connection = null): ?Version
    {
        $connection ??= DB::getDefaultConnection();
        if (! $this->hasTable($connection)) {
            return null;
        }

        // Inside a transaction the row is read fresh every time: callers resolve, wait for row locks,
        // then resolve again under the lock (inspection submit, inventory-correction apply), and that
        // second read must see a publish that committed while they waited.
        $inTransaction = DB::connection($connection)->transactionLevel() > 0;
        $key = $connection.'|'.$type;
        if (! $inTransaction && array_key_exists($key, $this->published)) {
            return $this->published[$key];
        }

        $published = Version::on($connection)
            ->where('checklist_type', $type)
            ->where('status', Version::STATUS_PUBLISHED)
            ->orderByDesc('id')
            ->first();
        if ($inTransaction) {
            // Never let a value read inside a transaction (possibly rolled back) outlive it.
            unset($this->published[$key]);

            return $published;
        }

        return $this->published[$key] = $published;
    }

    /**
     * The baseline every apparatus on this type resolves to: the published version wins, else the file.
     * `path` is always the repository file path (the resolver's output keeps it).
     *
     * @return array{source: 'file'|'published', path: string, checklist: array<string, mixed>|null, error: string|null, version_id: int|null}
     */
    public function baseline(string $type, ?string $directory = null, ?string $connection = null): array
    {
        $directory ??= self::defaultDirectory();
        $published = preg_match('/^[a-z0-9_]+$/D', $type) === 1 ? $this->published($type, $connection) : null;
        if ($published !== null) {
            $checklist = $published->checklist;

            return [
                'source' => 'published',
                'path' => "{$directory}/{$type}_checklist.json",
                'checklist' => is_array($checklist) ? $checklist : null,
                'error' => is_array($checklist) ? null : 'invalid_json',
                'version_id' => (int) $published->getKey(),
            ];
        }

        $file = $this->fileTemplate($type, $directory);

        return [
            'source' => 'file',
            'path' => $file['path'],
            'checklist' => $file['checklist'],
            'error' => $file['error'],
            'version_id' => null,
        ];
    }

    /** Forget memoized published rows and table checks (after a publish or a revert). */
    public function flush(): void
    {
        $this->published = [];
        $this->tableExists = [];
    }

    public static function defaultDirectory(): string
    {
        return base_path('storage/checklists');
    }

    /**
     * Schema::hasTable reads the catalog, so it never fails inside a Postgres transaction the way a
     * query against a missing table would (which would abort the caller's transaction).
     */
    private function hasTable(string $connection): bool
    {
        return $this->tableExists[$connection] ??= Schema::connection($connection)->hasTable((new Version)->getTable());
    }
}
