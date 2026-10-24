<?php

namespace Tests\Feature;

use App\Models\Training\TrainingTodo;
use App\Models\User;
use App\Notifications\TrainingTodoAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TrainingTodoNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['training_admin', 'training_viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /** A member who can open Training: only they can be told about training tasks. */
    private function trainingMember(string $role = 'training_viewer'): User
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    public function test_training_todo_creation_notifies_assignees(): void
    {
        Notification::fake();

        $creator = $this->trainingMember();
        $assignee = $this->trainingMember();

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
            fn (TrainingTodoAssignedNotification $notification, array $channels): bool => $channels === ['database'],
        );

        Notification::assertNotSentTo($creator, TrainingTodoAssignedNotification::class);
    }

    public function test_an_assignee_who_can_no_longer_open_training_is_not_notified(): void
    {
        Notification::fake();

        $creator = $this->trainingMember();
        $assignee = User::factory()->create(['account_status' => 'active']);

        $this->actingAs($creator);

        TrainingTodo::create([
            'title' => 'Prepare academy drill packets',
            'description' => null,
            'status' => 'pending',
            'priority' => 'high',
            'assigned_to' => [(string) $assignee->id],
            'created_by' => $creator->id,
        ]);

        Notification::assertNotSentTo($assignee, TrainingTodoAssignedNotification::class);
    }

    public function test_unassigned_training_todo_creation_notifies_training_panel_users(): void
    {
        Notification::fake();

        $creator = User::factory()->create(['account_status' => 'active']);
        $trainingAdmin = $this->trainingMember('training_admin');
        $trainingViewer = $this->trainingMember('training_viewer');
        $unrelatedUser = User::factory()->create(['account_status' => 'active']);

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
        $creator = $this->trainingMember();
        $existingAssignee = $this->trainingMember();
        $newAssignee = $this->trainingMember();

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
        $user = $this->trainingMember();
        $user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-endpoint',
            str_repeat('a', 88),
            str_repeat('b', 24),
        );

        $notification = new TrainingTodoAssignedNotification(
            todoId: 123,
            title: 'Review training roster',
            priority: 'urgent',
            actionUrl: '/training/training-todos/123',
        );

        $this->assertContains(WebPushChannel::class, $notification->via($user));
    }

    public function test_a_member_who_turned_push_off_still_gets_the_in_app_copy_only(): void
    {
        $user = $this->trainingMember();
        $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/test-endpoint', str_repeat('a', 88), str_repeat('b', 24));
        $user->notificationSubscriptions()->create([
            'event_key' => 'training_assignments',
            'database_enabled' => true,
            'webpush_enabled' => false,
            'email_enabled' => false,
        ]);

        $notification = new TrainingTodoAssignedNotification(1, 'Review roster', 'high', '/training');

        $this->assertSame(['database'], $notification->via($user));
    }
}
