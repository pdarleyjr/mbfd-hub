<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Shared fixtures for table-export tests: an admin panel, a super admin and helpers to read downloads back. */
abstract class ExportTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }

    /** A signed-in admin who holds only the listed direct permissions. */
    protected function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(static fn (string $name): Permission => Permission::findOrCreate($name, 'web'), $permissions));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function downloadedBytes(Testable $component): string
    {
        $content = $component->effects['download']['content'] ?? null;
        self::assertIsString($content, 'The action did not return a file download.');

        return base64_decode($content, true) ?: '';
    }

    protected function workbook(Testable $component): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-export-');
        file_put_contents($path, $this->downloadedBytes($component));
        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return list<list<mixed>> Data sheet as a grid of raw values (dates stay as Excel serials). */
    protected function dataRows(Spreadsheet $book): array
    {
        return $book->getSheet(0)->toArray(null, false, false, false);
    }
}
