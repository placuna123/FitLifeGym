<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use App\Models\Member;
use App\Models\PlatformNotification;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRequest;
use App\Models\User;
use Carbon\Carbon;

/**
 * Super Admin dashboard (platform overview) and notification bell.
 * Unscoped by design: only runs behind role:SuperAdmin.
 */
class PlatformDashboardController extends Controller
{
    /** Days before a trial or subscription ends that the Super Admin is told. */
    private const EXPIRY_NOTICE_DAYS = 7;

    public function dashboard()
    {
        $now = Carbon::now('Asia/Manila');
        $today = $now->copy()->startOfDay();
        $gyms = Gym::where('is_archived', 0)->get();

        // Branches per status as it applies today (a passed end date counts as expired)
        $statusCounts = ['trial' => 0, 'active' => 0, 'pending' => 0, 'expired' => 0, 'suspended' => 0, 'cancelled' => 0];
        foreach ($gyms as $gym) {
            $status = $gym->effectiveStatus();
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        // Trials and subscriptions that end within the next 7 days, soonest first
        $expiring = $gyms
            ->filter(fn (Gym $g) => in_array($g->effectiveStatus(), ['trial', 'active', 'pending'], true)
                && $g->subscription_expires_at
                && $g->subscription_expires_at->copy()->startOfDay()->lte($today->copy()->addDays(self::EXPIRY_NOTICE_DAYS)))
            ->sortBy(fn (Gym $g) => $g->subscription_expires_at->timestamp)
            ->values()
            ->map(fn (Gym $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'status' => $g->effectiveStatus(),
                'expires_at' => $g->subscription_expires_at->toDateString(),
                'days_left' => (int) $today->diffInDays($g->subscription_expires_at->copy()->startOfDay(), false),
            ]);

        // Last 6 months: new branches and what branches paid FitGym
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = $now->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $end = $start->copy()->endOfMonth();
            $months[] = [
                'label' => $start->format('M'),
                'month' => $start->format('Y-m'),
                'new_branches' => Gym::whereBetween('created_at', [$start, $end])->count(),
                'income' => (float) SubscriptionPayment::whereBetween('paid_on', [$start->toDateString(), $end->toDateString()])->sum('amount'),
            ];
        }

        $owners = User::whereIn('id', $gyms->pluck('owner_user_id')->filter())->get()->keyBy('id');
        $recentBranches = $gyms->sortByDesc('created_at')->take(5)->values()->map(fn (Gym $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'admin_name' => $owners->get($g->owner_user_id)?->name,
            'plan' => $g->subscription_plan ?: 'trial',
            'status' => $g->effectiveStatus(),
            'created_at' => $g->created_at?->toDateString(),
        ]);

        $pendingProofs = SubscriptionPaymentRequest::where('status', 'Pending')->latest()->get();
        $gymNames = Gym::whereIn('id', $pendingProofs->pluck('gym_id'))->pluck('name', 'id');

        return response()->json([
            'success' => true,
            'totals' => [
                'branches' => $gyms->count(),
                'new_this_month' => $gyms->filter(fn (Gym $g) => $g->created_at && $g->created_at->gte($now->copy()->startOfMonth()))->count(),
                'members' => Member::withoutGlobalScopes()->where('is_archived', 0)->whereIn('gym_id', $gyms->pluck('id'))->count(),
                'income_this_month' => end($months)['income'],
                'income_all_time' => (float) SubscriptionPayment::sum('amount'),
                'pending_proofs' => $pendingProofs->count(),
            ],
            'status_counts' => $statusCounts,
            'months' => $months,
            'expiring' => $expiring,
            'recent_branches' => $recentBranches,
            'pending_proofs' => $pendingProofs->take(5)->values()->map(fn ($p) => [
                'id' => $p->id,
                'gym_name' => $gymNames[$p->gym_id] ?? 'Branch',
                'plan' => $p->plan,
                'months' => (int) $p->months,
                'amount' => (float) $p->amount,
                'payment_method' => $p->payment_method,
                'sent_on' => $p->created_at?->toDateString(),
            ]),
        ]);
    }

    /** Latest notifications and how many are unread. Adds any due expiry reminders first. */
    public function notifications()
    {
        $this->addExpiryReminders();

        $items = PlatformNotification::latest()->limit(30)->get()->map(fn (PlatformNotification $n) => [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'message' => $n->message,
            'tab' => $n->tab,
            'is_read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'notifications' => $items,
            'unread' => PlatformNotification::whereNull('read_at')->count(),
        ]);
    }

    public function markRead(int $id)
    {
        $notification = PlatformNotification::find($id);
        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    public function markAllRead()
    {
        PlatformNotification::whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * One reminder per branch per end date, once it is 7 days or less away.
     * Done here instead of in the scheduler so it works without a cron job.
     */
    private function addExpiryReminders(): void
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();

        Gym::where('is_archived', 0)
            ->whereNotNull('subscription_expires_at')
            ->whereIn('subscription_status', ['trial', 'active', 'pending'])
            ->whereDate('subscription_expires_at', '>=', $today->toDateString())
            ->whereDate('subscription_expires_at', '<=', $today->copy()->addDays(self::EXPIRY_NOTICE_DAYS)->toDateString())
            ->get()
            ->each(function (Gym $gym) use ($today) {
                $date = $gym->subscription_expires_at;
                $days = (int) $today->diffInDays($date->copy()->startOfDay(), false);
                $what = $gym->subscription_status === 'trial' ? 'free trial' : 'subscription';
                $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days ({$date->format('M d')})");

                PlatformNotification::notify(
                    'expiry',
                    "{$gym->name}: {$what} ending",
                    "The {$what} of {$gym->name} ends {$when}.",
                    $gym->id,
                    'branches',
                    "expiry:{$gym->id}:{$date->toDateString()}",
                );
            });
    }
}
