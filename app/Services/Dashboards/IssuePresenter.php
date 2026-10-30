<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Support\Dashboards\DashboardImages;
use App\Support\Dashboards\DashboardIssue;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Plain view data for one dashboard row. Every value is text; Blade escapes it on output. */
final class IssuePresenter
{
    public const THUMB_SIZE = 88;

    /**
     * @return array{ref: string, subject: ?string, title: string, summary: ?string, severity: string, severityLabel: string,
     *     stage: string, statusLabel: string, time: string, timeIso: string, actor: ?string, initials: ?string,
     *     image: ?string, image2x: ?string, imageKind: ?string, alt: string, icon: string, cta: string, lane: string}
     */
    public static function row(DashboardIssue $issue, string $lane): array
    {
        $image = DashboardImages::url($issue->imageKey, self::THUMB_SIZE);

        return [
            'ref' => $issue->ref(),
            'subject' => $issue->subject,
            'title' => $issue->title,
            'summary' => $issue->categoryLabel !== null && $issue->summary !== null
                ? $issue->categoryLabel.' · '.$issue->summary
                : ($issue->categoryLabel ?? $issue->summary),
            'severity' => $issue->severity,
            'severityLabel' => self::severityLabel($issue->severity),
            'stage' => $issue->stage,
            'statusLabel' => $issue->statusLabel,
            'time' => self::time($issue->occurredAt),
            'timeIso' => $issue->occurredAt->toIso8601String(),
            'actor' => $issue->actorLabel,
            'initials' => self::initials($issue->actorLabel),
            'image' => $image,
            'image2x' => $image === null ? null : DashboardImages::url($issue->imageKey, self::THUMB_SIZE * 2),
            'imageKind' => $image === null ? null : DashboardImages::kind($issue->imageKey),
            'alt' => $issue->subject ?? $issue->title,
            'icon' => self::fallbackIcon($issue->imageKey, $lane),
            'cta' => self::cta($issue->stage),
            'lane' => $lane,
        ];
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
            default => ucfirst($severity),
        };
    }

    /** Today: "6:12 AM"; yesterday: "Yesterday, 4:18 PM"; earlier: "Oct 28, 6:11 PM" (America/New_York). */
    public static function time(DateTimeInterface $at, ?DateTimeInterface $now = null): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone(OperationalDisplayWindow::TIMEZONE);
        $today = ($now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now))
            ->setTimezone(OperationalDisplayWindow::TIMEZONE)->startOfDay();

        return match (true) {
            $local->isSameDay($today) => $local->format('g:i A'),
            $local->isSameDay($today->subDay()) => 'Yesterday, '.$local->format('g:i A'),
            $local->year === $today->year => $local->format('M j, g:i A'),
            default => $local->format('M j, Y, g:i A'),
        };
    }

    public static function cta(string $stage): string
    {
        return match ($stage) {
            'awaiting_response' => 'Review',
            'in_progress' => 'Update',
            'resolved' => 'View',
            default => 'Open',
        };
    }

    public static function initials(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\s]/u', ' ', $name))) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));
        if ($words === []) {
            return null;
        }

        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    private static function fallbackIcon(?string $imageKey, string $lane): string
    {
        return match (true) {
            str_starts_with((string) $imageKey, 'apparatus:'), $lane === DashboardSnapshot::LANE_VEHICLE => 'heroicon-o-truck',
            str_starts_with((string) $imageKey, 'station:'), $lane === DashboardSnapshot::LANE_STATION => 'heroicon-o-building-office',
            default => 'heroicon-o-chat-bubble-left-right',
        };
    }
}
