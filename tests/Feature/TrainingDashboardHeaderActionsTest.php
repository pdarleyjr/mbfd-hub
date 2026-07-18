<?php

namespace Tests\Feature;

use App\Filament\Training\Pages\Dashboard;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrainingDashboardHeaderActionsTest extends TestCase
{
    public function test_dashboard_does_not_render_a_link_for_an_unregistered_external_sources_resource(): void
    {
        $page = new class extends Dashboard
        {
            public function exposedHeaderActions(): array
            {
                return $this->getHeaderActions();
            }
        };

        $this->assertFalse(Route::has('filament.training.resources.external-sources.index'));

        $actions = $page->exposedHeaderActions();

        $this->assertCount(1, $actions);
        $this->assertSame('newTrainingTodo', $actions[0]->getName());
    }
}
