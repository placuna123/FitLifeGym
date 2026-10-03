<?php

namespace Tests\Feature;

use App\Models\ClassBooking;
use App\Models\Gym;
use App\Models\GymClass;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Trainer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_add_edit_and_delete_real_classes(): void
    {
        [$gym, $staffUser, $trainer] = $this->staffSetup();

        $id = $this->actingAs($staffUser)->postJson(route('gym-classes.store'), [
            'name' => 'Morning Yoga',
            'trainer_id' => $trainer->id,
            'day_of_week' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '08:00',
            'capacity' => 12,
        ])->assertCreated()->json('class.id');

        $this->assertDatabaseHas('gym_classes', ['id' => $id, 'gym_id' => $gym->id, 'name' => 'Morning Yoga']);

        $this->actingAs($staffUser)->putJson(route('gym-classes.update', $id), [
            'name' => 'Sunrise Yoga',
            'trainer_id' => null,
            'day_of_week' => 'Tuesday',
            'start_time' => '06:30',
            'end_time' => '07:30',
            'capacity' => 10,
        ])->assertOk();
        $this->assertDatabaseHas('gym_classes', ['id' => $id, 'name' => 'Sunrise Yoga', 'day_of_week' => 'Tuesday', 'trainer_id' => null]);

        $this->actingAs($staffUser)->deleteJson(route('gym-classes.destroy', $id))->assertOk();
        $this->assertSame(0, GymClass::where('is_archived', 0)->count());
    }

    public function test_schedule_lists_this_weeks_date_and_real_booking_count(): void
    {
        [$gym, $staffUser, $trainer] = $this->staffSetup();
        $class = GymClass::create([
            'gym_id' => $gym->id, 'name' => 'HIIT', 'trainer_id' => $trainer->id, 'day_of_week' => 'Wednesday',
            'start_time' => '18:00', 'end_time' => '19:00', 'capacity' => 2, 'is_archived' => 0,
        ]);
        $wednesday = Carbon::parse('2026-10-14'); // a Wednesday
        $plan = MembershipPlan::create(['gym_id' => $gym->id, 'name' => 'Monthly', 'type' => 'Monthly', 'price' => 500, 'period' => 'per month', 'is_archived' => 0]);
        foreach (['a@example.com', 'b@example.com'] as $i => $email) {
            $member = Member::create([
                'gym_id' => $gym->id, 'member_id' => (string) (1000 + $i), 'full_name' => "Member {$i}", 'email' => $email,
                'phone' => '0917000000' . $i, 'membership_plan_id' => $plan->id, 'status' => 'Active',
                'expiry_date' => '2026-12-31', 'payment_status' => 'Paid', 'is_archived' => 0,
            ]);
            ClassBooking::create(['gym_id' => $gym->id, 'gym_class_id' => $class->id, 'member_id' => $member->id, 'class_date' => $wednesday->toDateString(), 'status' => 'Booked']);
        }

        $this->actingAs($staffUser)->getJson(route('gym-classes.index', ['date' => '2026-10-12']))
            ->assertOk()
            ->assertJsonPath('classes.0.class_date', '2026-10-14')
            ->assertJsonPath('classes.0.booked_count', 2)
            ->assertJsonPath('classes.0.trainer_name', 'Coach Ana');

        // A different week has its own (empty) bookings.
        $this->actingAs($staffUser)->getJson(route('gym-classes.index', ['date' => '2026-10-20']))
            ->assertJsonPath('classes.0.class_date', '2026-10-21')
            ->assertJsonPath('classes.0.booked_count', 0);
    }

    /**
     * @return array{0: Gym, 1: User, 2: Trainer}
     */
    private function staffSetup(): array
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $staffUser = User::factory()->create(['role' => 'Staff', 'gym_id' => $gym->id, 'email' => 'coach@example.com']);
        $trainer = Trainer::create([
            'gym_id' => $gym->id, 'staff_id' => 'STF0001', 'full_name' => 'Coach Ana', 'email' => 'coach@example.com',
            'phone' => '09170000000', 'position' => 'Trainer', 'hire_date' => '2026-01-01', 'status' => 'Active', 'is_archived' => 0,
        ]);

        return [$gym, $staffUser, $trainer];
    }
}
