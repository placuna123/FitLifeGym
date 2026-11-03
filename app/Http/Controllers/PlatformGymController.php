<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Trainer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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
    /** Active branches, or the archived ones with ?archived=1 (so they can be restored). */
    public function index(Request $request)
    {
        try {
            $gyms = Gym::where('is_archived', $request->boolean('archived') ? 1 : 0)
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
            'admin_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        try {
            $gym = Gym::findOrFail($id);
            $previousStatus = $gym->subscription_status;

            // The branch Admin's display name (e.g. it was left as their email address).
            if ($request->filled('admin_name') && $gym->owner_user_id) {
                User::where('id', $gym->owner_user_id)->update(['name' => trim((string) $request->admin_name)]);
            }

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

    /** Bring an archived branch back; its Admin can sign in again. */
    public function restore($id)
    {
        $gym = Gym::where('id', $id)->where('is_archived', 1)->first();
        if (! $gym) {
            return response()->json(['success' => false, 'message' => 'Archived branch not found'], 404);
        }

        $gym->update(['is_archived' => 0]);
        AuditLog::record('gym.restored', "Restored gym {$gym->name}", ['type' => 'Gym', 'id' => $gym->id]);

        return response()->json(['success' => true, 'message' => "{$gym->name} was restored.", 'gym' => $this->withCounts($gym->fresh())]);
    }

    /** Email the branch Admin the same "set a new password" link as Forgot Password. */
    public function sendAdminReset($id)
    {
        $gym = Gym::findOrFail($id);
        $admin = $gym->owner_user_id ? User::find($gym->owner_user_id) : null;
        if (! $admin || ! $admin->email) {
            return response()->json(['success' => false, 'message' => 'This branch has no Admin account to send a link to.'], 422);
        }

        $status = Password::broker()->sendResetLink(['email' => $admin->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json(['success' => false, 'message' => __($status)], 422);
        }

        AuditLog::record('gym.admin_reset_sent', "Sent a password reset link to {$admin->email} ({$gym->name})", ['type' => 'Gym', 'id' => $gym->id]);

        return response()->json(['success' => true, 'message' => "A password reset link was emailed to {$admin->email}."]);
    }

    /**
     * Send a message to the Admin of every branch (or one branch); it shows in their
     * Notifications panel.
     */
    public function announce(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:120',
            'message' => 'required|string|max:1000',
            'gym_id' => 'nullable|integer|exists:gyms,id',
        ], [
            'title.required' => 'Give the announcement a title.',
            'message.required' => 'Write the announcement message.',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $gyms = Gym::where('is_archived', 0)
            ->when($request->filled('gym_id'), fn ($q) => $q->where('id', $request->gym_id))
            ->get();

        foreach ($gyms as $gym) {
            Notification::withoutGlobalScopes()->create([
                'gym_id' => $gym->id,
                'member_id' => null, // the branch Admin's notification center
                'title' => 'From FitGym: ' . $request->title,
                'message' => $request->message,
                'type' => 'blue',
                'is_read' => 0,
                'read_at' => null,
                'is_archived' => 0,
            ]);
        }

        $where = $request->filled('gym_id') ? $gyms->first()?->name : 'all branches';
        AuditLog::record('platform.announcement', "Announcement \"{$request->title}\" sent to {$where}", ['type' => 'Announcement', 'id' => null]);

        return response()->json(['success' => true, 'message' => "Sent to {$where}.", 'sent_to' => $gyms->count()]);
    }

    /**
     * Cross-branch analytics: platform totals + a per-gym comparison table.
     * "Revenue" is what members paid the branches (Paid payments), not platform income.
     */
    public function analytics()
    {
        $gyms = Gym::where('is_archived', 0)->get();
        $now = Carbon::now('Asia/Manila');
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $today = $now->toDateString();

        $perGym = $gyms->map(function (Gym $gym) use ($monthStart, $monthEnd, $today) {
            $members = Member::withoutGlobalScopes()->where('gym_id', $gym->id)->where('is_archived', 0);
            $paid = Payment::withoutGlobalScopes()->where('gym_id', $gym->id)->where('is_archived', 0)->where('status', 'Paid');

            return [
                'id' => $gym->id,
                'name' => $gym->name,
                'subscription_plan' => $gym->subscription_plan,
                'subscription_status' => $gym->effectiveStatus(),
                'members' => (clone $members)->count(),
                'active_members' => (clone $members)->where('status', 'Active')->where('payment_status', 'Paid')->whereDate('expiry_date', '>=', $today)->count(),
                'expired_members' => (clone $members)->where('status', 'Active')->whereNotNull('expiry_date')->whereDate('expiry_date', '<', $today)->count(),
                'awaiting_approval' => (clone $members)->where('status', 'Pending')->count(),
                'trainers' => Trainer::withoutGlobalScopes()->where('gym_id', $gym->id)->where('is_archived', 0)->count(),
                'revenue' => (float) (clone $paid)->sum('amount'),
                'revenue_month' => (float) (clone $paid)->whereBetween('payment_date', [$monthStart->toDateString(), $monthEnd->toDateString()])->sum('amount'),
                'pending_payments' => Payment::withoutGlobalScopes()->where('gym_id', $gym->id)->where('is_archived', 0)->where('status', 'Pending')->count(),
                'checkins_month' => DB::table('attendance')->where('gym_id', $gym->id)->where('is_archived', 0)
                    ->whereBetween('check_in_time', [$monthStart->copy()->utc(), $monthEnd->copy()->utc()])->count(),
            ];
        })->sortByDesc('revenue_month')->values();

        return response()->json([
            'success' => true,
            'month' => $now->format('F Y'),
            'totals' => [
                'gyms' => $gyms->count(),
                'members' => (int) $perGym->sum('members'),
                'active_members' => (int) $perGym->sum('active_members'),
                'trainers' => (int) $perGym->sum('trainers'),
                'revenue' => (float) $perGym->sum('revenue'),
                'revenue_month' => (float) $perGym->sum('revenue_month'),
                'pending_payments' => (int) $perGym->sum('pending_payments'),
                'checkins_month' => (int) $perGym->sum('checkins_month'),
            ],
            'branches' => $perGym,
        ]);
    }

    private function withCounts(Gym $gym): array
    {
        $owner = $gym->owner_user_id ? User::find($gym->owner_user_id) : null;

        return [
            'id' => $gym->id,
            'name' => $gym->name,
            'slug' => $gym->slug,
            'email' => $gym->email,
            'phone' => $gym->phone,
            'address' => $gym->address,
            'owner_user_id' => $gym->owner_user_id,
            // who runs this branch, so the Super Admin knows whom to contact
            'admin' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
            'created_on' => $gym->created_at?->copy()->setTimezone('Asia/Manila')->format('M j, Y'),
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
