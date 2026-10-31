<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Reads recipient lists the way RFC 5322 writes them: "Doe, John" <j@example.test>, comments, groups,
 * bare addresses, and the {name, address} shape a parser returns. A comma inside a quoted name is not a
 * separator. (The reference project split on every comma and dropped or mangled such names.)
 */
final class AddressParser
{
    /**
     * @return list<array{address: string, name: ?string}> unique by lower-cased address, first spelling of the name wins
     */
    public function parse(mixed $value): array
    {
        $found = [];
        foreach ($this->tokens($value) as $entry) {
            $key = $entry['address'];
            if (! isset($found[$key])) {
                $found[$key] = $entry;
            } elseif ($found[$key]['name'] === null && $entry['name'] !== null) {
                $found[$key]['name'] = $entry['name'];
            }
        }

        return array_values($found);
    }

    /** @return list<string> */
    public function addresses(mixed $value): array
    {
        return array_column($this->parse($value), 'address');
    }

    /** @return list<array{address: string, name: ?string}> */
    private function tokens(mixed $value): array
    {
        if (is_array($value)) {
            if (isset($value['address']) && is_string($value['address'])) {
                $entry = $this->entry($value['address'], is_string($value['name'] ?? null) ? $value['name'] : null);

                return $entry === null ? [] : [$entry];
            }
            $all = [];
            foreach ($value as $item) {
                array_push($all, ...$this->tokens($item));
            }

            return $all;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $all = [];
        foreach ($this->split($value) as $part) {
            $entry = $this->fromMailbox($part);
            if ($entry !== null) {
                $all[] = $entry;
            }
        }

        return $all;
    }

    /** @return list<string> top-level pieces, split on , or ; outside quotes, angle brackets and comments */
    private function split(string $value): array
    {
        $parts = [];
        $buffer = '';
        $quoted = false;
        $angle = false;
        $comment = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($quoted) {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $value[++$i];
                } elseif ($char === '"') {
                    $quoted = false;
                }

                continue;
            }
            if ($comment > 0) {
                // Comments are dropped: "(Ops desk)" is not part of a name or an address.
                if ($char === '\\' && $i + 1 < $length) {
                    $i++;
                } elseif ($char === '(') {
                    $comment++;
                } elseif ($char === ')') {
                    $comment--;
                }

                continue;
            }
            if ($char === '"') {
                $quoted = true;
                $buffer .= $char;
            } elseif ($char === '(') {
                $comment = 1;
            } elseif ($char === '<') {
                $angle = true;
                $buffer .= $char;
            } elseif ($char === '>') {
                $angle = false;
                $buffer .= $char;
            } elseif (($char === ',' || $char === ';') && ! $angle) {
                $parts[] = $buffer;
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
        $parts[] = $buffer;

        return array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
    }

    /** @return array{address: string, name: ?string}|null */
    private function fromMailbox(string $part): ?array
    {
        // Group syntax: "Duty Officers: a@x.test" leaves the first member; the rest were split above.
        if (preg_match('/^[^<>@"]*:\s*(.*)$/s', $part, $group) === 1 && $group[1] !== '') {
            $part = trim($group[1]);
        }
        if (preg_match('/^(.*)<([^<>]*)>\s*$/s', $part, $angle) === 1) {
            return $this->entry($angle[2], $this->displayName($angle[1]));
        }

        return $this->entry($part, null);
    }

    private function displayName(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/s', $raw, $quoted) === 1) {
            $raw = (string) preg_replace('/\\\\(.)/s', '$1', $quoted[1]);
        }
        $raw = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw));

        return $raw === '' ? null : mb_substr($raw, 0, 255);
    }

    /** @return array{address: string, name: ?string}|null */
    private function entry(string $address, ?string $name): ?array
    {
        $address = strtolower(trim($address));
        if ($address === '' || preg_match('/[\x00-\x20\x7F<>,;"()\\\\]/', $address) === 1
            || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return ['address' => $address, 'name' => $name === null ? null : $this->displayName($name)];
    }
}
