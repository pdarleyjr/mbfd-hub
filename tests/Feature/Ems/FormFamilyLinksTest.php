<?php

declare(strict_types=1);

namespace Tests\Feature\Ems;

use App\Models\User;
use App\Support\Ems\EmsLinks;
use App\Support\Home\HomeQuickActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/** Where the four forms are offered: the Forms library cards, the Home Rescue Forms links and the member links. */
final class FormFamilyLinksTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private const TITLES = [
        'als-transport-inventory' => 'ALS Transport Inventory Form',
        'als-non-transport-inventory' => 'ALS Non Transport Inventory Form',
        'fire-boat-6-medical-inventory' => 'Fire Boat 6 Medical Inventory Form',
        'bls-detail-medical-inventory' => 'BLS Detail Medical Inventory Form',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    public function test_the_forms_library_offers_four_cards_in_order_then_the_other_cards(): void
    {
        $html = $this->actingAs($this->emsMember())->get('/employee/forms')->assertOk()->getContent();
        $this->assertSame(1, preg_match("/data-bootstrap='([^']*)'/", $html, $matches));
        $cards = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR)['library_links'];

        $this->assertSame([...array_keys(self::TITLES), 'take-home-vehicle-inspection'], array_slice(array_column($cards, 'key'), 0, 5));
        foreach (array_slice($cards, 0, 4) as $card) {
            $this->assertSame(self::TITLES[$card['key']], $card['title']);
            $this->assertSame('Open form', $card['action_label']);
            $this->assertTrue($card['in_app']);
            $this->assertSame(url('/employee/forms?form='.$card['key']), $card['href']);
            $this->assertSame(['label' => 'My medical forms', 'href' => url(EmsLinks::memberRequests())], $card['secondary']);
            $this->assertNotSame('', $card['description']);
        }
        $this->assertSame('Airway Bag, ALS Med Bag or Box, ALS Transport Vehicle and Handtevy. Request only what you need.', $cards[0]['description']);
    }

    public function test_home_links_are_the_four_forms_in_order_with_the_same_titles_and_keys(): void
    {
        $links = HomeQuickActions::rescueForms($this->emsMember());

        $forms = array_slice($links, 0, 4);
        $this->assertSame(array_keys(self::TITLES), array_column($forms, 'key'));
        $this->assertSame(array_values(self::TITLES), array_column($forms, 'label'));
        foreach ($forms as $link) {
            $this->assertSame(url('/employee/forms?form='.$link['key']), $link['href']);
        }
        $this->assertSame('vehicle-inspection', $links[array_key_last($links)]['key']);
        $this->assertNotContains('medical-inventory', array_column($links, 'key'), 'The single Medical Inventory Forms link is gone.');
    }

    public function test_home_offers_nothing_to_an_account_with_no_personnel_profile(): void
    {
        $this->assertSame([], HomeQuickActions::rescueForms(User::factory()->create()));
        $this->assertSame([], HomeQuickActions::rescueForms(null));
    }

    public function test_the_original_links_still_work(): void
    {
        $this->assertSame('/employee/forms?form=medical-inventory-request', EmsLinks::memberForm());
        $this->assertSame('/employee/forms?form=medical-inventory-request', EmsLinks::MEMBER_FORMS.'?form='.EmsLinks::MEMBER_FORM_KEY);
        $this->assertSame('/employee/forms?form=medical-inventory-request&view=mine', EmsLinks::memberRequests());
        foreach (['als-transport' => 'als-transport-inventory', 'als-non-transport' => 'als-non-transport-inventory', 'fire-boat-6' => 'fire-boat-6-medical-inventory', 'bls-detail' => 'bls-detail-medical-inventory'] as $family => $formKey) {
            $this->assertSame('/employee/forms?form='.$formKey, EmsLinks::memberForm($family));
        }
    }
}
