<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Notification;
use App\Models\User;
use App\Services\SmsService;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        $query = Member::where('is_archived', 0)->with('membershipPlan');
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('member_id', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('plan')) {
            $query->whereHas('membershipPlan', fn ($q) => $q->where('name', $request->plan));
        }
        $members = $query->get()->map(fn ($member) => [
            'id' => $member->id,
            'member_id' => $member->member_id,
            'full_name' => $member->full_name,
            'email' => $member->email,
            'phone' => $member->phone,
            'address' => $member->address,
            'membership_plan' => $member->membershipPlan ? $member->membershipPlan->name : 'N/A',
            'status' => strtolower($member->status),
            'expiry_date' => $member->expiry_date?->format('Y-m-d'),
            'payment_status' => strtolower($member->payment_status),
            'created_at' => $member->created_at,
        ]);
        return response()->json($members);
    }

    public function store(Request $request)
    {
        $gymId = Tenancy::gymId();
        if ($limited = $this->planLimitResponse(Gym::forCurrentUser(), 'members')) {
            return $limited;
        }

        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('members', 'email')->where('gym_id', $gymId)],
            'phone' => 'required|string|max:50',
            'membership_plan' => 'required|string',
            'address' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }
        if ($conflict = \App\Support\MemberAccount::emailConflict($request->email, (int) $gymId)) {
            return response()->json(['success' => false, 'message' => $conflict, 'errors' => ['email' => [$conflict]]], 422);
        }
        try {
            DB::beginTransaction();
            $plan = MembershipPlan::whereRaw('LOWER(name) = ?', [strtolower($request->membership_plan)])->where('is_archived', 0)->first();
            if (!$plan) {
                return response()->json(['success' => false, 'message' => 'Membership plan not found.'], 404);
            }
            $memberId = $this->generateMemberId($gymId);
            $expiryDate = $this->calculateExpiryDate($plan->type);
            $member = Member::create([
                'member_id' => $memberId,
                'full_name' => $request->full_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'membership_plan_id' => $plan->id,
                'status' => 'Pending',
                'expiry_date' => $expiryDate,
                'payment_status' => 'Paid',
                'is_archived' => 0,
            ]);
            $this->createNewMemberNotification($member->full_name);
            $login = \App\Support\MemberAccount::createLogin($member->full_name, $member->email, (int) $gymId);
            DB::commit();
            \App\Support\MemberAccount::invite($login, (int) $gymId);
            \App\Models\AuditLog::record('member.created', "Created member {$member->full_name}", ['type' => 'Member', 'id' => $member->id]);
            return response()->json([
                'success' => true,
                'message' => 'Member created successfully',
                'member' => [
                    'id' => $member->id,
                    'member_id' => $member->member_id,
                    'full_name' => $member->full_name,
                    'email' => $member->email,
                    'phone' => $member->phone,
                    'address' => $member->address,
                    'membership_plan' => $plan->name,
                    'status' => strtolower($member->status),
                    'expiry_date' => $member->expiry_date?->format('Y-m-d'),
                    'payment_status' => strtolower($member->payment_status),
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to create member: ' . $e->getMessage()], 500);
        }
    }

    public function show(string $id)
    {
        $member = Member::where('id', $id)->where('is_archived', 0)->with('membershipPlan')->first();
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Member not found'], 404);
        }
        return response()->json([
            'id' => $member->id,
            'member_id' => $member->member_id,
            'full_name' => $member->full_name,
            'email' => $member->email,
            'phone' => $member->phone,
            'address' => $member->address,
            'membership_plan' => $member->membershipPlan ? $member->membershipPlan->name : 'N/A',
            'status' => strtolower($member->status),
            'expiry_date' => $member->expiry_date?->format('Y-m-d'),
            'payment_status' => strtolower($member->payment_status),
            'created_at' => $member->created_at,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $member = Member::where('id', $id)->where('is_archived', 0)->first();
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Member not found'], 404);
        }
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('members', 'email')->where('gym_id', Tenancy::gymId())->ignore($id)],
            'phone' => 'required|string|max:50',
            'membership_plan' => 'required|string',
            'address' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        try {
            DB::beginTransaction();
            $plan = MembershipPlan::whereRaw('LOWER(name) = ?', [strtolower($request->membership_plan)])->where('is_archived', 0)->first();
            if (!$plan) {
                return response()->json(['success' => false, 'message' => 'Membership plan not found.'], 404);
            }
            $expiryDate = $member->expiry_date;
            // Only a member with paid time gets it recalculated; an unpaid member
            // stays without an expiry until a payment is approved.
            if ($member->expiry_date && (int) $member->membership_plan_id !== (int) $plan->id) {
                $expiryDate = $this->calculateExpiryDate($plan->type);
            }
            $member->update([
                'full_name' => $request->full_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'membership_plan_id' => $plan->id,
                'expiry_date' => $expiryDate,
            ]);
            DB::commit();
            \App\Models\AuditLog::record('member.updated', "Updated member {$member->full_name}", ['type' => 'Member', 'id' => $member->id]);
            return response()->json([
                'success' => true,
                'message' => 'Member updated successfully',
                'member' => [
                    'id' => $member->id,
                    'member_id' => $member->member_id,
                    'full_name' => $member->full_name,
                    'email' => $member->email,
                    'phone' => $member->phone,
                    'address' => $member->address,
                    'membership_plan' => $plan->name,
                    'status' => strtolower($member->status),
                    'expiry_date' => $member->expiry_date?->format('Y-m-d'),
                    'payment_status' => strtolower($member->payment_status),
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to update member: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(string $id)
    {
        $member = Member::where('id', $id)->where('is_archived', 0)->first();
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Member not found'], 404);
        }
        try {
            $member->update(['is_archived' => 1]);

            // Close their login too; otherwise a deleted member could still sign in.
            \App\Models\User::where('gym_id', $member->gym_id)
                ->where('role', 'Member')
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $member->email)])
                ->update(['is_archived' => 1]);

            \App\Models\AuditLog::record('member.deleted', "Deleted member {$member->full_name}", ['type' => 'Member', 'id' => $member->id]);
            return response()->json(['success' => true, 'message' => 'Member deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete member: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Admin/owner sends an ad-hoc SMS to a specific member's registered phone.
     */
    public function sendSms(Request $request, string $id)
    {
        $member = Member::where('id', $id)->where('is_archived', 0)->first();
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Member not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:300',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $sent = app(SmsService::class)->send($member->phone, $request->message, $member->gym_id);
        $this->createMemberNotification($member->id, 'Message from Gym', $request->message, 'blue');

        return response()->json([
            'success' => true,
            'message' => $sent
                ? 'SMS sent successfully.'
                : 'Saved as a notification, but SMS delivery could not be confirmed (check TextBee device/setup).',
        ]);
    }

    /**
     * Admin/Staff approves a member account that was created Pending
     * (self-registration, or added by Admin/Staff) so it can actually log in.
     */
    public function approveMember(Request $request, string $id)
    {
        $member = Member::where('id', $id)->where('is_archived', 0)->first();
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Member not found'], 404);
        }

        $member->update(['status' => 'Active']);

        $this->createMemberNotification($member->id, 'Account Approved', 'Your account has been approved! You can now log in.', 'green');

        try {
            app(SmsService::class)->send(
                $member->phone,
                "Hi {$member->full_name}, your FitGym account has been approved! You can now log in and start using your membership.",
                $member->gym_id
            );
        } catch (\Throwable $exception) {
            // Approval must still succeed even if the SMS send fails.
        }

        \App\Support\Email::to($member->email, new \App\Notifications\MemberApproved(Gym::find($member->gym_id)?->name ?? 'your gym'));

        \App\Models\AuditLog::record('member.approved', "Approved member {$member->full_name}", ['type' => 'Member', 'id' => $member->id]);

        return response()->json([
            'success' => true,
            'message' => 'Member approved successfully',
            'member' => [
                'id' => $member->id,
                'status' => strtolower($member->status),
            ],
        ]);
    }

    /**
     * Generate a member_id unique within one gym. $gymId is explicit (rather
     * than relying on Tenancy::gymId()) because this also runs from the
     * public, pre-auth register() flow where there is no "current user" yet.
     */
    private function generateMemberId(?int $gymId): string
    {
        do {
            $memberId = str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Member::withoutGlobalScopes()->where('gym_id', $gymId)->where('member_id', $memberId)->exists());
        return $memberId;
    }

    private function calculateExpiryDate(string $planType): string
    {
        $today = now();
        switch (strtolower($planType)) {
            case 'annual':
                return $today->addYear()->format('Y-m-d');
            case 'quarterly':
                return $today->addMonths(3)->format('Y-m-d');
            default:
                return $today->addMonth()->format('Y-m-d');
        }
    }

    /**
     * Send a 6-digit OTP to the phone number a registrant is about to use,
     * so register() can prove it's a real, reachable number before creating
     * the account. Stored in cache (not a table) since it's short-lived.
     */
    public function sendRegistrationOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gym_id' => 'required|integer|exists:gyms,id',
            'phone' => ['required', 'string', 'regex:/^(09|\+639|639)\d{9}$/'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $gymId = (int) $request->gym_id;
        if ($closed = $this->registrationClosedResponse(Gym::find($gymId))) {
            return $closed;
        }
        $phone = $request->phone;
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->otpCacheKey($gymId, $phone), ['code' => $code, 'attempts' => 0], now()->addMinutes(10));

        $sent = app(SmsService::class)->send($phone, "Your FitGym verification code is {$code}. Valid for 10 minutes.", $gymId);

        return response()->json([
            'success' => true,
            'message' => $sent
                ? 'Verification code sent. Please check your phone.'
                : 'We could not confirm delivery of the code — please try again or contact support.',
        ]);
    }

    private function otpCacheKey(int $gymId, string $phone): string
    {
        return "phone_otp:{$gymId}:{$phone}";
    }

    /**
     * Public self-registration. There is no "current gym" yet (the request
     * is unauthenticated), so the registrant has to say which gym they're
     * joining — fed by GET /api/gyms/public on the frontend.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gym_id' => 'required|integer|exists:gyms,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => ['required', 'string', 'regex:/^(09|\+639|639)\d{9}$/'],
            'otp_code' => 'required|string|size:6',
            'address' => 'nullable|string|max:500',
            'date_of_birth' => 'nullable|date',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'password' => 'required|string|min:8',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $gymId = (int) $request->gym_id;
        if ($closed = $this->registrationClosedResponse(Gym::find($gymId))) {
            return $closed;
        }

        $otpKey = $this->otpCacheKey($gymId, $request->phone);
        $otpEntry = Cache::get($otpKey);
        if (! $otpEntry) {
            return response()->json(['success' => false, 'message' => 'Code expired or not requested. Please request a new one.'], 422);
        }
        if ($otpEntry['attempts'] >= 5) {
            Cache::forget($otpKey);
            return response()->json(['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'], 422);
        }
        if ($otpEntry['code'] !== $request->otp_code) {
            Cache::put($otpKey, ['code' => $otpEntry['code'], 'attempts' => $otpEntry['attempts'] + 1], now()->addMinutes(10));
            return response()->json(['success' => false, 'message' => 'Invalid verification code.'], 422);
        }
        Cache::forget($otpKey);

        $emailTaken = Member::withoutGlobalScopes()->where('gym_id', $gymId)->where('email', $request->email)->exists();
        if ($emailTaken) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => ['email' => ['This email is already registered at that gym.']],
            ], 422);
        }

        try {
            DB::beginTransaction();
            $defaultPlan = MembershipPlan::withoutGlobalScopes()->where('gym_id', $gymId)->where('is_archived', 0)->orderBy('id')->first();
            if (!$defaultPlan) {
                $defaultPlan = MembershipPlan::create([
                    'gym_id' => $gymId,
                    'name' => 'Trial Plan',
                    'type' => 'Monthly',
                    'price' => 0,
                    'period' => 'per month',
                    'description' => 'Trial membership',
                    'is_archived' => 0,
                ]);
            }
            $memberId = $this->generateMemberId($gymId);
            // No paid time yet: the membership starts on the day a payment is approved
            // (PaymentController::applyPaidConsequences), not on the sign-up day.
            $expiryDate = null;
            $fullName = trim($request->first_name . ' ' . $request->last_name);
            $addressData = [];
            if ($request->address) {
                $addressData['address'] = $request->address;
            }
            if ($request->emergency_contact_name || $request->emergency_contact_phone) {
                $addressData['emergency_contact'] = ['name' => $request->emergency_contact_name, 'phone' => $request->emergency_contact_phone];
            }
            if ($request->date_of_birth) {
                $addressData['date_of_birth'] = $request->date_of_birth;
            }
            $addressJson = !empty($addressData) ? json_encode($addressData) : null;
            $member = Member::create([
                'gym_id' => $gymId,
                'member_id' => $memberId,
                'full_name' => $fullName,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $addressJson,
                'membership_plan_id' => $defaultPlan->id,
                'status' => 'Pending',
                'expiry_date' => $expiryDate,
                'payment_status' => 'Pending',
                'is_archived' => 0,
            ]);
            $memberUser = User::create([
                'name' => $fullName,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'Member',
                'gym_id' => $gymId,
                'is_archived' => 0,
            ]);
            // Nobody is signed in yet at sign-up, so the log entry is written out in full
            // rather than through AuditLog::record()'s auth()->user().
            \App\Models\AuditLog::create([
                'gym_id' => $gymId,
                'user_id' => $memberUser->id,
                'user_name' => $fullName,
                'role' => 'Member',
                'action' => 'member.signed_up',
                'subject_type' => 'Member',
                'subject_id' => $member->id,
                'description' => "{$fullName} created a Member account (phone verified by SMS)",
                'ip_address' => $request->ip(),
            ]);
            $this->createNewMemberNotification($fullName, $gymId);
            $this->createMemberNotification($member->id, 'Welcome!', "Welcome to the gym, {$fullName}! Complete your GCash payment (or pay at the front desk) to activate your membership.", 'green', $gymId);
            DB::commit();
            \App\Support\Email::to($member->email, new \App\Notifications\MemberWelcome(Gym::find($gymId)?->name ?? 'your gym'));
            return response()->json([
                'success' => true,
                'message' => 'Account created! You can sign in now. An admin or staff member will approve your account before you can check in at the gym.',
                'member' => ['id' => $member->id, 'member_id' => $member->member_id, 'full_name' => $member->full_name, 'email' => $member->email]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to create account: ' . $e->getMessage()], 500);
        }
    }

    private function createMemberNotification(int $memberId, string $title, string $message, string $type = 'blue', ?int $gymId = null): void
    {
        try {
            $payload = [
                'member_id' => $memberId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'is_read' => 0,
                'read_at' => null,
                'is_archived' => 0,
            ];
            if ($gymId !== null) {
                $payload['gym_id'] = $gymId;
            }
            Notification::create($payload);
        } catch (\Throwable $exception) {
            // Keep the calling action successful even if the notification write fails.
        }
    }

    private function createNewMemberNotification(string $memberName, ?int $gymId = null): void
    {
        try {
            $payload = [
                'title' => 'New Member Joined',
                'message' => $memberName . ' has successfully registered.',
                'type' => 'yellow',
                'is_read' => 0,
                'read_at' => null,
                'is_archived' => 0,
            ];
            if ($gymId !== null) {
                $payload['gym_id'] = $gymId;
            }
            Notification::create($payload);
        } catch (\Throwable $exception) {
            // Keep member creation successful even if the notification write fails.
        }
    }
}
