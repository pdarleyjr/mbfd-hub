<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Support\Ems\EmsLinks;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Every link a notification sends must open a real page. These paths were built by hand and answered
 * "Page not found" in production (2026-11-06 workflow audit).
 */
final class NotificationLinkTargetsTest extends TestCase
{
    private function assertRouteExists(string $path, string $expectedRouteName): void
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (NotFoundHttpException) {
            self::fail("No page answers {$path}");
        }
        self::assertSame($expectedRouteName, $route->getName(), $path);
    }

    public function test_member_reply_links_open_the_conversation_page(): void
    {
        $this->assertRouteExists('/employee/my-conversations/station_request/12', 'filament.employee.pages.my-conversations.{type}.{id}');
    }

    public function test_ems_staff_links_open_the_rescue_inventory_record(): void
    {
        $uuid = '0f4b7c3e-1a2b-4c3d-8e9f-0a1b2c3d4e5f';
        self::assertSame('/admin/rescue-inventory/'.$uuid, EmsLinks::admin($uuid));
        $this->assertRouteExists(EmsLinks::admin($uuid), 'filament.admin.resources.rescue-inventory.view');
    }

    public function test_evaluation_submission_alerts_open_the_submission(): void
    {
        $this->assertRouteExists('/admin/workgroup/evaluation-submissions/7', 'filament.admin.resources.workgroup.evaluation-submissions.view');
    }
}
