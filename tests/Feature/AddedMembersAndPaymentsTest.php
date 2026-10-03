<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AccountInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AddedMembersAndPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake();
    }

    public function test_member_added_by_admin_gets_a_login_and_an_invite(): void
    {
        [$gym, $plan] = $this->gymWithPlan();
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($admin)->postJson(route('members.store'), [
            'full_name' => 'Walk In',
            'email' => 'Walk.In@Gmail.com',
            'phone' => '09170000001',
            'membership_plan' => $plan->name,
        ])->assertCreated();

        $login = User::where('email', 'walk.in@gmail.com')->first();
        $this->assertNotNull($login);
        $this->assertSame('Member', $login->role);
        $this->assertSame($gym->id, (int) $login->gym_id);
        Notification::assertSentTo($login, AccountInvite::class);
    }

    public function test_member_added_by_staff_gets_a_login_and_an_invite(): void
    {
        [$gym, $plan] = $this->gymWithPlan();
        $staff = User::factory()->create(['role' => 'Staff', 'gym_id' => $gym->id]);

        $this->actingAs($staff)->postJson(route('trainer.members.store'), [
            'full_name' => 'Walk In',
            'email' => 'walkin2@gmail.com',
            'phone' => '09170000002',
            'membership_plan_id' => $plan->id,
        ])->assertOk();

        Notification::assertSentTo(User::where('email', 'walkin2@gmail.com')->first(), AccountInvite::class);
    }

    public function test_adding_a_member_with_an_email_owned_by_another_account_is_refused(): void
    {
        [$gym, $plan] = $this->gymWithPlan();
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);
        User::factory()->create(['email' => 'taken@gmail.com', 'role' => 'Staff', 'gym_id' => $gym->id]);

        $this->actingAs($admin)->postJson(route('members.store'), [
            'full_name' => 'Someone',
            'email' => 'taken@gmail.com',
            'phone' => '09170000003',
            'membership_plan' => $plan->name,
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(0, Member::where('email', 'taken@gmail.com')->count());
    }

    public function test_cash_payment_for_a_quarterly_plan_extends_membership_by_three_months(): void
    {
        [$gym] = $this->gymWithPlan();
        $quarterly = MembershipPlan::create(['gym_id' => $gym->id, 'name' => 'Quarterly', 'type' => 'Quarterly', 'price' => 1200, 'period' => 'per 3 months', 'is_archived' => 0]);
        $member = Member::create([
            'gym_id' => $gym->id, 'member_id' => '1001', 'full_name' => 'Member', 'email' => 'member@gmail.com', 'phone' => '09170000000',
            'membership_plan_id' => $quarterly->id, 'status' => 'Active', 'expiry_date' => now()->subDay()->toDateString(),
            'payment_status' => 'Pending', 'is_archived' => 0,
        ]);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $id = $this->actingAs($admin)->postJson(route('payments.store'), [
            'member_id' => $member->id,
            'membership_plan_id' => $quarterly->id,
            'amount' => 1, // ignored: the plan's price is used
            'payment_date' => now()->toDateString(),
            'payment_method' => 'Cash',
        ])->assertCreated()->json('payment.id');

        $this->assertEquals(1200, (float) Payment::find($id)->amount);

        $this->actingAs($admin)->putJson(route('payments.update', $id), ['status' => 'Paid'])->assertOk();

        $this->assertSame(now()->addMonths(3)->toDateString(), $member->fresh()->expiry_date->toDateString());
    }

    /**
     * @return array{0: Gym, 1: MembershipPlan}
     */
    private function gymWithPlan(): array
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $plan = MembershipPlan::create(['gym_id' => $gym->id, 'name' => 'Monthly', 'type' => 'Monthly', 'price' => 500, 'period' => 'per month', 'is_archived' => 0]);

        return [$gym, $plan];
    }
}
