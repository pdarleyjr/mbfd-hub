<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalNavItem extends Model
{
    protected $fillable = [
        'division',
        'label',
        'slug',
        'type',
        'url',
        'external_source_id',
        'baserow_workspace_id',
        'baserow_database_id',
        'baserow_table_id',
        'baserow_view_id',
        'allowed_roles',
        'allowed_permissions',
        'sort_order',
        'is_active',
        'open_in_new_tab',
        'created_by',
    ];

    protected $casts = [
        'allowed_roles' => 'array',
        'allowed_permissions' => 'array',
        'is_active' => 'boolean',
        'open_in_new_tab' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForDivision(Builder $query, string $division): Builder
    {
        return $query->where('division', $division);
    }

    public function scopeForUser(Builder $query, $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $userRoles = $user->getRoleNames()->toArray();
            foreach ($userRoles as $role) {
                $q->orWhereJsonContains('allowed_roles', $role);
            }
        });
    }
}
