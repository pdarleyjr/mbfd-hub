<?php

declare(strict_types=1);

namespace App\Services\HubSupport;

use App\Enums\HubSupportTicketCategory;
use App\Enums\HubSupportTicketImpact;

final class HubSupportClassifier
{
    /** @return array{category: HubSupportTicketCategory, impact: HubSupportTicketImpact, affected_component: string, generated_title: string} */
    public function classify(string $description, ?string $path, array $diagnostics): array
    {
        $path = strtolower($path ?? '');
        $words = mb_strtolower($description);
        $events = $diagnostics['events'] ?? [];
        $failedRequiredPost = false;
        $serverFailures = 0;
        foreach (is_array($events) ? $events : [] as $event) {
            if (! is_array($event) || ($event['type'] ?? null) !== 'request') {
                continue;
            }
            if (($event['status'] ?? 0) >= 500) {
                $serverFailures++;
                if (($event['method'] ?? '') === 'POST') {
                    $failedRequiredPost = true;
                }
            }
        }

        [$category, $component] = match (true) {
            str_contains($path, 'media-control') => [HubSupportTicketCategory::MediaControlOrIntegration, 'media_control'],
            str_contains($path, 'notification') || str_contains($words, 'notification') || str_contains($words, 'push alert') => [HubSupportTicketCategory::Notification, 'notifications'],
            str_contains($path, '/account') || str_contains($path, '/login') || str_contains($words, 'sign in') => [HubSupportTicketCategory::AccountOrAccess, 'identity'],
            str_contains($path, '/forms') => [HubSupportTicketCategory::FormOrWorkflow, 'operational_forms'],
            str_contains($path, '/daily') => [HubSupportTicketCategory::FormOrWorkflow, 'daily_checkout'],
            str_contains($path, 'personnel-request') => [HubSupportTicketCategory::FormOrWorkflow, 'personnel_requests'],
            str_contains($path, 'video-conferencing') => [HubSupportTicketCategory::Other, 'video_conferencing'],
            str_contains($path, '/workgroup') => [HubSupportTicketCategory::Other, 'workgroups'],
            str_contains($path, '/training') => [HubSupportTicketCategory::Other, 'training'],
            str_contains($path, '/pump-simulator') => [HubSupportTicketCategory::Other, 'pump_simulator'],
            str_contains($path, '/admin') => [HubSupportTicketCategory::Other, 'admin'],
            $path === '/' => [HubSupportTicketCategory::Other, 'homepage'],
            default => [HubSupportTicketCategory::Other, 'unknown'],
        };

        $impact = match (true) {
            $serverFailures >= 3 || preg_match('/\b(?:entire|whole) (?:feature|system) (?:is )?(?:down|unavailable)|(?:feature|page|display) (?:will not|won\'t|cannot) load\b/u', $words) === 1 => HubSupportTicketImpact::FeatureUnavailable,
            $failedRequiredPost || preg_match('/\b(?:can\'t|cannot|won\'t|will not|unable to) (?:submit|save|upload|sign in)\b/u', $words) === 1 => HubSupportTicketImpact::TaskBlocking,
            default => HubSupportTicketImpact::Minor,
        };

        $title = trim((string) preg_split('/[.!?\r\n]/u', $description, 2)[0]);
        $title = mb_strimwidth($title !== '' ? $title : 'Issue reported', 0, 120, '…');

        return [
            'category' => $category,
            'impact' => $impact,
            'affected_component' => $component,
            'generated_title' => $title,
        ];
    }
}
