<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_created_by_admin_belongs_to_the_admins_branch_and_can_work(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($admin)->postJson(route('trainer.store'), $this->staffPayload())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $staffUser = User::where('email', 'new.staff@example.com')->firstOrFail();
        $this->assertSame('Staff', $staffUser->role);
        $this->assertSame($gym->id, (int) $staffUser->gym_id);

        auth()->logout();

        $this->postJson(route('trainer.login'), ['email' => 'new.staff@example.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('success', true);

        // The tenant guard lets a branch-linked staff account reach branch data.
        $this->getJson(route('members.index'))->assertOk();
    }

    public function test_editing_a_staff_login_without_a_branch_repairs_it(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);
        $orphanLogin = User::factory()->create(['email' => 'old.staff@example.com', 'role' => 'Staff', 'gym_id' => null]);
        $trainer = Trainer::create([
            'gym_id' => $gym->id,
            'staff_id' => 'STF0001',
            'full_name' => 'Old Staff',
            'email' => 'old.staff@example.com',
            'phone' => '09170000000',
            'position' => 'Trainer',
            'hire_date' => now()->format('Y-m-d'),
            'status' => 'Active',
            'is_archived' => 0,
        ]);

        $this->actingAs($admin)->putJson(route('trainer.update', $trainer->id), [
            'full_name' => 'Old Staff',
            'email' => 'old.staff@example.com',
            'phone' => '09170000000',
            'position' => 'Trainer',
            'status' => 'Active',
        ])->assertOk();

        $this->assertSame($gym->id, (int) $orphanLogin->fresh()->gym_id);
    }

    public function test_deleted_staff_can_no_longer_sign_in_and_their_classes_become_unassigned(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);
        $this->actingAs($admin)->postJson(route('trainer.store'), $this->staffPayload())->assertCreated();
        $trainer = Trainer::where('email', 'new.staff@example.com')->firstOrFail();
        $class = \App\Models\GymClass::create([
            'gym_id' => $gym->id, 'name' => 'Yoga', 'trainer_id' => $trainer->id,
            'day_of_week' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'capacity' => 10, 'is_archived' => 0,
        ]);

        $this->actingAs($admin)->deleteJson(route('trainer.destroy', $trainer->id))->assertOk();

        $this->assertTrue((bool) User::where('email', 'new.staff@example.com')->value('is_archived'));
        $this->assertNull($class->fresh()->trainer_id);
        auth()->logout();
        $this->postJson(route('trainer.login'), ['email' => 'new.staff@example.com', 'password' => 'secret123'])
            ->assertJsonPath('success', false);
        $this->assertGuest();
    }

    public function test_adding_staff_with_an_email_already_in_use_says_why(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);
        User::factory()->create(['role' => 'Admin', 'email' => 'new.staff@example.com']);

        $this->actingAs($admin)->postJson(route('trainer.store'), $this->staffPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'This email is already used by another FitGym account. Use a different email.');
    }

    private function staffPayload(): array
    {
        return [
            'full_name' => 'New Staff',
            'email' => 'new.staff@example.com',
            'phone' => '09170000000',
            'position' => 'Trainer',
            'hire_date' => now()->format('Y-m-d'),
            'status' => 'Active',
            'password' => 'secret123',
        ];
    }
}
