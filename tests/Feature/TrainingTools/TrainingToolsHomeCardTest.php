<?php

declare(strict_types=1);

namespace Tests\Feature\TrainingTools;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The Training Tools card in Home Quick Access, and the two simulator links that moved out of Additional Links. */
final class TrainingToolsHomeCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_member_sees_one_collapsed_native_disclosure_named_training_tools(): void
    {
        $this->actingAsCanonicalFixture();

        $xpath = $this->xpath($this->get('/')->assertOk()->getContent());
        $cards = $xpath->query('//details[@data-training-tools]');
        self::assertSame(1, $cards->length);

        $card = $cards->item(0);
        self::assertFalse($card->hasAttribute('open'), 'Collapsed until the member opens it.');
        self::assertSame(1, $xpath->query('ancestor::section[@data-home-section="quick-access"]', $card)->length, 'Inside Quick Access.');
        self::assertSame(1, $xpath->query('ancestor::aside[@data-home-column="rail"]', $card)->length);
        self::assertSame(1, $xpath->query('./summary', $card)->length, 'Native <summary> carries keyboard and screen reader behavior.');
        self::assertSame('Training Tools', trim($xpath->query('./summary//*[contains(@class,"hub-tile__title")]', $card)->item(0)->textContent));
        self::assertSame(1, $xpath->query('./summary//*[contains(@class,"hub-additional__chevron")]', $card)->length);
        self::assertSame('Training Tools', $xpath->query('./ul[@class="hub-additional__list"]', $card)->item(0)->getAttribute('aria-label'));
        self::assertSame(1, $xpath->query('//*[normalize-space(text())="Training Tools"][contains(@class,"hub-tile__title")]')->length, 'One card, not two.');
    }

    public function test_the_card_has_exactly_three_links_in_order_with_the_right_targets(): void
    {
        $this->actingAsCanonicalFixture();

        $xpath = $this->xpath($this->get('/')->assertOk()->getContent());
        $actual = [];
        foreach ($xpath->query('//details[@data-training-tools]//a') as $link) {
            $actual[] = [
                trim($xpath->query('.//*[contains(@class,"hub-tile__title")]', $link)->item(0)->textContent),
                $link->getAttribute('href'),
                $link->getAttribute('target'),
                $link->getAttribute('rel'),
                $link->hasAttribute('data-important-target'),
            ];
        }

        self::assertSame([
            ['Command Board Sandbox', route('training.command-board'), '', '', true],
            ['Vehicle Placement Simulator', 'https://firesim.mbfdhub.com', '_blank', 'noopener noreferrer', true],
            ['Pump Panel', 'https://pump-panel.pages.dev/', '_blank', 'noopener noreferrer', true],
        ], $actual);
        self::assertSame('/training-tools/command-board', parse_url(route('training.command-board'), PHP_URL_PATH));

        $links = $xpath->query('//details[@data-training-tools]//a');
        self::assertStringNotContainsString('(opens in a new tab)', $links->item(0)->textContent, 'The sandbox opens in this window.');
        self::assertStringContainsString('(opens in a new tab)', $links->item(1)->textContent);
        self::assertStringContainsString('(opens in a new tab)', $links->item(2)->textContent);
    }

    public function test_the_card_sits_before_additional_links_in_the_quick_access_rail(): void
    {
        $user = $this->actingAsCanonicalFixture();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo(Permission::findOrCreate('admin.workgroups.view', 'web'));

        $xpath = $this->xpath($this->get('/')->assertOk()->getContent());
        $order = [];
        foreach ($xpath->query('//aside[@data-home-column="rail"]//details') as $details) {
            $order[] = $details->hasAttribute('data-training-tools') ? 'training-tools' : ($details->hasAttribute('data-additional-links') ? 'additional-links' : 'other');
        }

        self::assertSame(['training-tools', 'additional-links'], array_values(array_diff($order, ['other'])));
    }

    public function test_the_card_needs_no_permission_an_account_without_a_personnel_profile_sees_it(): void
    {
        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]));

        $xpath = $this->xpath($this->get('/')->assertOk()->getContent());

        self::assertSame(3, $xpath->query('//details[@data-training-tools]//a')->length);
    }

    public function test_additional_links_no_longer_carry_the_simulators_but_keep_the_rest_with_their_rules(): void
    {
        $entitled = $this->actingAsCanonicalFixture('E01-TT-ADMIN');
        $entitled->assignRole(Role::findOrCreate('admin', 'web'));
        $entitled->givePermissionTo([
            Permission::findOrCreate('app.media_control.access', 'web'),
            Permission::findOrCreate('admin.workgroups.view', 'web'),
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.fleet.manage', 'web'),
        ]);
        config(['services.media_control.authorization.service_token' => 'test-media-control-token']);

        $all = $this->additionalTitles($this->get('/')->assertOk()->getContent());
        self::assertSame(['Fleet operations', 'Workgroup Dashboard', 'Videos', 'Media Control'], $all);

        $this->actingAsCanonicalFixture('E01-TT-PLAIN');
        $plain = $this->additionalTitles($this->get('/')->assertOk()->getContent());
        self::assertSame(['Videos'], $plain, 'Fleet, Workgroups and Media Control keep their own entitlement.');
    }

    public function test_each_simulator_appears_once_on_home_and_nowhere_else_in_the_rail(): void
    {
        $this->actingAsCanonicalFixture();

        $html = (string) $this->get('/')->assertOk()->getContent();

        self::assertSame(1, substr_count($html, 'href="https://firesim.mbfdhub.com"'));
        self::assertSame(1, substr_count($html, 'href="https://pump-panel.pages.dev/"'));
        self::assertSame(1, substr_count($html, 'Vehicle Placement Simulator</span>'));
        self::assertSame(1, substr_count($html, 'Pump Panel</span>'));
        $xpath = $this->xpath($html);
        self::assertSame(0, $xpath->query('//details[@data-additional-links]//a[contains(@href,"firesim") or contains(@href,"pump-panel")]')->length);
    }

    /** @return list<string> */
    private function additionalTitles(string $html): array
    {
        $xpath = $this->xpath($html);
        $titles = [];
        foreach ($xpath->query('//details[@data-additional-links]//a[@data-additional-link]') as $link) {
            $titles[] = trim($xpath->query('.//*[contains(@class,"hub-tile__title")]', $link)->item(0)->textContent);
        }

        return $titles;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}
