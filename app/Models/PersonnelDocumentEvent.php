<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history of a personnel document: upload, view, download, visibility change, new version. */
class PersonnelDocumentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['personnel_document_id', 'actor_user_id', 'action', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<PersonnelDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PersonnelDocument::class, 'personnel_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
