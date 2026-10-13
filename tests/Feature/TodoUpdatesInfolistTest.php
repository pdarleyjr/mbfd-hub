<?php

namespace Tests\Feature;

use App\Models\Todo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodoUpdatesInfolistTest extends TestCase
{
    use RefreshDatabase;

    /** The view page 500ed because the infolist called withTrashed() on a relation whose model has no soft deletes. */
    public function test_todo_updates_infolist_renders_for_a_todo_without_updates(): void
    {
        $todo = Todo::create(['title' => 'No updates yet']);

        $html = view('filament.infolists.todo-updates', ['getRecord' => fn () => $todo])->render();

        $this->assertIsString($html);
        $this->assertStringNotContainsString('withTrashed', $html);
    }
}
