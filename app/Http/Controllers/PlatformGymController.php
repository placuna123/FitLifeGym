<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Super Admin's view of every gym on the platform — full branch management
 * (create/edit/assign admin/delete) plus approve/suspend/subscription
 * control. Unscoped by design: Gym has no gym_id of its own (it IS the
 * tenant), and this controller only runs behind role:SuperAdmin.
 */
class PlatformGymController extends Controller
{
    public function index()
    {
        try {
            $gyms = Gym::where('is_archived', 0)
                ->orderBy('name')
                ->get()
                ->map(fn (Gym $gym) => $this->withCounts($gym));

            return response()->json([
                'success' => true,
                'gyms' => $gyms,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load gyms: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $gym = Gym::where('id', $id)->where('is_archived', 0)->first();
        if (! $gym) {
            return response()->json(['success' => false, 'message' => 'Gym not found'], 404);
        }

        return response()->json([
            'success' => true,
            'gym' => $this->withCounts($gym),
        ]);
    }

    /**
     * Super Admin directly onboards a new branch/gym (skips the self-service
     * trial signup) and assigns it an Admin in the same step.
     */
    public function store(Request $request)
    {
        $normalizedEmail = strtolower(trim((string) $request->input('admin_email', '')));
        $request->merge(['admin_email' => $normalizedEmail]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:8',
            'subscription_plan' => 'nullable|string|in:trial,basic,pro',
            'subscription_status' => 'nullable|string|in:trial,pending,active,suspended,expired,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $gym = DB::transaction(function () use ($request, $normalizedEmail) {
                $base = Str::slug($request->name) ?: 'gym';
                $slug = $base;
                $suffix = 1;
                while (Gym::where('slug', $slug)->exists()) {
                    $suffix++;
                    $slug = $base . '-' . $suffix;
                }

                $status = $request->subscription_status ?? 'trial';
                $gym = Gym::create([
                    'name' => $request->name,
                    'slug' => $slug,
                    'subscription_plan' => $request->subscription_plan ?? 'trial',
                    'subscription_status' => $status,
                    'subscription_expires_at' => $status === 'trial'
                        ? now()->addDays((int) config('fitgym.trial_days', 14))->toDateString()
                        : null,
                    'is_archived' => 0,
                ]);

                $admin = User::create([
                    'name' => $request->admin_name,
                    'email' => $normalizedEmail,
                    'password' => Hash::make($request->admin_password),
                    'role' => 'Admin',
                    'gym_id' => $gym->id,
                    'is_archived' => 0,
                ]);

                $gym->update(['owner_user_id' => $admin->id]);

                return $gym;
            });

            AuditLog::record('gym.created', "Super Admin created gym {$gym->name}", ['type' => 'Gym', 'id' => $gym->id]);

            if ($admin = User::find($gym->owner_user_id)) {
                \App\Support\Email::send($admin, new \App\Notifications\AccountInvite('Admin', $gym->name, \App\Support\Email::setPasswordUrl($admin)));
            }

            return response()->json([
                'success' => true,
                'message' => 'Gym created successfully',
                'gym' => $this->withCounts($gym->fresh()),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to create gym: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'subscription_plan' => 'nullable|string|in:trial,basic,pro',
            'subscription_status' => 'nullable|string|in:trial,pending,active,suspended,expired,cancelled',
            'subscription_expires_at' => 'nullable|date',
            'admin_user_id' => 'nullable|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $gym = Gym::findOrFail($id);
            $previousStatus = $gym->subscription_status;

            $gym->update(array_filter([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'subscription_plan' => $request->subscription_plan,
                'subscription_status' => $request->subscription_status,
            ], fn ($value) => $value !== null));

            // The end date can be set or cleared (empty = no end date).
            if ($request->exists('subscription_expires_at')) {
                $gym->update(['subscription_expires_at' => $request->input('subscription_expires_at') ?: null]);
            } elseif (in_array($request->subscription_status, ['active', 'trial'], true)
                && $gym->subscription_expires_at
                && $gym->subscription_expires_at->copy()->endOfDay()->isPast()) {
                // Re-activating a lapsed branch shouldn't leave it locked by its old end date.
                $gym->update(['subscription_expires_at' => null]);
            }

            // Reassign which User owns/administers this branch.
            if ($request->filled('admin_user_id')) {
                User::where('id', $request->admin_user_id)->update(['gym_id' => $gym->id, 'role' => 'Admin']);
                $gym->update(['owner_user_id' => $request->admin_user_id]);
            }

            if ($request->filled('subscription_status') && $request->subscription_status !== $previousStatus) {
                AuditLog::record(
                    'gym.subscription_changed',
                    "Gym {$gym->name} subscription set to {$request->subscription_status}",
                    ['type' => 'Gym', 'id' => $gym->id]
                );
            } else {
                AuditLog::record('gym.updated', "Updated gym {$gym->name}", ['type' => 'Gym', 'id' => $gym->id]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Gym updated successfully',
                'gym' => $this->withCounts($gym->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update gym: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $gym = Gym::findOrFail($id);
            $gym->update(['is_archived' => 1]);

            AuditLog::record('gym.deleted', "Archived gym {$gym->name}", ['type' => 'Gym', 'id' => $gym->id]);

            return response()->json(['success' => true, 'message' => 'Gym archived successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete gym: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cross-branch analytics: platform totals + a per-gym comparison table.
     */
    public function analytics()
    {
        $gyms = Gym::where('is_archived', 0)->get();

        $perGym = $gyms->map(function (Gym $gym) {
            $memberCount = Member::withoutGlobalScopes()->where('gym_id', $gym->id)->where('is_archived', 0)->count();
            $trainerCount = Trainer::withoutGlobalScopes()->where('gym_id', $gym->id)->where('is_archived', 0)->count();
            $revenue = Payment::withoutGlobalScopes()->where('gym_id', $gym->id)->where('is_archived', 0)->where('status', 'Paid')->sum('amount');

            return [
                'id' => $gym->id,
                'name' => $gym->name,
                'subscription_plan' => $gym->subscription_plan,
                'subscription_status' => $gym->effectiveStatus(),
                'members' => $memberCount,
                'trainers' => $trainerCount,
                'revenue' => (float) $revenue,
            ];
        })->sortByDesc('revenue')->values();

        return response()->json([
            'success' => true,
            'totals' => [
                'gyms' => $gyms->count(),
                'members' => (int) $perGym->sum('members'),
                'trainers' => (int) $perGym->sum('trainers'),
                'revenue' => (float) $perGym->sum('revenue'),
            ],
            'branches' => $perGym,
        ]);
    }

    private function withCounts(Gym $gym): array
    {
        return [
            'id' => $gym->id,
            'name' => $gym->name,
            'slug' => $gym->slug,
            'email' => $gym->email,
            'phone' => $gym->phone,
            'address' => $gym->address,
            'owner_user_id' => $gym->owner_user_id,
            'subscription_plan' => $gym->subscription_plan,
            'subscription_status' => $gym->subscription_status,
            'effective_status' => $gym->effectiveStatus(),
            'subscription_expires_at' => $gym->subscription_expires_at?->toDateString(),
            'member_count' => $gym->activeCount('members'),
            'trainer_count' => $gym->activeCount('staff'),
            'member_limit' => $gym->planLimit('members'),
            'staff_limit' => $gym->planLimit('staff'),
            'created_at' => $gym->created_at,
        ];
    }
}
