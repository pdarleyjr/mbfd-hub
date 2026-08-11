<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\TrtTrailerInventory;
use App\Models\TrtInventoryCatalogItem;
use App\Models\TrtInventoryEntry;
use App\Models\TrtInventorySession;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
}
