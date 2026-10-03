<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemberAttendanceQrTest extends TestCase
{
    use RefreshDatabase;

    private Gym $gym;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // never hit the real SMS gateway from tests
        $this->gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
    }

    public function test_member_can_request_an_attendance_qr_payload(): void
    {
        ['user' => $memberUser] = $this->createMemberAccount();

        $response = $this->actingAs($memberUser)->postJson(route('member.attendance.qr'));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('qr_payload.type', 'fitgym_member_attendance');

        $this->assertNotEmpty($response->json('qr_payload.qr_token'));
        $this->assertStringStartsWith('fitgym-attendance:', (string) $response->json('qr_code_text'));
        $this->assertNotEmpty($response->json('expires_at'));
    }

    public function test_member_user_without_existing_member_record_can_still_request_attendance_qr(): void
    {
        ['user' => $memberUser, 'member' => $member] = $this->createMemberAccount();
        $member->delete();

        $response = $this->actingAs($memberUser)->postJson(route('member.attendance.qr'));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('qr_payload.type', 'fitgym_member_attendance');
    }

    public function test_staff_scan_can_check_member_in_then_check_member_out_with_the_same_qr(): void
    {
        ['user' => $memberUser, 'member' => $member] = $this->createMemberAccount();
        $staffUser = User::factory()->create(['role' => 'Staff', 'gym_id' => $this->gym->id]);

        $issueResponse = $this->actingAs($memberUser)->postJson(route('member.attendance.qr'));
        $payload = json_encode($issueResponse->json('qr_payload'));

        $checkInResponse = $this->actingAs($staffUser)->postJson(route('trainer.attendance.process-qr'), [
            'payload' => $payload,
        ]);

        $checkInResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'check_in');

        $attendanceId = $checkInResponse->json('attendance.id');

        $this->assertDatabaseHas('attendance', [
            'id' => $attendanceId,
            'member_id' => $member->id,
            'check_out_time' => null,
        ]);

        $checkOutResponse = $this->actingAs($staffUser)->postJson(route('trainer.attendance.process-qr'), [
            'payload' => $payload,
        ]);

        $checkOutResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'check_out');

        $attendanceRecord = DB::table('attendance')->where('id', $attendanceId)->first();

        $this->assertNotNull($attendanceRecord);
        $this->assertNotNull($attendanceRecord->check_out_time);

        $thirdScanResponse = $this->actingAs($staffUser)->postJson(route('trainer.attendance.process-qr'), [
            'payload' => $payload,
        ]);

        $thirdScanResponse
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_staff_scan_accepts_compact_attendance_qr_text(): void
    {
        ['user' => $memberUser, 'member' => $member] = $this->createMemberAccount();
        $staffUser = User::factory()->create(['role' => 'Staff', 'gym_id' => $this->gym->id]);

        $issueResponse = $this->actingAs($memberUser)->postJson(route('member.attendance.qr'));
        $payload = (string) $issueResponse->json('qr_code_text');

        $response = $this->actingAs($staffUser)->postJson(route('trainer.attendance.process-qr'), [
            'payload' => $payload,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'check_in');

        $this->assertDatabaseHas('attendance', [
            'member_id' => $member->id,
            'check_out_time' => null,
        ]);
    }

    public function test_staff_scan_accepts_legacy_member_checkin_payload(): void
    {
        ['member' => $member] = $this->createMemberAccount();
        $staffUser = User::factory()->create(['role' => 'Staff', 'gym_id' => $this->gym->id]);

        $legacyPayload = json_encode([
            'v' => 1,
            'type' => 'fitgym_member_checkin',
            'member_id' => $member->id,
            'member_code' => $member->member_id,
        ]);

        $response = $this->actingAs($staffUser)->postJson(route('trainer.attendance.process-qr'), [
            'payload' => $legacyPayload,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'check_in');
    }

    public function test_manual_member_checkout_invalidates_the_current_qr(): void
    {
        ['user' => $memberUser] = $this->createMemberAccount();
        $staffUser = User::factory()->create(['role' => 'Staff', 'gym_id' => $this->gym->id]);

        $issueResponse = $this->actingAs($memberUser)->postJson(route('member.attendance.qr'));
        $payload = json_encode($issueResponse->json('qr_payload'));

        $this->actingAs($staffUser)->postJson(route('trainer.attendance.process-qr'), [
            'payload' => $payload,
        ])->assertOk()->assertJsonPath('action', 'check_in');

        $this->actingAs($memberUser)->postJson(route('member.attendance.checkout'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($staffUser)->postJson(route('trainer.attendance.process-qr'), [
            'payload' => $payload,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_expired_attendance_qr_is_rejected(): void
    {
        ['user' => $memberUser] = $this->createMemberAccount();
        $staffUser = User::factory()->create(['role' => 'Staff', 'gym_id' => $this->gym->id]);

        $issueResponse = $this->actingAs($memberUser)->postJson(route('member.attendance.qr'));
        $payload = json_encode($issueResponse->json('qr_payload'));

        $this->travel(9)->hours();

        $this->actingAs($staffUser)->postJson(route('trainer.attendance.process-qr'), [
            'payload' => $payload,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * @return array{user: User, member: Member}
     */
    private function createMemberAccount(array $userOverrides = []): array
    {
        $plan = MembershipPlan::create([
            'gym_id' => $this->gym->id,
            'name' => 'Monthly Plan',
            'type' => 'Monthly',
            'price' => 999,
            'period' => 'per month',
            'description' => 'Monthly plan',
            'is_archived' => 0,
        ]);

        $user = User::factory()->create([
            'name' => 'Member User',
            'email' => 'member@example.com',
            'role' => 'Member',
            'gym_id' => $this->gym->id,
            'is_archived' => 0,
            ...$userOverrides,
        ]);

        $member = Member::create([
            'gym_id' => $this->gym->id,
            'member_id' => '1001',
            'full_name' => 'Member User',
            'email' => $user->email,
            'phone' => '09170000000',
            'address' => 'Cebu City',
            'membership_plan_id' => $plan->id,
            'status' => 'Active',
            'expiry_date' => now()->addMonth()->format('Y-m-d'),
            'payment_status' => 'Paid',
            'is_archived' => 0,
        ]);

        return ['user' => $user, 'member' => $member];
    }
}
