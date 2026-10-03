<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AccountInvite;
use App\Notifications\GymWelcome;
use App\Notifications\MemberApproved;
use App\Notifications\MemberWelcome;
use App\Notifications\PasswordChanged;
use App\Notifications\PaymentVerified;
use App\Support\Email;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake(); // never hit the real SMS gateway from tests
    }

    public function test_gym_owner_gets_a_welcome_email_on_signup(): void
    {
        $this->postJson(route('gyms.signup'), [
            'gym_name' => 'Iron Gym',
            'admin_name' => 'Owner',
            'admin_email' => 'owner@gmail.com',
            'admin_password' => 'password123',
        ])->assertCreated();

        Notification::assertSentTo(User::where('email', 'owner@gmail.com')->first(), GymWelcome::class);
    }

    public function test_branch_admin_created_by_super_admin_gets_a_working_set_password_link(): void
    {
        $superAdmin = User::factory()->create(['role' => 'SuperAdmin', 'gym_id' => null]);

        $this->actingAs($superAdmin)->postJson(route('platform.gyms.store'), [
            'name' => 'Branch Two',
            'admin_name' => 'Branch Admin',
            'admin_email' => 'branch.admin@gmail.com',
            'admin_password' => 'temporary123',
        ])->assertCreated();

        $admin = User::where('email', 'branch.admin@gmail.com')->first();
        $link = null;
        Notification::assertSentTo($admin, AccountInvite::class, function (AccountInvite $invite) use ($admin, &$link) {
            $link = $invite->toMail($admin)->actionUrl;

            return true;
        });

        // The emailed link really lets them set their own password.
        preg_match('#reset-password/([^?]+)#', $link, $m);
        auth()->logout();
        $this->post('/reset-password', [
            'token' => $m[1],
            'email' => 'branch.admin@gmail.com',
            'password' => 'MyOwnPass123',
            'password_confirmation' => 'MyOwnPass123',
        ])->assertRedirect('/?reason=password-reset');

        $this->assertTrue(password_verify('MyOwnPass123', $admin->fresh()->password));
        Notification::assertSentTo($admin, PasswordChanged::class);
    }

    public function test_staff_created_by_admin_gets_an_invite_email(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($admin)->postJson(route('trainer.store'), [
            'full_name' => 'New Staff',
            'email' => 'new.staff@gmail.com',
            'phone' => '09170000000',
            'position' => 'Trainer',
            'hire_date' => now()->toDateString(),
            'status' => 'Active',
            'password' => 'secret123',
        ])->assertCreated();

        Notification::assertSentTo(User::where('email', 'new.staff@gmail.com')->first(), AccountInvite::class);
    }

    public function test_member_gets_a_welcome_email_after_registering(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        MembershipPlan::create(['gym_id' => $gym->id, 'name' => 'Monthly', 'type' => 'Monthly', 'price' => 500, 'period' => 'per month', 'is_archived' => 0]);
        Cache::put("phone_otp:{$gym->id}:09171234567", ['code' => '123456', 'attempts' => 0], now()->addMinutes(10));

        $this->postJson(route('members.register'), [
            'gym_id' => $gym->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@gmail.com',
            'phone' => '09171234567',
            'otp_code' => '123456',
            'password' => 'password123',
        ])->assertCreated();

        $this->assertSentToAddress('juan@gmail.com', MemberWelcome::class);
    }

    public function test_member_gets_emails_when_approved_and_when_payment_is_verified(): void
    {
        [$gym, $plan, $member] = $this->memberSetup('Pending');
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->actingAs($admin)->postJson(route('members.approve', $member->id))->assertOk();
        $this->assertSentToAddress('member@gmail.com', MemberApproved::class);

        $payment = Payment::create([
            'gym_id' => $gym->id,
            'invoice_number' => 'INV-TEST-0001',
            'member_id' => $member->id,
            'membership_plan_id' => $plan->id,
            'amount' => 500,
            'payment_method' => 'GCash',
            'payment_date' => now()->toDateString(),
            'status' => 'Pending',
            'is_archived' => 0,
        ]);

        $this->actingAs($admin)->putJson(route('payments.update', $payment->id), ['status' => 'Paid'])->assertOk();
        $this->assertSentToAddress('member@gmail.com', PaymentVerified::class);
    }

    public function test_changing_a_password_sends_a_security_email(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id, 'password' => 'old-password']);

        $this->actingAs($admin)->postJson(route('admin.change-password'), [
            'current_password' => 'old-password',
            'new_password' => 'new-password-123',
            'confirm_password' => 'new-password-123',
        ])->assertOk();

        Notification::assertSentTo($admin, PasswordChanged::class);
    }

    public function test_a_broken_mail_server_never_breaks_the_action(): void
    {
        Notification::swap(new \Illuminate\Notifications\ChannelManager(app())); // real sending again
        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'does-not-exist']]);

        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);

        $this->assertFalse(Email::send($admin, new PasswordChanged('now')));

        $this->actingAs($admin)->postJson(route('trainer.store'), [
            'full_name' => 'New Staff',
            'email' => 'new.staff@gmail.com',
            'phone' => '09170000000',
            'position' => 'Trainer',
            'hire_date' => now()->toDateString(),
            'status' => 'Active',
            'password' => 'secret123',
        ])->assertCreated();
    }

    private function assertSentToAddress(string $address, string $notification): void
    {
        Notification::assertSentOnDemand($notification, function ($sent, array $channels, AnonymousNotifiable $notifiable) use ($address) {
            return ($notifiable->routes['mail'] ?? null) === $address;
        });
    }

    /**
     * @return array{0: Gym, 1: MembershipPlan, 2: Member}
     */
    private function memberSetup(string $status): array
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $plan = MembershipPlan::create(['gym_id' => $gym->id, 'name' => 'Monthly', 'type' => 'Monthly', 'price' => 500, 'period' => 'per month', 'is_archived' => 0]);
        $member = Member::create([
            'gym_id' => $gym->id,
            'member_id' => '1001',
            'full_name' => 'Member',
            'email' => 'member@gmail.com',
            'phone' => '09170000000',
            'membership_plan_id' => $plan->id,
            'status' => $status,
            'expiry_date' => now()->addMonth()->toDateString(),
            'payment_status' => 'Pending',
            'is_archived' => 0,
        ]);

        return [$gym, $plan, $member];
    }
}
