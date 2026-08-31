<?php

declare(strict_types=1);

namespace App\Services\SnipeIdentity;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SnipeApiIdentityDirectory
{
    /** @return list<array{id: int, employee_num: ?string, username: ?string, email: ?string, name: ?string}> */
    public function users(): array
    {
        $baseUrl = config('services.snipeit.url');
        $token = config('services.snipeit.token');

        if (! is_string($baseUrl) || $baseUrl === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Snipe-IT preview requires configured API URL and token.');
        }

        $users = [];
        $offset = 0;
        $limit = 500;

        do {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->get(rtrim($baseUrl, '/').'/users', [
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Snipe-IT identity preview could not read the user directory.');
            }

            $rows = $response->json('rows', []);
            if (! is_array($rows)) {
                throw new RuntimeException('Snipe-IT identity preview received an invalid user directory response.');
            }

            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['id']) || ! is_numeric($row['id'])) {
                    continue;
                }

                $users[] = [
                    'id' => (int) $row['id'],
                    'employee_num' => $this->stringOrNull($row['employee_num'] ?? null),
                    'username' => $this->stringOrNull($row['username'] ?? null),
                    'email' => $this->stringOrNull($row['email'] ?? null),
                    'name' => $this->stringOrNull($row['name'] ?? null),
                ];
            }

            $offset += count($rows);
            $total = $response->json('total');
        } while (is_numeric($total) && $offset < (int) $total && $rows !== []);

        return $users;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
