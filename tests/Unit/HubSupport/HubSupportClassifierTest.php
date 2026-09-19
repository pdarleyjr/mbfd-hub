<?php

declare(strict_types=1);

namespace Tests\Unit\HubSupport;

use App\Services\HubSupport\HubSupportClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HubSupportClassifierTest extends TestCase
{
    #[DataProvider('classificationCases')]
    public function test_it_classifies_from_context_without_member_selected_fields(
        string $description,
        string $path,
        array $diagnostics,
        string $category,
        string $impact,
        string $component,
    ): void {
        $result = (new HubSupportClassifier)->classify($description, $path, $diagnostics);

        self::assertSame($category, $result['category']->value);
        self::assertSame($impact, $result['impact']->value);
        self::assertSame($component, $result['affected_component']);
        self::assertNotSame('', $result['generated_title']);
    }

    public static function classificationCases(): iterable
    {
        yield 'failed operational form submission' => [
            "I can't submit the form.",
            '/employee/forms/froc/123',
            ['events' => [['type' => 'request', 'method' => 'POST', 'path' => '/employee/forms/api/records', 'status' => 500]]],
            'form_or_workflow',
            'task_blocking',
            'operational_forms',
        ];

        yield 'account access' => ['I cannot sign in to my account.', '/account', [], 'account_or_access', 'task_blocking', 'identity'];
        yield 'notification problem' => ['Push alerts are not appearing.', '/admin/notification-settings', [], 'notification', 'minor', 'notifications'];
        yield 'media control' => ['The classroom display will not load.', '/media-control', [], 'media_control_or_integration', 'feature_unavailable', 'media_control'];
        yield 'daily checkout' => ['A checklist item looks wrong.', '/daily-checkout', [], 'form_or_workflow', 'minor', 'daily_checkout'];
        yield 'workgroups' => ['The comparison is missing.', '/workgroups/data-dashboard', [], 'other', 'minor', 'workgroups'];
        yield 'video conferencing' => ['I cannot hear the call.', '/employee/video-conferencing/command', [], 'other', 'minor', 'video_conferencing'];
        yield 'training' => ['My course is missing.', '/training', [], 'other', 'minor', 'training'];
        yield 'unknown context' => ['Something looks odd.', '/somewhere-new', [], 'other', 'minor', 'unknown'];
    }
}
