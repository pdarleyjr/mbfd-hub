<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Models\AiAutomation\AiAutomationPlaybook;
use App\Models\AiAutomation\AiAutomationPlaybookRevision;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The editable reply templates and word lists (the Response Playbooks screen). The engine reads here on every
 * call (never cached), so a saved edit applies to the next reply with no deploy. The config file holds only
 * first-install defaults: a row that exists is never overwritten by them.
 */
final class PlaybookRepository
{
    public const TEMPLATE_MAX_CHARS = 320;

    public const LIST_MAX_LINES = 300;

    public const LIST_TERM_MAX_CHARS = 60;

    /** Lists that must never be emptied: an empty list would silently stop escalating. */
    private const NON_EMPTY_LISTS = ['safety_terms'];

    /** @return array{body: string, version: int} */
    public function template(string $key): array
    {
        $row = AiAutomationPlaybook::query()->where('key', $key)->first();
        if ($row !== null && $row->kind === AiAutomationPlaybook::KIND_TEMPLATE) {
            return ['body' => (string) $row->body, 'version' => (int) $row->version];
        }

        $default = $row === null ? config("ai_automation.templates.{$key}.body") : null;
        if (! is_string($default)) {
            throw new InvalidArgumentException("Unknown reply template [{$key}].");
        }

        return ['body' => $default, 'version' => 0];
    }

    /** @return list<string> lower-cased, trimmed, unique, non-empty */
    public function terms(string $listKey): array
    {
        $row = AiAutomationPlaybook::query()->where('key', $listKey)->first();
        if ($row !== null && $row->kind === AiAutomationPlaybook::KIND_LIST) {
            return self::parseTerms((string) $row->body);
        }

        $default = $row === null ? config("ai_automation.lists.{$listKey}.terms") : null;
        if (! is_array($default)) {
            throw new InvalidArgumentException("Unknown word list [{$listKey}].");
        }

        return self::parseTerms(implode("\n", array_map('strval', $default)));
    }

    /** Inserts the config defaults that have no row yet. Never overwrites an edited row. Returns rows inserted. */
    public function ensureSeeded(): int
    {
        $inserted = 0;

        foreach ((array) config('ai_automation.templates', []) as $key => $definition) {
            $inserted += $this->seedOne((string) $key, AiAutomationPlaybook::KIND_TEMPLATE, (array) $definition, (string) ($definition['body'] ?? ''));
        }
        foreach ((array) config('ai_automation.lists', []) as $key => $definition) {
            $inserted += $this->seedOne((string) $key, AiAutomationPlaybook::KIND_LIST, (array) $definition, implode("\n", array_map('strval', (array) ($definition['terms'] ?? []))));
        }

        return $inserted;
    }

    /**
     * Saves an edit: validates, bumps the version and writes the revision, in one transaction. Saving the body
     * that is already live changes nothing (no version bump, no revision).
     *
     * @throws InvalidArgumentException with a message an administrator can act on
     */
    public function update(AiAutomationPlaybook $playbook, string $body, User $editor): AiAutomationPlaybook
    {
        $normalized = $this->validate($playbook, $body);

        return DB::transaction(function () use ($playbook, $normalized, $editor): AiAutomationPlaybook {
            /** @var AiAutomationPlaybook $fresh */
            $fresh = AiAutomationPlaybook::query()->whereKey($playbook->getKey())->lockForUpdate()->firstOrFail();
            if ((string) $fresh->body === $normalized) {
                return $fresh;
            }

            $version = (int) $fresh->version + 1;
            $fresh->forceFill(['body' => $normalized, 'version' => $version, 'updated_by_user_id' => $editor->getKey()])->save();
            AiAutomationPlaybookRevision::query()->create([
                'playbook_id' => $fresh->getKey(),
                'version' => $version,
                'body' => $normalized,
                'edited_by_user_id' => $editor->getKey(),
                'edited_by_name' => self::editorName($editor),
                'created_at' => now(),
            ]);

            return $fresh;
        });
    }

    /**
     * Checks a body against the rules for the playbook's kind and returns the normalized text to store.
     *
     * @throws InvalidArgumentException
     */
    public function validate(AiAutomationPlaybook $playbook, string $body): string
    {
        return $playbook->kind === AiAutomationPlaybook::KIND_LIST
            ? $this->validateList($playbook->key, $body)
            : $this->validateTemplate($body);
    }

