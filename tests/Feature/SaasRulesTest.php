<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SaasRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_signup_gets_a_trial_end_date(): void
    {
        $this->postJson(route('gyms.signup'), [
            'gym_name' => 'New Gym',
            'admin_name' => 'Owner',
            'admin_email' => 'owner@example.com',
            'admin_password' => 'password123',
        ])->assertCreated();

        $gym = Gym::where('name', 'New Gym')->firstOrFail();
        $this->assertSame('trial', $gym->subscription_status);
        $this->assertSame(now()->addDays(config('fitgym.trial_days'))->toDateString(), $gym->subscription_expires_at->toDateString());
    }

    public function test_suspended_branch_cannot_sign_in_or_use_its_data(): void
    {
        $gym = $this->gym(['subscription_status' => 'suspended']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id, 'email' => 'admin@example.com']);

        $this->postJson(route('admin.login'), ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('code', 'gym_inactive');

        $this->actingAs($admin)->getJson(route('members.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'gym_inactive');
    }

    public function test_expired_trial_locks_the_branch(): void
    {
        $gym = $this->gym(['subscription_status' => 'trial', 'subscription_expires_at' => now()->subDay()->toDateString()]);
        $staff = User::factory()->create(['role' => 'Staff', 'gym_id' => $gym->id, 'email' => 'staff@example.com']);

        $this->assertSame('expired', $gym->effectiveStatus());

        $this->postJson(route('trainer.login'), ['email' => 'staff@example.com', 'password' => 'password'])
            ->assertForbidden();

        $this->actingAs($staff)->getJson(route('payments.index'))->assertForbidden();
    }

    public function test_trial_ending_today_still_works(): void
    {
        $gym = $this->gym(['subscription_status' => 'trial', 'subscription_expires_at' => now()->toDateString()]);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($admin)->getJson(route('members.index'))->assertOk();
    }

    public function test_super_admin_reactivating_a_lapsed_branch_restores_access(): void
    {
        $gym = $this->gym(['subscription_status' => 'trial', 'subscription_expires_at' => now()->subDays(3)->toDateString()]);
        $superAdmin = User::factory()->create(['role' => 'SuperAdmin', 'gym_id' => null]);

        $this->actingAs($superAdmin)->putJson(route('platform.gyms.update', $gym->id), ['subscription_status' => 'active'])
            ->assertOk()
            ->assertJsonPath('gym.effective_status', 'active');

        $this->assertTrue($gym->fresh()->allowsAccess());
    }

    public function test_suspended_branch_is_hidden_from_member_signup_and_rejects_registration(): void
    {
        $open = $this->gym(['name' => 'Open Gym', 'slug' => 'open']);
        $closed = $this->gym(['name' => 'Closed Gym', 'slug' => 'closed', 'subscription_status' => 'suspended']);

        $names = collect($this->getJson(route('gyms.public'))->json('gyms'))->pluck('name')->all();
        $this->assertContains('Open Gym', $names);
        $this->assertNotContains('Closed Gym', $names);

        $this->postJson(route('members.register.send-otp'), ['gym_id' => $closed->id, 'phone' => '09171234567'])
            ->assertStatus(422);
        $this->assertNotNull($open);
    }

    public function test_plan_member_limit_blocks_adding_more_members(): void
    {
        config(['fitgym.plans.trial.members' => 1]);
        $gym = $this->gym(['subscription_plan' => 'trial']);
        $plan = $this->plan($gym);
        $this->member($gym, $plan, 'first@example.com');
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($admin)->postJson(route('members.store'), [
            'full_name' => 'Second',
            'email' => 'second@example.com',
            'phone' => '09170000002',
            'membership_plan_id' => $plan->id,
        ])->assertStatus(422)->assertJsonPath('code', 'plan_limit');
    }

    public function test_plan_staff_limit_blocks_adding_more_staff(): void
    {
        config(['fitgym.plans.basic.staff' => 0]);
        $gym = $this->gym(['subscription_plan' => 'basic', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($admin)->postJson(route('trainer.store'), [
            'full_name' => 'Staff',
            'email' => 'staff@example.com',
            'phone' => '09170000000',
            'position' => 'Trainer',
            'hire_date' => now()->toDateString(),
            'status' => 'Active',
            'password' => 'secret123',
        ])->assertStatus(422)->assertJsonPath('code', 'plan_limit');
    }

    public function test_pro_plan_has_no_limits(): void
    {
        $gym = $this->gym(['subscription_plan' => 'pro', 'subscription_status' => 'active']);

        $this->assertNull($gym->planLimit('members'));
        $this->assertTrue($gym->hasRoomFor('members'));
        $this->assertTrue($gym->hasRoomFor('staff'));
    }

    public function test_member_support_ticket_reaches_the_admin_notifications(): void
    {
        $gym = $this->gym();
        $plan = $this->plan($gym);
        $memberUser = User::factory()->create(['role' => 'Member', 'gym_id' => $gym->id, 'email' => 'member@example.com']);
        $this->member($gym, $plan, 'member@example.com');
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($memberUser)->postJson(route('member.support-tickets.store'), [
            'subject' => 'Equipment Issue',
            'message' => 'The treadmill is broken.',
        ])->assertOk()->assertJsonPath('success', true);

        $active = collect($this->actingAs($admin)->getJson(route('admin.notifications.index'))->json('active'));
        $ticket = $active->firstWhere('title', 'Member Feedback: Equipment Issue');
        $this->assertNotNull($ticket);
        $this->assertStringContainsString('The treadmill is broken.', $ticket['message']);
        $this->assertSame('high', $ticket['priority']);
    }

    public function test_member_gcash_payment_alerts_the_admin(): void
    {
        Storage::fake('public');
        $gym = $this->gym();
        $plan = $this->plan($gym);
        $memberUser = User::factory()->create(['role' => 'Member', 'gym_id' => $gym->id, 'email' => 'member@example.com']);
        $this->member($gym, $plan, 'member@example.com');
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($memberUser)->post(route('member.payments.store'), [
            'membership_plan_id' => $plan->id,
            'payment_method' => 'GCash',
            'receipt_proof' => UploadedFile::fake()->image('receipt.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $titles = collect($this->actingAs($admin)->getJson(route('admin.notifications.index'))->json('active'))->pluck('title');
        $this->assertContains('Payment Received', $titles->all());
    }

    private function gym(array $attributes = []): Gym
    {
        return Gym::create([
            'name' => 'Gym A',
            'slug' => 'gym-a',
            'subscription_plan' => 'trial',
            'subscription_status' => 'trial',
            'subscription_expires_at' => now()->addDays(7)->toDateString(),
            ...$attributes,
        ]);
    }

    private function plan(Gym $gym): MembershipPlan
    {
        return MembershipPlan::create([
            'gym_id' => $gym->id,
            'name' => 'Monthly',
            'type' => 'Monthly',
            'price' => 500,
            'period' => 'per month',
            'is_archived' => 0,
        ]);
    }

    private function member(Gym $gym, MembershipPlan $plan, string $email): Member
    {
        return Member::create([
            'gym_id' => $gym->id,
            'member_id' => (string) random_int(1000, 9999),
            'full_name' => 'Member',
            'email' => $email,
            'phone' => '09170000000',
            'membership_plan_id' => $plan->id,
            'status' => 'Active',
            'expiry_date' => now()->addMonth()->format('Y-m-d'),
            'payment_status' => 'Paid',
            'is_archived' => 0,
        ]);
    }
}
