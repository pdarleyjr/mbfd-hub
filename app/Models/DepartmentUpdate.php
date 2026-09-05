<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepartmentUpdateAudience;
use App\Enums\DepartmentUpdateCategory;
use App\Enums\DepartmentUpdatePriority;
use App\Enums\DepartmentUpdateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 * @property DepartmentUpdateCategory $category
 * @property DepartmentUpdatePriority $priority
 * @property DepartmentUpdateStatus $status
 * @property DepartmentUpdateAudience $audience
 * @property bool $is_pinned
 * @property bool $send_in_app
 * @property bool $send_web_push
 * @property array<int, int>|null $audience_user_ids
 * @property \Carbon\CarbonImmutable|null $publish_at
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable|null $notification_sent_at
 * @property \Carbon\CarbonImmutable|null $notification_prepared_at
 * @property \Carbon\CarbonImmutable|null $first_published_at
 * @property string|null $cta_label
 * @property string|null $cta_url
 * @property string|null $image_path
 * @property string|null $image_name
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property int|null $author_id
 * @property-read User|null $author
 */
class DepartmentUpdate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'body',
        'category',
        'priority',
        'status',
        'is_pinned',
        'publish_at',
        'expires_at',
        'cta_label',
        'cta_url',
        'image_path',
        'image_name',
        'attachment_path',
        'attachment_name',
        'author_id',
        'send_in_app',
        'send_web_push',
        'audience',
        'audience_user_ids',
        'notification_sent_at',
        'notification_prepared_at',
        'first_published_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => DepartmentUpdateCategory::class,
            'priority' => DepartmentUpdatePriority::class,
            'status' => DepartmentUpdateStatus::class,
            'audience' => DepartmentUpdateAudience::class,
            'is_pinned' => 'boolean',
            'send_in_app' => 'boolean',
            'send_web_push' => 'boolean',
            'audience_user_ids' => 'array',
            'publish_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'notification_sent_at' => 'immutable_datetime',
            'notification_prepared_at' => 'immutable_datetime',
            'first_published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<DepartmentUpdateNotificationDelivery, $this> */
    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(DepartmentUpdateNotificationDelivery::class);
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query
            ->where('status', DepartmentUpdateStatus::Published->value)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForHomepage(Builder $query): Builder
    {
        return $this->scopeCurrentlyActive($query)
            ->with('author:id,name')
            ->orderByDesc('is_pinned')
            ->orderByDesc('publish_at')
            ->limit(5);
    }

    public function scopePublishedArchive(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('status', DepartmentUpdateStatus::Published->value)
                        ->whereNotNull('publish_at')
                        ->where('publish_at', '<=', now());
                })->orWhere(function (Builder $query): void {
                    $query->where('status', DepartmentUpdateStatus::Archived->value)
                        ->whereNotNull('first_published_at')
                        ->where('first_published_at', '<=', now());
                });
            });
    }

    public function isPublishedHistory(): bool
    {
        if ($this->status === DepartmentUpdateStatus::Archived) {
            return $this->first_published_at !== null
                && $this->first_published_at->lessThanOrEqualTo(now());
        }

        return $this->status === DepartmentUpdateStatus::Published
            && $this->publish_at !== null
            && $this->publish_at->lessThanOrEqualTo(now());
    }

    public function canArchiveAsPublishedHistory(): bool
    {
        return $this->status === DepartmentUpdateStatus::Published
            && $this->publish_at !== null
            && $this->publish_at->lessThanOrEqualTo(now());
    }

    public function archiveAsPublishedHistory(): bool
    {
        if (! $this->canArchiveAsPublishedHistory()) {
            return false;
        }

        return $this->update([
            'status' => DepartmentUpdateStatus::Archived,
            'first_published_at' => $this->first_published_at ?? $this->publish_at,
        ]);
    }

    public function isActiveForNotificationDelivery(string $channel): bool
    {
        return $this->status === DepartmentUpdateStatus::Published
            && $this->publish_at !== null
            && $this->publish_at->lessThanOrEqualTo(now())
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && match ($channel) {
                'database' => $this->send_in_app,
                \NotificationChannels\WebPush\WebPushChannel::class => $this->send_web_push,
                default => false,
            };
    }

    public function isDueForNotification(): bool
    {
        return $this->status === DepartmentUpdateStatus::Published
            && $this->publish_at !== null
            && $this->publish_at->lessThanOrEqualTo(now())
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->send_in_app || $this->send_web_push)
            && $this->notification_prepared_at === null
            && $this->notification_sent_at === null;
    }

    public function excerpt(int $length = 180): string
    {
        return Str::of(strip_tags($this->body))->squish()->limit($length)->toString();
    }

    public function hasExternalCta(): bool
    {
        return $this->safeCtaUrl() !== null && preg_match('/^https?:\/\//i', $this->cta_url) === 1;
    }

    public function safeCtaUrl(): ?string
    {
        if (! is_string($this->cta_url) || $this->cta_url === '') {
            return null;
        }

        if (str_starts_with($this->cta_url, '/') && ! str_starts_with($this->cta_url, '//')) {
            return $this->cta_url;
        }

        return filter_var($this->cta_url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($this->cta_url, PHP_URL_SCHEME)), ['http', 'https'], true)
                ? $this->cta_url
                : null;
    }
}
