<?php

namespace Tests\Feature;

use App\Models\Training\TrainingTodo;
use App\Models\User;
use App\Notifications\TrainingTodoAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\Models\Role;
use Tests\Concerns\EnsuresPermissionTables;
use Tests\TestCase;

class TrainingTodoNotificationTest extends TestCase
{
    use EnsuresPermissionTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePermissionTables();
    }

    public function test_training_todo_creation_notifies_assignees(): void
    {
        Notification::fake();

        $creator = User::factory()->create();
        $assignee = User::factory()->create();

        $this->actingAs($creator);

        TrainingTodo::create([
            'title' => 'Prepare academy drill packets',
            'description' => 'Print and stage packets for the next drill.',
            'status' => 'pending',
            'priority' => 'high',
            'assigned_to' => [(string) $assignee->id],
            'created_by' => $creator->id,
        ]);

        Notification::assertSentTo(
            $assignee,
            TrainingTodoAssignedNotification::class,
            fn (TrainingTodoAssignedNotification $notification, array $channels): bool => $channels === ['database']
                && str_contains($notification->toDatabase($assignee)['actions'][0]['url'], '/admin/training-todos/'),
        );

        Notification::assertNotSentTo($creator, TrainingTodoAssignedNotification::class);
    }

    public function test_unassigned_training_todo_creation_notifies_training_panel_users(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Role::create(['name' => 'training_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'training_viewer', 'guard_name' => 'web']);

        Notification::fake();

        $creator = User::factory()->create();
        $trainingAdmin = User::factory()->create();
        $trainingViewer = User::factory()->create();
        $unrelatedUser = User::factory()->create();

        $trainingAdmin->assignRole('training_admin');
        $trainingViewer->assignRole('training_viewer');

        $this->actingAs($creator);

        TrainingTodo::create([
            'title' => 'Publish annual training calendar',
            'description' => null,
            'status' => 'pending',
            'priority' => 'medium',
            'assigned_to' => null,
            'created_by' => $creator->id,
        ]);

        Notification::assertSentTo($trainingAdmin, TrainingTodoAssignedNotification::class);
        Notification::assertSentTo($trainingViewer, TrainingTodoAssignedNotification::class);
        Notification::assertNotSentTo($unrelatedUser, TrainingTodoAssignedNotification::class);
        Notification::assertNotSentTo($creator, TrainingTodoAssignedNotification::class);
    }

    public function test_training_todo_update_notifies_only_new_assignees(): void
    {
        $creator = User::factory()->create();
        $existingAssignee = User::factory()->create();
        $newAssignee = User::factory()->create();

        $this->actingAs($creator);

        $todo = TrainingTodo::create([
            'title' => 'Update checkoff sheets',
            'description' => null,
            'status' => 'pending',
            'priority' => 'low',
            'assigned_to' => [(string) $existingAssignee->id],
            'created_by' => $creator->id,
        ]);

        Notification::fake();

        $todo->update([
            'assigned_to' => [(string) $existingAssignee->id, (string) $newAssignee->id],
        ]);

        Notification::assertSentTo($newAssignee, TrainingTodoAssignedNotification::class);
        Notification::assertNotSentTo($existingAssignee, TrainingTodoAssignedNotification::class);
        Notification::assertNotSentTo($creator, TrainingTodoAssignedNotification::class);
    }

    public function test_training_todo_notification_uses_webpush_for_subscribed_users(): void
    {
        $user = User::factory()->create();
        $user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-endpoint',
            str_repeat('a', 88),
            str_repeat('b', 24),
        );

        $notification = new TrainingTodoAssignedNotification(
            todoId: 123,
            title: 'Review training roster',
            priority: 'urgent',
            actionUrl: '/admin/training-todos/123',
        );

        $this->assertContains(WebPushChannel::class, $notification->via($user));
    }
}
