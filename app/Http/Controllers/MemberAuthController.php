<?php

namespace App\Http\Controllers;

use App\Models\MembershipPlan;
use App\Models\MemberWeightLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Member;

class MemberAuthController extends Controller
{
    /**
     * If User exists (Member role) but no Member record, create one so login can succeed.
     * Only ever called AFTER Auth::attempt() succeeds, so Tenancy::gymId() (used by
     * Member's BelongsToGym trait to fill gym_id on create) resolves correctly.
     */
    private function ensureMemberRecordExists(\App\Models\User $user): Member
    {
        // `members.email` is unique per gym (regardless of `is_archived`).
        // If the member exists but is archived, we must update/unarchive it
        // instead of inserting a new row (prevents duplicate key error).
        $member = Member::where('email', $user->email)->where('is_archived', 0)->first();
        if ($member) {
            return $member;
        }

        $archivedMember = Member::where('email', $user->email)->first();
        $plan = MembershipPlan::where('is_archived', 0)->orderBy('id')->first();
        if (! $plan) {
            $plan = MembershipPlan::create([
                'name' => 'Trial Plan',
                'type' => 'Monthly',
                'price' => 0,
                'period' => '1 month',
                'description' => 'Trial membership',
                'is_popular' => 0,
                'is_deactivated' => 0,
                'is_archived' => 0,
            ]);
        }

        $expiry = now()->addMonth();
        if (strtolower($plan->type ?? '') === 'annual') {
            $expiry = now()->addYear();
        } elseif (strtolower($plan->type ?? '') === 'quarterly') {
            $expiry = now()->addMonths(3);
        }

        if ($archivedMember) {
            $archivedMember->update([
                'full_name' => $user->name ?? $archivedMember->full_name ?? 'Member User',
                'membership_plan_id' => $plan->id,
                'status' => 'Active',
                'expiry_date' => $expiry,
                'payment_status' => 'Paid',
                'is_archived' => 0,
            ]);

            return $archivedMember->fresh();
        }

        do {
            $memberId = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Member::where('member_id', $memberId)->exists());

        return Member::create([
            'member_id' => $memberId,
            'full_name' => $user->name ?? 'Member User',
            'email' => $user->email,
            'phone' => '',
            'address' => null,
            'membership_plan_id' => $plan->id,
            'status' => 'Active',
            'expiry_date' => $expiry,
            'payment_status' => 'Paid',
            'is_archived' => 0,
        ]);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        // Verify the password FIRST. Nothing about this account (archived
        // state, role, or whether a Member record exists) is checked — let
        // alone written to — before we know the password is actually correct.
        if (! Auth::attempt($credentials, $remember)) {
            return response()->json(['success' => false, 'message' => 'Invalid email or password.'], 401);
        }

        $user = Auth::user();

        if (isset($user->is_archived) && $user->is_archived) {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact support.'
            ], 403);
        }

        if (($user->role ?? null) !== 'Member') {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Member account required.'
            ], 403);
        }

        if ($blocked = $this->blockedGymResponse($user)) {
            Auth::logout();
            return $blocked;
        }

        $member = Member::where('email', $user->email)->where('is_archived', 0)->first();
        if (! $member) {
            $member = $this->ensureMemberRecordExists($user);
        }

        // Pending members (phone-verified, awaiting staff approval) may sign in to pay
        // and see their account; attendance QR stays blocked until they are approved.
        if (! in_array($member->status, ['Active', 'Pending'], true)) {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'Your account is pending admin approval. Please wait for the gym staff to approve your account before logging in.'
            ], 403);
        }

        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'member' => [
                'id' => $member->id,
                'member_id' => $member->member_id,
                'full_name' => $member->full_name,
                'email' => $member->email,
                'phone' => $member->phone,
                'status' => $member->status,
                'membership_plan_id' => $member->membership_plan_id,
            ]
        ]);
    }

    public const FITNESS_GOALS = ['Weight Loss', 'Muscle Gain', 'Endurance', 'General Fitness'];

    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        if (! $user || ($user->role ?? null) !== 'Member') {
            return response()->json(['success' => false, 'message' => 'Not a member account'], 403);
        }
        $member = Member::where('email', $user->email)->where('is_archived', 0)->first();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Member record not found'], 404);
        }

        $request->merge(['email' => strtolower(trim((string) $request->input('email', '')))]);
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
                Rule::unique('members', 'email')->where('gym_id', $member->gym_id)->ignore($member->id),
            ],
            'phone' => 'nullable|string|max:50',
            'fitness_goal' => ['nullable', Rule::in(self::FITNESS_GOALS)],
            'weight_kg' => 'nullable|numeric|min:20|max:400',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::transaction(function () use ($request, $user, $member) {
            $user->update(['name' => $request->name, 'email' => $request->email]);
            $member->update([
                'full_name' => $request->name,
                'email' => $request->email,
                'phone' => $request->input('phone') ?: null,
                'fitness_goal' => $request->input('fitness_goal') ?: null,
            ]);
            if ($request->filled('weight_kg')) {
                MemberWeightLog::updateOrCreate(
                    ['member_id' => $member->id, 'logged_at' => now()->format('Y-m-d')],
                    ['weight_kg' => $request->weight_kg]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'member' => [
                'full_name' => $member->full_name,
                'email' => $member->email,
                'phone' => $member->phone,
                'fitness_goal' => $member->fitness_goal,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        $user = Auth::user();
        if (! $user || ($user->role ?? null) !== 'Member') {
            return response()->json(['success' => false, 'message' => 'Not a member account'], 403);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8',
            'new_password_confirmation' => 'required|string|same:new_password',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }
        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'Current password is incorrect'], 422);
        }

        $user->update(['password' => Hash::make($request->new_password)]);
        \App\Support\Email::passwordChanged($user);

        return response()->json(['success' => true, 'message' => 'Password changed successfully']);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
    }

    public function user(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'message' => 'Not authenticated'], 401);
        }
        $user = Auth::user();
        if (($user->role ?? null) !== 'Member') {
            return response()->json(['success' => false, 'message' => 'Not a member account'], 403);
        }
        if ($blocked = $this->blockedGymResponse($user)) {
            Auth::logout();
            return $blocked;
        }
        $member = Member::where('email', $user->email)->where('is_archived', 0)->with('membershipPlan')->first();
        if (!$member) {
            $member = $this->ensureMemberRecordExists($user);
        }
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'member' => [
                'id' => $member->id,
                'member_id' => $member->member_id,
                'full_name' => $member->full_name,
                'email' => $member->email,
                'phone' => $member->phone,
                'status' => $member->status,
                'membership_plan_id' => $member->membership_plan_id,
                'membership_plan' => $member->membershipPlan ? $member->membershipPlan->name : null,
                'expiry_date' => $member->expiry_date ? $member->expiry_date->format('Y-m-d') : null,
                'payment_status' => $member->payment_status,
                'fitness_goal' => $member->fitness_goal,
                'created_at' => $member->created_at?->format('Y-m-d'),
            ]
        ]);
    }
}
