<?php

namespace Tests\Feature;

use App\Models\MembershipPlan;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_active_and_history_notifications(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);

        $activeNotification = Notification::create([
            'title' => 'New Member Joined',
            'message' => 'Jane Doe has successfully registered.',
            'type' => 'yellow',
            'is_read' => 0,
            'is_archived' => 0,
        ]);

        $readNotification = Notification::create([
            'title' => 'Payment Received',
            'message' => 'Payment posted for member account.',
            'type' => 'purple',
            'is_read' => 1,
            'read_at' => now(),
            'is_archived' => 0,
        ]);

        $dismissedNotification = Notification::create([
            'title' => 'System Maintenance',
            'message' => 'Scheduled maintenance was completed.',
            'type' => 'pink',
            'is_read' => 1,
            'read_at' => now(),
            'is_archived' => 1,
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.notifications.index'));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'active')
            ->assertJsonCount(2, 'history')
            ->assertJsonPath('active.0.id', $activeNotification->id);

        $historyIds = collect($response->json('history'))->pluck('id')->all();

        $this->assertContains($readNotification->id, $historyIds);
        $this->assertContains($dismissedNotification->id, $historyIds);
    }

    public function test_marking_a_notification_as_read_moves_it_to_history(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $notification = Notification::create([
            'title' => 'New Member Joined',
            'message' => 'John Doe has successfully registered.',
            'type' => 'yellow',
            'is_read' => 0,
            'is_archived' => 0,
        ]);

        $response = $this->actingAs($admin)->postJson(route('admin.notifications.read', $notification));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'active')
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.id', $notification->id)
            ->assertJsonPath('history.0.history_status', 'read');

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'is_read' => 1,
            'is_archived' => 0,
        ]);
    }

    public function test_dismissing_a_notification_moves_it_to_history(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $notification = Notification::create([
            'title' => 'Payment Overdue',
            'message' => 'A member payment is overdue.',
            'type' => 'yellow',
            'is_read' => 0,
            'is_archived' => 0,
        ]);

        $response = $this->actingAs($admin)->postJson(route('admin.notifications.dismiss', $notification));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'active')
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.id', $notification->id)
            ->assertJsonPath('history.0.history_status', 'dismissed');

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'is_read' => 1,
            'is_archived' => 1,
        ]);
    }

    public function test_creating_a_member_from_admin_portal_creates_a_notification(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $plan = MembershipPlan::create([
            'name' => 'Monthly Starter',
            'type' => 'Monthly',
            'price' => 999,
            'period' => 'per month',
            'description' => 'Monthly access',
            'is_archived' => 0,
        ]);

        $response = $this->actingAs($admin)->postJson(route('members.store'), [
            'full_name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'phone' => '09171234567',
            'membership_plan' => $plan->name,
            'address' => 'Cebu City',
        ]);

        $response->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('notifications', [
            'title' => 'New Member Joined',
            'message' => 'Maria Santos has successfully registered.',
            'is_read' => 0,
            'is_archived' => 0,
        ]);
    }
}
