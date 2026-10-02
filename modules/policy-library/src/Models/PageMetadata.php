<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Models;

use Illuminate\Database\Eloquent\Model;

final class PageMetadata extends Model
{
    protected $table = 'policy_pages';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Revision page metadata is immutable. Create a new revision.'));
        self::deleting(fn () => throw new \LogicException('Revision page metadata cannot be deleted.'));
    }
}
