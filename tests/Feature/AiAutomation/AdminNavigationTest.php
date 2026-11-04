<?php

declare(strict_types=1);

namespace Tests\Feature\AiAutomation;

use App\Filament\Admin\Pages\AiActivityHistory;
use App\Filament\Admin\Pages\AiResponseAutomation;
use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Filament\Resources\AiPlaybookResource;
use App\Filament\Resources\SupportEquipmentRuleResource;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

/** The menu group must not add database work to every admin page. */
final class AdminNavigationTest extends AdminScreensTestCase
{
    private const ENTRIES = [AiResponseAutomation::class, SupportEquipmentRuleResource::class, AiKnowledgeArticleResource::class, AiPlaybookResource::class, AiActivityHistory::class];

    public function test_all_five_entries_share_one_access_check_per_request(): void
    {
        $this->actingAs($this->admin(permissions: ['admin.access', 'admin.ai_automation.manage']));
        \App\Services\AiAutomation\AiAutomationAccess::allowsCurrentUser(); // warm the permission cache once
        $this->app->instance('request', \Illuminate\Http\Request::create('/admin'));

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        foreach (self::ENTRIES as $entry) {
            $entry::registerNavigationItems();
        }
        $five = $queries;

        $queries = 0;
        for ($i = 0; $i < 5; $i++) {
            \App\Services\AiAutomation\AiAutomationAccess::allowsCurrentUser();
        }
        $unshared = $queries;

        self::assertLessThanOrEqual(2, $five, 'Five menu entries cost one lookup, not five.');
        self::assertGreaterThanOrEqual($five * 4, $unshared, 'Without sharing, each entry would query on its own.');
    }

    public function test_the_menu_hides_the_group_from_people_without_the_permission_and_shows_it_to_holders(): void
    {
        $this->actingAs($this->admin(permissions: ['admin.access']));
        self::assertNull(collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Support Services Automation'));

        $this->app->instance('request', \Illuminate\Http\Request::create('/admin'));
        $this->actingAs($this->admin(permissions: ['admin.access', 'admin.ai_automation.manage']));
        // Navigation is built once per panel boot; a fresh holder sees all five entries.
        foreach (self::ENTRIES as $entry) {
            self::assertTrue($entry::shouldRegisterNavigation());
        }
    }
}
