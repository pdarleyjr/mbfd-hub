<?php

declare(strict_types=1);

namespace App\Services\SupportTriage;

use App\Models\SupportTriage\SupportEquipmentRule;

/**
 * One active equipment rule prepared for matching: the exact comparison keys (name and plain aliases) and the
 * word-prefix patterns (aliases ending in `*`), all in PhraseText's folded form.
 */
final readonly class CatalogEntry
{
    /**
     * @param  list<string>  $exactKeys
     * @param  list<list<string>>  $patterns  word lists; the last word is a prefix
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $level,
        public string $category,
        public string $handler,
        public ?string $scope,
        public ?string $memberResponse,
        public bool $isPpe,
        public bool $isCritical,
        public string $contact300,
        public array $exactKeys,
        public array $patterns,
    ) {}

    public static function fromRule(SupportEquipmentRule $rule): self
    {
        $exact = [PhraseText::key($rule->name)];
        $patterns = [];
        foreach ($rule->aliases ?? [] as $alias) {
            $alias = (string) $alias;
            $key = PhraseText::key($alias);
            if ($key === '') {
                continue;
            }
            if (PhraseText::isPattern($alias)) {
                $patterns[] = explode(' ', $key);
            } else {
                $exact[] = $key;
            }
        }

        return new self(
            id: (int) $rule->getKey(),
            name: (string) $rule->name,
            level: (string) $rule->level,
            category: (string) $rule->category,
            handler: (string) $rule->handler,
            scope: $rule->apparatus_scope !== null && $rule->apparatus_scope !== '' ? (string) $rule->apparatus_scope : null,
            memberResponse: filled($rule->member_response) ? (string) $rule->member_response : null,
            isPpe: (bool) $rule->is_ppe,
            isCritical: (bool) $rule->is_critical,
            contact300: (string) $rule->contact_300,
            exactKeys: array_values(array_unique(array_filter($exact, static fn (string $key): bool => $key !== ''))),
            patterns: $patterns,
        );
    }

    /** A protective-equipment rule at HIGH: the categories the owner never lets a keyword rule outrank. */
    public function isProtectiveHigh(): bool
    {
        return $this->level === EquipmentClassification::HIGH && ($this->isPpe || in_array($this->category, ['ppe', 'scba_unit', 'scba_facepiece'], true));
    }
}
