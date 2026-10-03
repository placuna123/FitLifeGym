<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_new_password_works_and_old_one_does_not(): void
    {
        $admin = $this->user('Admin', 'admin@example.com');

        $this->actingAs($admin)->postJson(route('admin.change-password'), [
            'current_password' => 'old-password',
            'new_password' => 'new-password-123',
            'confirm_password' => 'new-password-123',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertLoginWorksOnlyWithNewPassword('admin.login', 'admin@example.com');
    }

    public function test_staff_new_password_works_and_old_one_does_not(): void
    {
        $staff = $this->user('Staff', 'staff@example.com');
        Trainer::create([
            'gym_id' => $staff->gym_id,
            'staff_id' => 'STF0001',
            'full_name' => 'Staff',
            'email' => 'staff@example.com',
            'phone' => '09170000000',
            'position' => 'Trainer',
            'hire_date' => now()->format('Y-m-d'),
            'status' => 'Active',
            'is_archived' => 0,
        ]);

        $this->actingAs($staff)->postJson(route('trainer.profile.change-password'), [
            'current_password' => 'old-password',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertLoginWorksOnlyWithNewPassword('trainer.login', 'staff@example.com');
    }

    public function test_member_new_password_works_and_old_one_does_not(): void
    {
        $member = $this->user('Member', 'member@example.com');
        $plan = MembershipPlan::create([
            'gym_id' => $member->gym_id,
            'name' => 'Monthly',
            'type' => 'Monthly',
            'price' => 500,
            'period' => 'per month',
            'is_archived' => 0,
        ]);
        Member::create([
            'gym_id' => $member->gym_id,
            'member_id' => '1001',
            'full_name' => 'Member',
            'email' => 'member@example.com',
            'phone' => '09170000000',
            'membership_plan_id' => $plan->id,
            'status' => 'Active',
            'expiry_date' => now()->addMonth()->format('Y-m-d'),
            'payment_status' => 'Paid',
            'is_archived' => 0,
        ]);

        $this->actingAs($member)->postJson(route('member.change-password'), [
            'current_password' => 'old-password',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertLoginWorksOnlyWithNewPassword('member.login', 'member@example.com');
    }

    public function test_super_admin_new_password_works_and_old_one_does_not(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'SuperAdmin',
            'email' => 'superadmin@example.com',
            'password' => 'old-password',
            'gym_id' => null,
        ]);

        $this->actingAs($superAdmin)->postJson(route('platform.change-password'), [
            'current_password' => 'old-password',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertLoginWorksOnlyWithNewPassword('platform.login', 'superadmin@example.com');
    }

    public function test_wrong_current_password_is_rejected_and_password_unchanged(): void
    {
        $member = $this->user('Member', 'member@example.com');

        $this->actingAs($member)->postJson(route('member.change-password'), [
            'current_password' => 'not-my-password',
            'new_password' => 'new-password-123',
            'new_password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonPath('message', 'Current password is incorrect');

        $this->assertTrue(password_verify('old-password', $member->fresh()->password));
    }

    public function test_short_new_password_gets_a_clear_message(): void
    {
        $admin = $this->user('Admin', 'admin@example.com');

        $response = $this->actingAs($admin)->postJson(route('admin.change-password'), [
            'current_password' => 'old-password',
            'new_password' => 'short',
            'confirm_password' => 'short',
        ])->assertStatus(422);

        $this->assertStringContainsString('8', (string) $response->json('message'));
    }

    private function user(string $role, string $email): User
    {
        $gym = Gym::create(['name' => 'Gym '.$role, 'slug' => 'gym-'.strtolower($role)]);

        return User::factory()->create([
            'role' => $role,
            'email' => $email,
            'password' => 'old-password',
            'gym_id' => $gym->id,
        ]);
    }

    private function assertLoginWorksOnlyWithNewPassword(string $loginRoute, string $email): void
    {
        auth()->logout();

        $this->postJson(route($loginRoute), ['email' => $email, 'password' => 'old-password'])
            ->assertJsonPath('success', false);

        auth()->logout();

        $this->postJson(route($loginRoute), ['email' => $email, 'password' => 'new-password-123'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
