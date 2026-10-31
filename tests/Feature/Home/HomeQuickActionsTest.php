<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\User;
use App\Services\Security\Command300Access;
use App\Support\Ems\EmsLinks;
use App\Support\Home\HomeQuickActions;
use App\Support\Narcotics\NarcoticsLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Rescue Forms card and Command Board button on the member Home. */
final class HomeQuickActionsTest extends TestCase
{
    use RefreshDatabase;

    private const BOARD = 'https://cmd.mbfdhub.com';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.command_board.url' => self::BOARD, 'narcotics_checks.enabled' => true]);
        Permission::findOrCreate(Command300Access::PERMISSION, 'web');
    }

    // ---- Rescue Forms -----------------------------------------------------------------------------

    public function test_the_deep_links_are_the_ones_the_forms_page_understands(): void
    {
        self::assertSame('/employee/forms?form=medical-inventory-request', EmsLinks::memberForm());
        self::assertSame('/employee/forms?form=narcotics-check', NarcoticsLinks::memberForm());
        self::assertSame('/daily/vehicle-inspections', HomeQuickActions::VEHICLE_INSPECTION_PATH);
    }

    public function test_a_member_sees_one_gray_native_disclosure_with_exactly_three_links_in_order(): void
    {
        $this->signIn($this->member('Firefighter'));

        $xpath = $this->xpath($this->get('/')->assertOk()->getContent());
        $cards = $xpath->query('//details[@data-rescue-forms]');
        self::assertSame(1, $cards->length);

        $card = $cards->item(0);
        self::assertFalse($card->hasAttribute('open'), 'Collapsed until the member opens it.');
        self::assertStringContainsString('hub-tile--neutral', $card->getAttribute('class'));
        self::assertStringContainsString('hub-additional', $card->getAttribute('class'));

        $summary = $xpath->query('./summary', $card);
        self::assertSame(1, $summary->length, 'Native <summary> carries keyboard and ARIA behaviour.');
        self::assertSame('Rescue Forms', trim($xpath->query('.//*[contains(@class,"hub-tile__title")]', $summary->item(0))->item(0)->textContent));
        self::assertSame(1, $xpath->query('.//*[contains(@class,"hub-additional__chevron")]', $summary->item(0))->length);

        $links = $xpath->query('.//a[@data-rescue-form-link]', $card);
        $found = [];
        foreach ($links as $link) {
            $found[trim($link->textContent)] = $link->getAttribute('href');
        }
        self::assertSame([
            'Medical Inventory Forms' => url('/employee/forms?form=medical-inventory-request'),
            'Narcotics Check' => url('/employee/forms?form=narcotics-check'),
            'Vehicle Inspection' => url('/daily/vehicle-inspections'),
        ], $found);
        self::assertSame(0, $xpath->query('.//a[@target]', $card)->length, 'Same-site links open in place.');
    }

    public function test_the_card_is_not_a_standalone_quick_access_tile(): void
    {
        $this->signIn($this->member('Firefighter'));

        $xpath = $this->xpath($this->get('/')->assertOk()->getContent());
        self::assertSame(0, $xpath->query('//*[@data-rescue-forms and @data-quick-access-card]')->length);
        self::assertSame(1, $xpath->query('//details[@data-additional-links]')->length, 'Additional Links is untouched.');
    }

    public function test_narcotics_check_is_hidden_when_the_feature_is_switched_off(): void
    {
        config(['narcotics_checks.enabled' => false]);
        $this->signIn($this->member('Firefighter'));

        $this->assertRescueLinks(['Medical Inventory Forms', 'Vehicle Inspection']);
    }

    public function test_narcotics_check_is_hidden_while_the_member_must_change_their_password(): void
    {
        $this->signIn($this->member('Firefighter', mustChangePassword: true));

        $response = $this->get('/');
        if ($response->isRedirection()) {
            // The onboarding boundary keeps this account off Home entirely, which is stricter than hiding one link.
            self::assertNotSame('/', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

            return;
        }
        $this->assertRescueLinks(['Medical Inventory Forms', 'Vehicle Inspection'], $response->getContent());
    }

    public function test_an_account_without_a_personnel_profile_sees_no_card_at_all(): void
    {
        $user = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $this->signIn($user);

        $html = $this->get('/')->assertOk()->getContent();
        $xpath = $this->xpath($html);
        self::assertSame(0, $xpath->query('//*[@data-rescue-forms]')->length);
        self::assertStringNotContainsString('Rescue Forms', $html);
        self::assertSame([], HomeQuickActions::rescueForms($user));
        self::assertSame([], HomeQuickActions::rescueForms(null));
    }

    public function test_the_card_never_elevates_access_it_only_links(): void
    {
        $user = $this->member('Firefighter');
        $this->signIn($user);
        $this->get('/')->assertOk();

        self::assertFalse($user->fresh()->hasRole('super_admin'));
        self::assertCount(0, $user->fresh()->getAllPermissions());
        // The destinations still decide for themselves.
        self::assertSame(0, $this->xpath($this->get('/')->getContent())->query('//a[@data-rescue-form-link][contains(@href,"/admin")]')->length);
    }

    public function test_the_personalised_home_is_never_publicly_cacheable(): void
    {
        $this->signIn($this->member('Firefighter'));

        $cacheControl = (string) $this->get('/')->assertOk()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringNotContainsString('s-maxage', $cacheControl);
    }

    // ---- Command Board ----------------------------------------------------------------------------

    public function test_each_eligibility_path_sees_the_command_board_button_beside_vehicle_checkout(): void
    {
        $captain = $this->member('Captain');
        $chief = $this->member('Division Chief');
        $administrator = $this->member('Firefighter');
        $administrator->assignRole(Role::findOrCreate('super_admin', 'web'));
        $panelAdmin = $this->member('Lieutenant');
        $panelAdmin->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $granted = $this->member('Firefighter');
        $granted->givePermissionTo(Command300Access::PERMISSION);
        $assigned = $this->member('Lieutenant');
        $this->assignment($assigned, '300');

        $expected = [
            'captain' => [$captain, Command300Access::SOURCE_CAPTAIN],
            'division chief' => [$chief, Command300Access::SOURCE_DIVISION_CHIEF],
            'super_admin' => [$administrator, Command300Access::SOURCE_ADMINISTRATOR],
            'admin.access' => [$panelAdmin, Command300Access::SOURCE_ADMINISTRATOR],
            'explicit grant' => [$granted, Command300Access::SOURCE_GRANT],
            '300 assignment' => [$assigned, Command300Access::SOURCE_ASSIGNMENT],
        ];

        foreach ($expected as $path => [$user, $source]) {
            $user = $user->fresh();
            self::assertSame($source, app(Command300Access::class)->source($user), $path);
            $this->signIn($user);

            $xpath = $this->xpath($this->get('/')->assertOk()->getContent());
            $button = $xpath->query('//section[contains(@class,"home-today")]//a[@data-home-command-board]');
            self::assertSame(1, $button->length, $path.' sees the Command Board button.');
            self::assertSame(self::BOARD, $button->item(0)->getAttribute('href'), $path);
            self::assertStringStartsWith('Command Board', trim(preg_replace('/\s+/', ' ', $button->item(0)->textContent)));
            self::assertSame('noopener noreferrer', $button->item(0)->getAttribute('rel'));
            self::assertSame(
                url('/daily/vehicle-inspections'),
                $xpath->query('//section[contains(@class,"home-today")]//a[contains(@class,"hub-btn--primary")]')->item(0)->getAttribute('href'),
                'Vehicle Checkout is unchanged.',
            );
        }
    }

    public function test_ineligible_members_never_see_the_command_board_button(): void
    {
        $plain = $this->member('Firefighter');
        $lieutenant = $this->member('Lieutenant');
        $lookalike = $this->member('Captain 5');
        $cmdAppOnly = $this->member('Firefighter');
        $cmdAppOnly->givePermissionTo(Permission::findOrCreate('app.cmd.access', 'web'));
        $staleAssignment = $this->member('Firefighter');
        $this->assignment($staleAssignment, '300', ['superseded_at' => now()]);
        $otherUnit = $this->member('Firefighter');
        $this->assignment($otherUnit, '301');
        $mustChange = $this->member('Captain', mustChangePassword: true);

        foreach ([$plain, $lieutenant, $lookalike, $cmdAppOnly, $staleAssignment, $otherUnit] as $user) {
            $this->signIn($user->fresh());
            $html = $this->get('/')->assertOk()->getContent();
            self::assertSame(0, $this->xpath($html)->query('//*[@data-home-command-board]')->length, $user->employee_id);
            self::assertStringNotContainsString('Command Board', $html);
            self::assertStringNotContainsString('href="'.self::BOARD, $html);
        }

        self::assertNull(HomeQuickActions::commandBoardUrl($mustChange->fresh()));
        self::assertNull(HomeQuickActions::commandBoardUrl(null));
    }

    public function test_the_button_is_withheld_when_the_board_address_is_not_https(): void
    {
        config(['services.command_board.url' => 'http://cmd.mbfdhub.com']);
        $this->signIn($this->member('Captain'));

        self::assertSame(0, $this->xpath($this->get('/')->assertOk()->getContent())->query('//*[@data-home-command-board]')->length);
    }

    public function test_an_eligible_administrator_without_a_personnel_profile_still_gets_the_button_but_no_employee_actions(): void
    {
        $admin = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->signIn($admin);

        $xpath = $this->xpath($this->get('/')->assertOk()->getContent());
        self::assertSame(1, $xpath->query('//a[@data-home-command-board]')->length);
        self::assertSame(0, $xpath->query('//section[contains(@class,"home-today")]//a[contains(@class,"hub-btn--primary")]')->length);
    }

    public function test_an_ineligible_member_who_guesses_the_sign_in_address_is_turned_away(): void
    {
        $client = $this->commandBoardClient();
        $user = $this->member('Firefighter');
        $this->signIn($user);

        $response = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => self::BOARD.'/auth/callback', 'response_type' => 'code',
            'scope' => 'openid profile', 'state' => 'guess-state', 'nonce' => 'guess-nonce', 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='),
        ]))->assertRedirect();

        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith(self::BOARD.'/auth/callback?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $returned);
        self::assertSame('access_denied', $returned['error']);
        self::assertSame('guess-state', $returned['state']);
        self::assertArrayNotHasKey('code', $returned);
        $this->assertDatabaseCount('oidc_sessions', 0);
    }

    // ---- helpers ----------------------------------------------------------------------------------

    /** @param  list<string>  $labels */
    private function assertRescueLinks(array $labels, ?string $html = null): void
    {
        $html ??= $this->get('/')->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $found = [];
        foreach ($xpath->query('//details[@data-rescue-forms]//a[@data-rescue-form-link]') as $link) {
            $found[] = trim($link->textContent);
        }
        self::assertSame($labels, $found);
    }

    private function signIn(User $user): void
    {
        $this->actingAsCanonicalUser($user);
    }

    private function member(string $rank, bool $mustChangePassword = false): User
    {
        $this->sequence++;
        $employee = Employee::query()->create([
            'employee_id' => 'E-HOME-'.$this->sequence, 'name' => 'Home Member '.$this->sequence, 'rank' => $rank,
            'password' => 'not-used-by-tests', 'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => 'active', 'must_change_password' => $mustChangePassword,
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function assignment(User $user, string $unit, array $overrides = []): EmployeeBidAssignment
    {
        $this->sequence++;

        return EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $user->employee_profile_id, 'payload_version' => 2, 'bid_year' => 2026,
            'term_label' => '2026–2027', 'bid_session_id' => 'bid-2026', 'position_id' => 'P'.$this->sequence,
            'rank_label' => 'Division Chief', 'shift_label' => 'A Shift', 'station_label' => 'Station 2',
            'unit_label' => $unit, 'position_label' => 'Division Chief', 'bid_selection_label' => $unit,
            'assignment_type' => 'Assigned', 'assignment_source' => 'retained_nonbiddable', 'a_day_label' => 'A-Day',
            'idempotency_key' => 'key-'.$this->sequence, 'payload_hash' => str_repeat('a', 64), 'is_forced' => false,
            'source_sequence' => $this->sequence, 'source_result_hash' => str_repeat('b', 64), 'source_workbook_sha256' => str_repeat('c', 64),
            ...$overrides,
        ]);
    }

    private function commandBoardClient(): Client
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
        $client = Client::query()->create([
            'name' => 'MBFD Command Board', 'secret' => 'home-quick-actions-test-only', 'provider' => 'users',
            'redirect_uris' => [self::BOARD.'/auth/callback'], 'grant_types' => ['authorization_code'], 'revoked' => false,
        ]);
        config(['oidc.clients.cmd' => $client->id]);

        return $client;
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
