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

    public function test_update_timestamp_renders_eastern_without_changing_the_saved_instant(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26T03:59:59Z'));
        $user = \App\Models\User::factory()->create();
        $todo = Todo::create(['title' => 'Timezone fixture']);
        $update = $todo->updates()->create(['user_id' => $user->id, 'username' => 'Timezone fixture', 'comment' => 'Recorded by server']);

        $html = view('filament.infolists.todo-updates', ['getRecord' => fn () => $todo])->render();

        self::assertStringContainsString('Oct 25, 2026 11:59 PM', $html);
        self::assertSame('2026-10-26 03:59:59', $update->fresh()->getRawOriginal('created_at'));
    }
}