    private function validateTemplate(string $body): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ($text === '') {
            throw new InvalidArgumentException('The reply cannot be empty.');
        }
        if (mb_strlen($text) > self::TEMPLATE_MAX_CHARS) {
            throw new InvalidArgumentException('The reply is '.mb_strlen($text).' characters. Keep it to '.self::TEMPLATE_MAX_CHARS.' or fewer.');
        }

        preg_match_all('/\{([^{}]*)\}/', $text, $found);
        foreach ($found[1] as $name) {
            if ($name !== 'item') {
                throw new InvalidArgumentException('Only {item} can be used as a placeholder. Remove {'.$name.'}.');
            }
        }
        if (preg_match('/[{}]/', (string) preg_replace('/\{item\}/', '', $text)) === 1) {
            throw new InvalidArgumentException('Curly braces are only allowed as the {item} placeholder.');
        }

        if (preg_match('~(?:https?://|ftp://|www\.|\b[a-z0-9-]+\.(?:com|org|net|gov|edu|us|io|co|info|app|dev)\b)~i', $text) === 1) {
            throw new InvalidArgumentException('Replies cannot contain web links. Members are pointed to a person, not a link.');
        }
        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $text) === 1) {
            throw new InvalidArgumentException('Replies cannot contain email addresses.');
        }
        if (preg_match_all('/\+?\d[\d\s().\-]{5,}\d/', $text, $runs) > 0) {
            foreach ($runs[0] as $run) {
                if (strlen((string) preg_replace('/\D/', '', $run)) >= 7) {
                    throw new InvalidArgumentException('Replies cannot contain phone numbers.');
                }
            }
        }

        return $text;
    }

    private function validateList(string $key, string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $terms = [];
        foreach ($lines as $line) {
            $term = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $line)));
            if ($term === '') {
                continue;
            }
            if (mb_strlen($term) > self::LIST_TERM_MAX_CHARS) {
                throw new InvalidArgumentException('"'.mb_substr($term, 0, 30).'..." is longer than '.self::LIST_TERM_MAX_CHARS.' characters. Put one short word or phrase on each line.');
            }
            $terms[$term] = true;
        }
        $terms = array_map('strval', array_keys($terms));

        if (count($terms) > self::LIST_MAX_LINES) {
            throw new InvalidArgumentException('The list has '.count($terms).' entries. Keep it to '.self::LIST_MAX_LINES.' or fewer.');
        }
        if ($terms === [] && in_array($key, self::NON_EMPTY_LISTS, true)) {
            throw new InvalidArgumentException('This list cannot be empty: it is what keeps safety-related items with staff.');
        }

        return implode("\n", $terms);
    }

    /** @param array<string, mixed> $definition */
    private function seedOne(string $key, string $kind, array $definition, string $body): int
    {
        if (AiAutomationPlaybook::query()->where('key', $key)->exists()) {
            return 0;
        }

        try {
            DB::transaction(function () use ($key, $kind, $definition, $body): void {
                $playbook = AiAutomationPlaybook::query()->create([
                    'key' => $key,
                    'kind' => $kind,
                    'label' => (string) ($definition['label'] ?? $key),
                    'help' => isset($definition['help']) ? (string) $definition['help'] : null,
                    'body' => $body,
                    'version' => 1,
                ]);
                AiAutomationPlaybookRevision::query()->create([
                    'playbook_id' => $playbook->getKey(),
                    'version' => 1,
                    'body' => $body,
                    'edited_by_user_id' => null,
                    'edited_by_name' => 'Repository defaults',
                    'created_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return 0; // another process seeded it first
        }

        return 1;
    }

    /** @return list<string> */
    private static function parseTerms(string $body): array
    {
        $terms = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $term = mb_strtolower(trim($line));
            if ($term !== '') {
                $terms[$term] = true;
            }
        }

        return array_map('strval', array_keys($terms));
    }

    private static function editorName(User $editor): string
    {
        $name = trim((string) ($editor->display_name ?: $editor->name));

        return mb_substr($name !== '' ? $name : 'Administrator', 0, 160);
    }
}
