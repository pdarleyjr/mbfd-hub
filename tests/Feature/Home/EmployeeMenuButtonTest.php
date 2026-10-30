<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The Employee Portal top bar opens its drawer from a labelled "Menu" button, not a bare icon. */
final class EmployeeMenuButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_employee_panel_menu_button_has_a_visible_label_and_correct_aria(): void
    {
        $this->withoutVite();
        $this->actingAsCanonicalFixture();

        $xpath = $this->xpath($this->get('/employee/dashboard')->assertOk()->getContent());
        $buttons = $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " fi-topbar-open-sidebar-btn ")]');
        self::assertSame(1, $buttons->length);

        $button = $buttons->item(0);
        self::assertSame('button', $button->getAttribute('type'));
        self::assertSame('Open menu', $button->getAttribute('aria-label'), 'The accessible name contains the visible word "Menu".');
        self::assertSame('false', $button->getAttribute('aria-expanded'));
        self::assertTrue($button->hasAttribute('data-hub-menu-button'));
        self::assertStringContainsString('hub-menu-btn', $button->getAttribute('class'));
        self::assertStringContainsString('lg:hidden', $button->getAttribute('class'), 'Wide screens keep the permanent sidebar.');

        $label = $xpath->query('.//*[@data-menu-label]', $button);
        self::assertSame(1, $label->length);
        self::assertSame('Menu', trim($label->item(0)->textContent));
        self::assertSame('true', $xpath->query('.//svg', $button)->item(0)->getAttribute('aria-hidden'));

        // aria-controls names a real element: the navigation drawer.
        $controls = $button->getAttribute('aria-controls');
        self::assertSame('hub-panel-sidebar', $controls);
        $target = $xpath->query('//*[@id="'.$controls.'"]');
        self::assertSame(1, $target->length);
        self::assertSame('aside', $target->item(0)->nodeName);
        self::assertStringContainsString('fi-sidebar', $target->item(0)->getAttribute('class'));
    }

    public function test_other_panels_keep_filaments_own_icon_button(): void
    {
        $this->withoutVite();
        $employee = Employee::query()->create([
            'employee_id' => 'E-MENU-ADMIN', 'name' => 'Menu Admin', 'rank' => 'Captain',
            'password' => 'not-used-by-tests', 'must_change_password' => false,
        ]);
        $admin = User::factory()->create([
            'account_status' => AccountStatus::Active, 'must_change_password' => false,
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
        ]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAsCanonicalUser($admin->fresh());

        $html = $this->get('/admin')->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $buttons = $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " fi-topbar-open-sidebar-btn ")]');
        self::assertSame(1, $buttons->length);
        self::assertSame(0, $xpath->query('//*[@data-hub-menu-button]')->length);
        self::assertStringNotContainsString('hub-menu-btn', $buttons->item(0)->getAttribute('class'));
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
