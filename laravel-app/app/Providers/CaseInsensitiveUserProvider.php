<?php

namespace App\Providers;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class CaseInsensitiveUserProvider extends EloquentUserProvider
{
    /**
     * Retrieve a user by the given credentials (case-insensitive email).
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (empty($credentials) ||
            (count($credentials) === 1 && array_key_exists('password', $credentials))) {
            return null;
        }

        // Build query with case-insensitive email lookup
        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            if ($key === 'password') {
                continue;
            }
            
            // Case-insensitive comparison for email
            if ($key === 'email') {
                $query->whereRaw('LOWER(email) = ?', [strtolower($value)]);
            } else {
                $query->where($key, $value);
            }
        }

        return $query->first();
    }
}
