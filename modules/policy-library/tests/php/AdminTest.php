<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Filament\Facades\Filament;
use Livewire\Livewire;
use Mbfd\PolicyLibrary\Filament\Resources\Pages\CreateManual;
use Mbfd\PolicyLibrary\Filament\Resources\Pages\CreateNode;
use Mbfd\PolicyLibrary\Filament\Resources\Pages\EditManual;
use Mbfd\PolicyLibrary\Filament\Resources\Pages\EditNode;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;

final class AdminTest extends TestCase
{
    public function test_management_pages_render_for_entitled_user_and_deny_members(): void
    {
        $manager = $this->user('Manager');
        $manager->givePermissionTo('files.manage');
        $this->actingAs($manager);
        $this->withoutExceptionHandling();
        foreach (['/manage/manuals', '/manage/nodes', '/manage/editions', '/manage/imports'] as $path) {
            $this->get('https://files.mbfdhub.com'.$path)->assertOk();
        }
        $this->withExceptionHandling();
        $this->actingAs($this->user('Member'));
        foreach (['/manage/manuals', '/manage/nodes', '/manage/editions', '/manage/imports'] as $path) {
            $this->get('https://files.mbfdhub.com'.$path)->assertForbidden();
        }
    }

    public function test_manager_can_create_manual_through_existing_filament_form(): void
    {
        $manager = $this->user('Manager');
        $manager->givePermissionTo('files.manage');
        $this->actingAs($manager);
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('policy-library'));
        Livewire::test(CreateManual::class)->fillForm(['name' => 'Medical Protocols', 'slug' => 'medical', 'type' => 'medical', 'description' => 'Protocol library', 'sort_order' => 0, 'is_active' => true])->call('create')->assertHasNoFormErrors();
        $this->assertSame('Medical Protocols', Manual::query()->where('slug', 'medical')->value('name'));
    }

    public function test_new_manual_slug_follows_name_until_the_manager_customizes_it(): void
    {
        $this->signInManager();
        Livewire::test(CreateManual::class)->fillForm(['type' => 'medical'])
            ->set('data.name', 'Medical Protocols')->assertFormSet(['slug' => 'medical-protocols'])
            ->set('data.name', 'Medical Reference')->assertFormSet(['slug' => 'medical-reference'])
            ->set('data.slug', 'medical')->set('data.name', 'Medical Reference Updated')->assertFormSet(['slug' => 'medical'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('Medical Reference Updated', Manual::query()->where('slug', 'medical')->value('name'));
    }

    public function test_renaming_an_existing_manual_preserves_its_bookmark_slug(): void
    {
        $this->signInManager();
        $manual = Manual::query()->create(['name' => 'Medical Protocols', 'slug' => 'medical-protocols', 'type' => 'medical']);
        Livewire::test(EditManual::class, ['record' => $manual->getRouteKey()])
            ->set('data.name', 'Medical Reference')->assertFormSet(['slug' => 'medical-protocols'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Medical Reference', $manual->fresh()->name);
        $this->assertSame('medical-protocols', $manual->fresh()->slug);
    }

    public function test_new_navigation_slug_follows_title_until_the_manager_customizes_it(): void
    {
        $this->signInManager();
        $manual = Manual::query()->create(['name' => 'Medical Protocols', 'slug' => 'medical', 'type' => 'medical']);
        $edition = $manual->editions()->create(['label' => 'Draft']);
        Livewire::test(CreateNode::class)->fillForm(['edition_id' => $edition->id])
            ->set('data.title', 'Adult Assessment')->assertFormSet(['slug' => 'adult-assessment'])
            ->set('data.title', 'Initial Adult Assessment')->assertFormSet(['slug' => 'initial-adult-assessment'])
            ->set('data.slug', 'adult-care')->set('data.title', 'Initial Patient Assessment')->assertFormSet(['slug' => 'adult-care'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame('Initial Patient Assessment', ManualNode::query()->where('edition_id', $edition->id)->where('slug', 'adult-care')->value('title'));
    }

    public function test_renaming_an_existing_navigation_entry_preserves_its_bookmark_slug(): void
    {
        $this->signInManager();
        $manual = Manual::query()->create(['name' => 'Medical Protocols', 'slug' => 'medical', 'type' => 'medical']);
        $edition = $manual->editions()->create(['label' => 'Draft']);
        $node = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => 'Adult Assessment', 'slug' => 'adult-assessment', 'type' => 'document']);
        Livewire::test(EditNode::class, ['record' => $node->getRouteKey()])
            ->set('data.title', 'Initial Adult Assessment')->assertFormSet(['slug' => 'adult-assessment'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Initial Adult Assessment', $node->fresh()->title);
        $this->assertSame('adult-assessment', $node->fresh()->slug);
    }

    private function signInManager(): void
    {
        $manager = $this->user('Manager');
        $manager->givePermissionTo('files.manage');
        $this->actingAs($manager);
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('policy-library'));
    }
}
