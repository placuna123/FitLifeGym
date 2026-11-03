<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberPendingApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_member_can_sign_in(): void
    {
        $this->createMember('Pending');

        $this->postJson(route('member.login'), ['email' => 'pending@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('member.status', 'Pending');
    }

    public function test_pending_member_cannot_get_an_attendance_qr(): void
    {
        $user = $this->createMember('Pending');

        $this->actingAs($user)->postJson(route('member.attendance.qr'))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_active_member_can_sign_in_and_get_an_attendance_qr(): void
    {
        $user = $this->createMember('Active');

        $this->postJson(route('member.login'), ['email' => 'pending@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($user)->postJson(route('member.attendance.qr'))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_approved_member_who_has_not_paid_cannot_get_an_attendance_qr(): void
    {
        $user = $this->createMember('Active', 'Pending');

        $this->actingAs($user)->postJson(route('member.attendance.qr'))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_member_whose_paid_time_ran_out_cannot_get_an_attendance_qr(): void
    {
        $user = $this->createMember('Active', 'Paid', now()->subDays(2)->format('Y-m-d'));

        $this->actingAs($user)->postJson(route('member.attendance.qr'))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_expired_member_is_still_blocked_from_signing_in(): void
    {
        $this->createMember('Expired');

        $this->postJson(route('member.login'), ['email' => 'pending@example.com', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    private function createMember(string $status, ?string $paymentStatus = null, ?string $expiryDate = null): User
    {
        // An approved member has paid unless a test says otherwise; a pending one hasn't.
        $paymentStatus ??= $status === 'Active' ? 'Paid' : 'Pending';
        $expiryDate ??= now()->addMonth()->format('Y-m-d');

        $gym = Gym::create(['name' => 'Test Gym', 'slug' => 'test-gym']);

        $plan = MembershipPlan::create([
            'gym_id' => $gym->id,
            'name' => 'Monthly Plan',
            'type' => 'Monthly',
            'price' => 999,
            'period' => 'per month',
            'description' => 'Monthly plan',
            'is_archived' => 0,
        ]);

        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'password' => 'password',
            'role' => 'Member',
            'gym_id' => $gym->id,
            'is_archived' => 0,
        ]);

        Member::create([
            'gym_id' => $gym->id,
            'member_id' => '1001',
            'full_name' => 'Pending Member',
            'email' => $user->email,
            'phone' => '09170000000',
            'membership_plan_id' => $plan->id,
            'status' => $status,
            'expiry_date' => $expiryDate,
            'payment_status' => $paymentStatus,
            'is_archived' => 0,
        ]);

        return $user;
    }
}
