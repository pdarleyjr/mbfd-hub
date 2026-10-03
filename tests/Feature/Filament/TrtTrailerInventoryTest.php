<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\TrtTrailerInventory;
use App\Models\TrtInventoryCatalogItem;
use App\Models\TrtInventoryEntry;
use App\Models\TrtInventorySession;
use App\Models\User;
use App\Services\OperationalEvidenceArchiveService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrtTrailerInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_historical_photo_is_reported_without_requesting_a_broken_url(): void
    {
        Storage::fake('public');
        Role::create(['name' => 'logistics_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');
        $admin->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.equipment.view', 'web'),
        ]);
        $session = TrtInventorySession::query()->create(['session_date' => today()]);
        $catalogItem = TrtInventoryCatalogItem::query()->create([
            'name' => 'Audit Rescue Tool',
            'category' => 'Audit',
            'expected_quantity' => 1,
            'active' => true,
        ]);
        TrtInventoryEntry::query()->create([
            'session_id' => $session->id,
            'catalog_item_id' => $catalogItem->id,
            'present' => true,
            'image_path' => 'trt-inventory/images/missing-audit-photo.jpg',
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(TrtTrailerInventory::class)
            ->assertSee('Missing photo file')
            ->assertDontSee('/storage/trt-inventory/images/missing-audit-photo.jpg');
    }

    public function test_member_submission_reaches_admin_detail_and_remains_discoverable_after_archive_restore(): void
    {
        Storage::fake('public');
        $this->travelTo(now()->subDay());
        $member = $this->actingAsCanonicalFixture('QA-TRT-ACTOR', '[QA TEST] TRT Actor');
        $catalogItem = TrtInventoryCatalogItem::create([
            'name' => '[QA TEST] Rescue Tool', 'category' => 'QA', 'expected_quantity' => 2, 'active' => true,
        ]);
        $response = $this->postJson('/api/public/trt-inventory/submit', [
            'entries' => [[
                'catalog_item_id' => $catalogItem->id,
                'present' => true,
                'actual_quantity' => 2,
                'condition' => 'good',
                'action' => 'keep',
                'image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOioAAAAASUVORK5CYII=',
            ]],
        ])->assertCreated();
        $session = TrtInventorySession::findOrFail($response->json('data.session_id'));
        $entry = $session->entries()->sole();
        self::assertSame($member->id, $entry->user_id);
        self::assertSame(2, $entry->actual_quantity);
        Storage::disk('public')->assertExists($entry->image_path);
        $photoBytes = Storage::disk('public')->get($entry->image_path);
        $this->travelBack();

        $admin = $this->equipmentAdmin(manage: true);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(TrtTrailerInventory::class)
            ->assertSet('selectedSessionId', $session->id)
            ->assertSee('[QA TEST] Rescue Tool')
            ->assertSee('/storage/'.$entry->image_path, false)
            ->call('showItemDetail', $catalogItem->id)
            ->assertSee('[QA TEST] TRT Actor')
            ->assertSee('good')
            ->call('closeItemDetail')
            ->callAction('archiveSession', data: ['archive_reason' => ''])
            ->assertHasActionErrors(['archive_reason' => 'required'])
            ->callAction('archiveSession', data: ['archive_reason' => '[QA TEST] Historical review'])
            ->assertHasNoActionErrors()
            ->assertSet('selectedSessionId', null)
            ->set('archiveState', 'archived')
            ->assertSet('selectedSessionId', $session->id)
            ->assertSee('Archived')
            ->call('showItemDetail', $catalogItem->id)
            ->assertSee('[QA TEST] TRT Actor')
            ->call('closeItemDetail')
            ->callAction('restoreSession')->assertHasNoActionErrors()
            ->assertSet('archiveState', 'active')
            ->assertSet('selectedSessionId', $session->id);

        self::assertNull($session->fresh()->archived_at);
        self::assertCount(2, $session->fresh()->archive_history);
        self::assertSame('[QA TEST] Historical review', $session->fresh()->archive_history[1]['metadata']['archive_reason']);
        $archival = app(OperationalEvidenceArchiveService::class);
        $archival->archive($session, $admin, '[QA TEST] Second historical review');
        $archival->archive($session, $admin, '[QA TEST] Duplicate archive');
        self::assertCount(3, $session->archive_history);
        self::assertSame('[QA TEST] Second historical review', $session->archive_reason);
        self::assertSame($admin->id, $session->archive_history[2]['user_id']);
        $archival->restore($session, $admin);
        $archival->restore($session, $admin);
        self::assertCount(4, $session->archive_history);
        self::assertSame('[QA TEST] Second historical review', $session->archive_history[3]['metadata']['archive_reason']);
        Livewire::test(TrtTrailerInventory::class)->assertSee('Archive history')->assertSee('[QA TEST] Second historical review');
        self::assertSame(2, $entry->fresh()->actual_quantity);
        self::assertSame('good', $entry->fresh()->condition);
        self::assertSame('keep', $entry->fresh()->action);
        Storage::disk('public')->assertExists($entry->image_path);
        self::assertSame($photoBytes, Storage::disk('public')->get($entry->image_path));
    }

    public function test_today_shared_session_cannot_be_archived_and_viewers_have_no_lifecycle_actions(): void
    {
        $session = TrtInventorySession::findOrCreateForToday();
        $manager = $this->equipmentAdmin(manage: true);
        $this->actingAs($manager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
        Livewire::test(TrtTrailerInventory::class)->assertActionHidden('archiveSession');

        try {
            app(OperationalEvidenceArchiveService::class)->archive($session, $manager, '[QA TEST] Current session');
            self::fail('Today’s shared session must remain available for new submissions.');
        } catch (ValidationException) {
            self::assertNull($session->fresh()->archived_at);
        }

        $historical = TrtInventorySession::create(['session_date' => today()->subDay()]);
        $this->actingAs($this->equipmentAdmin(manage: false));
        Livewire::test(TrtTrailerInventory::class)
            ->set('selectedSessionId', $historical->id)
            ->assertActionHidden('archiveSession')
            ->assertActionHidden('restoreSession');
    }

    private function equipmentAdmin(bool $manage): User
    {
        $admin = User::factory()->create();
        $permissions = ['admin.access', 'admin.equipment.view'];
        if ($manage) {
            $permissions[] = 'admin.equipment.manage';
        }
        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $admin;
    }
}
