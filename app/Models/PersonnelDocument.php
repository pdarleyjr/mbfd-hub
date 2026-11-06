<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One stored personnel file. Employee profiles, My Documents, vehicle history and Support Services messages all
 * point at this row; the physical file exists once. Use PersonnelDocumentService to create, read or retire one.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $employee_id
 * @property string $title
 * @property string $category
 * @property string|null $description
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property bool $visible_to_employee
 * @property string $access_level
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int $version
 * @property int|null $supersedes_id
 * @property int|null $uploaded_by_user_id
 */
class PersonnelDocument extends Model
{
    use HasUlids;
    use SoftDeletes;

    /** Readable for anyone with personnel document access. */
    public const ACCESS_PERSONNEL = 'personnel';

    /** A memorandum sent to Support Services: the member and the Support Services inbox only. */
    public const ACCESS_SUPPORT_MESSAGE = 'support_message';

    /** Accident reports: medical, injury and third-party details. Needs the accident permission. */
    public const ACCESS_ACCIDENT = 'accident';

    public const CATEGORIES = [
        'personnel_file' => 'Personnel file',
        'certification' => 'Certification / license',
        'training' => 'Training record',
        'evaluation' => 'Evaluation',
        'correspondence' => 'Correspondence',
        'accident_report' => 'Accident report',
        'memorandum' => 'Memorandum',
        'medical' => 'Medical / fitness',
        'other' => 'Other',
    ];

    protected $fillable = [
        'employee_id', 'title', 'category', 'description', 'storage_disk', 'storage_path', 'original_name',
        'mime_type', 'size_bytes', 'sha256', 'visible_to_employee', 'access_level', 'source_type', 'source_id',
        'version', 'supersedes_id', 'uploaded_by_user_id',
    ];

    protected $hidden = ['storage_disk', 'storage_path'];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'size_bytes' => 'integer',
            'visible_to_employee' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<self, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasMany<PersonnelDocumentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PersonnelDocumentEvent::class);
    }

    /**
     * Documents an employee may see in their own My Documents list.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId)->where('visible_to_employee', true);
    }

    /**
     * Latest version only: a superseded row stays for history but is not listed twice.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub): void {
            $sub->selectRaw('1')->from('personnel_documents as newer')
                ->whereColumn('newer.supersedes_id', 'personnel_documents.id')
                ->whereNull('newer.deleted_at');
        });
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst(str_replace('_', ' ', $this->category));
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function readableSize(): string
    {
        $bytes = $this->size_bytes;

        return match (true) {
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024).' KB',
            default => $bytes.' bytes',
        };
    }
}
