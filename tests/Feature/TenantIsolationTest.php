<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_admin_only_sees_their_own_branch_members(): void
    {
        $gymA = $this->createGymWithMember('Gym A', 'a@example.com');
        $this->createGymWithMember('Gym B', 'b@example.com');
        $adminA = User::factory()->create(['role' => 'Admin', 'gym_id' => $gymA->id]);

        $response = $this->actingAs($adminA)->getJson(route('members.index'))->assertOk();

        $this->assertSame(['a@example.com'], collect($response->json())->pluck('email')->all());
    }

    public function test_new_branch_admin_sees_no_members(): void
    {
        $this->createGymWithMember('Gym A', 'a@example.com');
        $newGym = Gym::create(['name' => 'New Gym', 'slug' => 'new-gym']);
        $newAdmin = User::factory()->create(['role' => 'Admin', 'gym_id' => $newGym->id]);

        $this->actingAs($newAdmin)->getJson(route('members.index'))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_super_admin_session_cannot_read_branch_data(): void
    {
        $this->createGymWithMember('Gym A', 'a@example.com');
        $superAdmin = User::factory()->create(['role' => 'SuperAdmin', 'gym_id' => null]);

        $this->actingAs($superAdmin)->getJson(route('members.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'no_gym_session');

        $this->actingAs($superAdmin)->getJson(route('payments.index'))->assertForbidden();
    }

    public function test_super_admin_session_cannot_create_a_member_without_a_branch(): void
    {
        $superAdmin = User::factory()->create(['role' => 'SuperAdmin', 'gym_id' => null]);

        $this->actingAs($superAdmin)->postJson(route('members.store'), [
            'full_name' => 'Orphan Member',
            'email' => 'orphan@example.com',
        ])->assertForbidden();

        $this->assertDatabaseMissing('members', ['email' => 'orphan@example.com']);
    }

    public function test_super_admin_platform_routes_still_work(): void
    {
        $superAdmin = User::factory()->create(['role' => 'SuperAdmin', 'gym_id' => null]);

        $this->actingAs($superAdmin)->getJson(route('platform.gyms.index'))->assertOk();
    }

    private function createGymWithMember(string $name, string $email): Gym
    {
        $gym = Gym::create(['name' => $name, 'slug' => str($name)->slug()->toString()]);

        $plan = MembershipPlan::create([
            'gym_id' => $gym->id,
            'name' => 'Monthly',
            'type' => 'Monthly',
            'price' => 500,
            'period' => 'per month',
            'is_archived' => 0,
        ]);

        Member::create([
            'gym_id' => $gym->id,
            'member_id' => (string) random_int(1000, 9999),
            'full_name' => $name . ' Member',
            'email' => $email,
            'phone' => '09170000000',
            'membership_plan_id' => $plan->id,
            'status' => 'Active',
            'expiry_date' => now()->addMonth()->format('Y-m-d'),
            'payment_status' => 'Paid',
            'is_archived' => 0,
        ]);

        return $gym;
    }
}
