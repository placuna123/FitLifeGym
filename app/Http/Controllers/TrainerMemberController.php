<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Notification;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

class TrainerMemberController extends Controller
{
    /**
     * Add new member
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('members', 'email')->where('gym_id', Tenancy::gymId())],
            'phone' => 'required|string|max:50',
            'address' => 'nullable|string',
            'membership_plan_id' => 'required|exists:membership_plans,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $plan = MembershipPlan::findOrFail($request->membership_plan_id);

            // Generate member ID
            $memberId = $this->generateMemberId();

            // Calculate expiry date based on plan period
            $expiryDate = Carbon::now();
            if ($plan->period) {
                // Parse period (e.g., "1 month", "3 months", "12 months", "1 year")
                $period = strtolower($plan->period);
                if (strpos($period, 'month') !== false) {
                    $months = (int) preg_replace('/[^0-9]/', '', $period);
                    if ($months > 0) {
                        $expiryDate = $expiryDate->addMonths($months);
                    } else {
                        $expiryDate = $expiryDate->addMonth();
                    }
                } elseif (strpos($period, 'year') !== false) {
                    $years = (int) preg_replace('/[^0-9]/', '', $period);
                    if ($years > 0) {
                        $expiryDate = $expiryDate->addYears($years);
                    } else {
                        $expiryDate = $expiryDate->addYear();
                    }
                } else {
                    // Default to 1 month if period format is unclear
                    $expiryDate = $expiryDate->addMonth();
                }
            } else {
                // Default to 1 month if no period specified
                $expiryDate = $expiryDate->addMonth();
            }

            // Create member
            $member = Member::create([
                'member_id' => $memberId,
                'full_name' => $request->full_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address ?? '',
                'membership_plan_id' => $request->membership_plan_id,
                'status' => 'Pending',
                'expiry_date' => $expiryDate,
                'payment_status' => 'Pending',
                'is_archived' => 0,
            ]);

            // Notify admin about new member join.
            // Notifications table is currently global (no admin_id), so we just insert a new row.
            try {
                Notification::create([
                    'title' => 'New Member Joined',
                    'message' => $member->full_name . ' has successfully registered.',
                    'type' => 'yellow',
                    'is_read' => 0,
                    'read_at' => null,
                    'is_archived' => 0,
                ]);
            } catch (\Throwable $notifyEx) {
                // Don't block member creation if notifications fail.
            }

            return response()->json([
                'success' => true,
                'message' => 'Member added successfully',
                'member' => [
                    'id' => $member->id,
                    'member_id' => $member->member_id,
                    'full_name' => $member->full_name,
                    'email' => $member->email,
                    'phone' => $member->phone,
                    'membership_plan' => $plan->name,
                    'expiry_date' => $member->expiry_date->format('Y-m-d'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add member: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all active members for dropdown
     */
    public function getActiveMembers()
    {
        try {
            $members = Member::where('is_archived', 0)
                ->where('status', 'Active')
                ->select('id', 'member_id', 'full_name', 'email')
                ->orderBy('full_name')
                ->get();

            return response()->json([
                'success' => true,
                'members' => $members
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch members: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Renew membership
     */
    public function renew(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'membership_plan_id' => 'required|exists:membership_plans,id',
            'renewal_period' => 'required|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $member = Member::findOrFail($id);

            if ($member->is_archived) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member account is archived'
                ], 422);
            }

            $plan = MembershipPlan::findOrFail($request->membership_plan_id);

            // Calculate new expiry date
            $currentExpiry = $member->expiry_date ? Carbon::parse($member->expiry_date) : Carbon::now();
            $newExpiry = $currentExpiry->addMonths($request->renewal_period);

            // Update member
            $member->update([
                'membership_plan_id' => $request->membership_plan_id,
                'expiry_date' => $newExpiry,
                'status' => 'Active',
                'payment_status' => 'Paid',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Membership renewed successfully',
                'member' => [
                    'id' => $member->id,
                    'full_name' => $member->full_name,
                    'membership_plan' => $plan->name,
                    'expiry_date' => $newExpiry->format('Y-m-d'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to renew membership: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get members with expiring memberships
     */
    public function getExpiringMembers()
    {
        try {
            $expiring = Member::where('is_archived', 0)
                ->where('status', 'Active')
                ->where('expiry_date', '<=', Carbon::now()->addDays(30))
                ->where('expiry_date', '>=', Carbon::now()->startOfDay())
                ->with('membershipPlan')
                ->select('id', 'member_id', 'full_name', 'expiry_date', 'membership_plan_id')
                ->orderBy('expiry_date', 'asc')
                ->limit(10)
                ->get();

            $formatted = $expiring->map(function ($member) {
                return [
                    'id' => $member->id,
                    'member_id' => $member->member_id,
                    'full_name' => $member->full_name,
                    'expiry_date' => $member->expiry_date->format('Y-m-d'),
                    'initials' => $this->getInitials($member->full_name),
                ];
            });

            return response()->json([
                'success' => true,
                'members' => $formatted
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch expiring members: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate unique member ID
     */
    private function generateMemberId()
    {
        do {
            $memberId = 'MEM' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (Member::where('member_id', $memberId)->exists());

        return $memberId;
    }

    /**
     * Get member initials for avatar
     */
    private function getInitials($name)
    {
        $names = explode(' ', $name);
        if (count($names) >= 2) {
            return strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }
}
