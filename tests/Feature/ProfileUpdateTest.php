<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_profile_name_phone_and_address_are_saved(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($admin)->putJson(route('admin.update-profile'), [
            'name' => 'New Admin Name',
            'phone' => '09171234567',
            'address' => 'Surigao City',
        ])->assertOk();

        $this->actingAs($admin)->getJson(route('admin.profile'))
            ->assertOk()
            ->assertJsonPath('admin.name', 'New Admin Name')
            ->assertJsonPath('admin.phone', '09171234567')
            ->assertJsonPath('admin.address', 'Surigao City');
    }

    public function test_staff_profile_name_and_phone_are_saved(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $staff = User::factory()->create(['role' => 'Staff', 'gym_id' => $gym->id, 'email' => 'staff@example.com']);
        Trainer::create([
            'gym_id' => $gym->id,
            'staff_id' => 'STF0001',
            'full_name' => 'Old Name',
            'email' => 'staff@example.com',
            'phone' => '000',
            'position' => 'Trainer',
            'hire_date' => now()->format('Y-m-d'),
            'status' => 'Active',
            'is_archived' => 0,
        ]);

        $this->actingAs($staff)->putJson(route('trainer.profile.update'), [
            'full_name' => 'New Staff Name',
            'email' => 'staff@example.com',
            'phone' => '09179999999',
        ])->assertOk();

        $this->actingAs($staff)->getJson(route('trainer.profile.show'))
            ->assertOk()
            ->assertJsonPath('trainer.full_name', 'New Staff Name')
            ->assertJsonPath('trainer.phone', '09179999999');
    }

    public function test_member_profile_is_saved_and_weight_is_logged(): void
    {
        [$user, $member] = $this->createMember();

        $this->actingAs($user)->putJson(route('member.profile.update'), [
            'name' => 'Renamed Member',
            'email' => 'renamed@example.com',
            'phone' => '09170001111',
            'fitness_goal' => 'Muscle Gain',
            'weight_kg' => 68.5,
        ])->assertOk()->assertJsonPath('success', true);

        $this->actingAs($user->fresh())->getJson(route('member.user'))
            ->assertOk()
            ->assertJsonPath('user.name', 'Renamed Member')
            ->assertJsonPath('user.email', 'renamed@example.com')
            ->assertJsonPath('member.full_name', 'Renamed Member')
            ->assertJsonPath('member.phone', '09170001111')
            ->assertJsonPath('member.fitness_goal', 'Muscle Gain');

        $this->assertDatabaseHas('member_weight_logs', ['member_id' => $member->id, 'weight_kg' => 68.5]);
    }

    public function test_member_cannot_take_an_email_already_used_by_another_account(): void
    {
        [$user] = $this->createMember();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user)->putJson(route('member.profile.update'), [
            'name' => 'Member',
            'email' => 'taken@example.com',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame('member@example.com', $user->fresh()->email);
    }

    public function test_member_fitness_goal_must_be_a_known_option(): void
    {
        [$user] = $this->createMember();

        $this->actingAs($user)->putJson(route('member.profile.update'), [
            'name' => 'Member',
            'email' => 'member@example.com',
            'fitness_goal' => 'Fly',
        ])->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: Member}
     */
    private function createMember(): array
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $plan = MembershipPlan::create([
            'gym_id' => $gym->id,
            'name' => 'Monthly',
            'type' => 'Monthly',
            'price' => 500,
            'period' => 'per month',
            'is_archived' => 0,
        ]);
        $user = User::factory()->create(['email' => 'member@example.com', 'role' => 'Member', 'gym_id' => $gym->id]);
        $member = Member::create([
            'gym_id' => $gym->id,
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

        return [$user, $member];
    }
}
