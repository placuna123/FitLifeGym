<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\Notification;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * FitGym's own income: what each branch pays for its subscription. The Super Admin
 * records a payment and the branch's plan and end date move forward with it.
 */
class PlatformBillingController extends Controller
{
    private const METHODS = ['Cash', 'GCash', 'Bank Transfer', 'Other'];

    public function index(Request $request)
    {
        $now = Carbon::now('Asia/Manila');
        $gymNames = Gym::withoutGlobalScopes()->pluck('name', 'id');

        $payments = SubscriptionPayment::query()
            ->when($request->filled('gym_id'), fn ($q) => $q->where('gym_id', $request->gym_id))
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'month' => $now->format('F Y'),
            'totals' => [
                'all_time' => (float) SubscriptionPayment::sum('amount'),
                'this_month' => (float) SubscriptionPayment::whereBetween('paid_on', [$now->copy()->startOfMonth()->toDateString(), $now->copy()->endOfMonth()->toDateString()])->sum('amount'),
                'payments' => SubscriptionPayment::count(),
            ],
            'plan_prices' => collect(config('fitgym.plans', []))->map(fn ($p) => (float) ($p['price'] ?? 0)),
            'methods' => self::METHODS,
            'payment_details' => PlatformSetting::paymentDetails(),
            // Receipts branches sent: waiting ones first, then the last few reviewed.
            'proofs' => SubscriptionPaymentRequest::query()
                ->orderByRaw("CASE WHEN status = 'Pending' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->limit(30)
                ->get()
                ->map(fn (SubscriptionPaymentRequest $r) => [
                    'id' => $r->id,
                    'gym_id' => $r->gym_id,
                    'gym_name' => $gymNames[$r->gym_id] ?? 'Deleted branch',
                    'plan' => $r->plan,
                    'months' => $r->months,
                    'amount' => (float) $r->amount,
                    'payment_method' => $r->payment_method,
                    'reference' => $r->reference,
                    'receipt_url' => $r->receiptUrl(),
                    'notes' => $r->notes,
                    'status' => $r->status,
                    'review_note' => $r->review_note,
                    'sent_on' => $r->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A'),
                ]),
            'pending_proofs' => SubscriptionPaymentRequest::where('status', 'Pending')->count(),
            'payments' => $payments->map(fn (SubscriptionPayment $p) => [
                'id' => $p->id,
                'gym_id' => $p->gym_id,
                'gym_name' => $gymNames[$p->gym_id] ?? 'Deleted branch',
                'plan' => $p->plan,
                'months' => $p->months,
                'amount' => (float) $p->amount,
                'payment_method' => $p->payment_method,
                'reference' => $p->reference,
                'paid_on' => $p->paid_on->format('M j, Y'),
                'period' => $p->period_start->format('M j, Y') . ' – ' . $p->period_end->format('M j, Y'),
                'notes' => $p->notes,
            ]),
        ]);
    }

    /** Where branches send their subscription payment (shown on every Admin's Subscription page). */
    public function savePaymentDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gcash_number' => 'nullable|string|max:20',
            'gcash_name' => 'nullable|string|max:120',
            'bank_details' => 'nullable|string|max:500',
            'instructions' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        foreach (['gcash_number', 'gcash_name', 'bank_details', 'instructions'] as $field) {
            PlatformSetting::setValue("payment_{$field}", trim((string) $request->input($field, '')) ?: null);
        }
        AuditLog::record('platform.payment_details_updated', 'Updated where branches pay their FitGym subscription', ['type' => 'PlatformSetting', 'id' => null]);

        return response()->json(['success' => true, 'message' => 'Payment details saved. Branch Admins see them on their Subscription page.', 'payment_details' => PlatformSetting::paymentDetails()]);
    }

    /** Record what a branch paid; its plan becomes active and its end date moves forward. */
    public function store(Request $request, $gymId)
    {
        $gym = Gym::where('id', $gymId)->where('is_archived', 0)->first();
        if (! $gym) {
            return response()->json(['success' => false, 'message' => 'Branch not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'plan' => 'required|in:basic,pro',
            'months' => 'required|integer|min:1|max:24',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:' . implode(',', self::METHODS),
            'reference' => 'nullable|string|max:100',
            'paid_on' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ], [
            'plan.in' => 'Choose the Basic or Pro plan.',
            'months.min' => 'Enter at least 1 month.',
            'amount.required' => 'Enter the amount received.',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $payment = $this->applyPayment($gym, $request->only(['plan', 'months', 'amount', 'payment_method', 'reference', 'paid_on', 'notes']));

        return response()->json([
            'success' => true,
            'message' => "Payment recorded. {$gym->name} is active on " . ucfirst($payment->plan) . ' until ' . $payment->period_end->format('M j, Y') . '.',
        ], 201);
    }

    /** Approve a branch's receipt: it becomes a recorded payment, exactly like Record Payment. */
    public function approveProof($id)
    {
        $proof = SubscriptionPaymentRequest::where('id', $id)->where('status', 'Pending')->first();
        if (! $proof) {
            return response()->json(['success' => false, 'message' => 'This payment was already reviewed or no longer exists.'], 404);
        }
        $gym = Gym::where('id', $proof->gym_id)->where('is_archived', 0)->first();
        if (! $gym) {
            return response()->json(['success' => false, 'message' => 'This branch was archived. Restore it first.'], 422);
        }

        $payment = $this->applyPayment($gym, [
            'plan' => $proof->plan,
            'months' => $proof->months,
            'amount' => $proof->amount,
            'payment_method' => $proof->payment_method,
            'reference' => $proof->reference,
            'paid_on' => $proof->created_at->timezone('Asia/Manila')->toDateString(),
            'notes' => $proof->notes,
        ]);
        $proof->update([
            'status' => 'Approved',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'subscription_payment_id' => $payment->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Approved. {$gym->name} is active on " . ucfirst($payment->plan) . ' until ' . $payment->period_end->format('M j, Y') . '.',
        ]);
    }

    /** Reject a receipt (wrong amount, unreadable, not received) and tell the branch why. */
    public function rejectProof(Request $request, $id)
    {
        $proof = SubscriptionPaymentRequest::where('id', $id)->where('status', 'Pending')->first();
        if (! $proof) {
            return response()->json(['success' => false, 'message' => 'This payment was already reviewed or no longer exists.'], 404);
        }
        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:500'], ['reason.required' => 'Tell the branch why the payment was not accepted.']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $reason = trim($request->reason);
        $proof->update(['status' => 'Rejected', 'review_note' => $reason, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()]);
        $gymName = Gym::withoutGlobalScopes()->where('id', $proof->gym_id)->value('name') ?? 'Branch';

        Notification::withoutGlobalScopes()->create([
            'gym_id' => $proof->gym_id,
            'member_id' => null,
            'title' => 'Subscription Payment Not Accepted',
            'message' => 'FitGym could not accept your ' . $proof->payment_method . ' payment (ref ' . $proof->reference . "). Reason: {$reason} You can send it again from Settings → Subscription.",
            'type' => 'red',
            'is_read' => 0,
            'read_at' => null,
            'is_archived' => 0,
        ]);
        AuditLog::record('subscription.proof_rejected', "Rejected {$gymName}'s payment (ref {$proof->reference}): {$reason}", ['type' => 'SubscriptionPaymentRequest', 'id' => $proof->id]);

        return response()->json(['success' => true, 'message' => "Rejected. {$gymName}'s Admin was told why."]);
    }

    /** Save a payment, move the branch's plan and end date forward, and notify its Admin. */
    private function applyPayment(Gym $gym, array $data): SubscriptionPayment
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $currentEnd = $gym->subscription_expires_at ? Carbon::parse($gym->subscription_expires_at->toDateString(), 'Asia/Manila') : null;
        // Paying early extends from the current end date; otherwise the new period starts today.
        $start = $currentEnd && $currentEnd->gte($today) && $gym->subscription_status === 'active'
            ? $currentEnd->copy()->addDay()
            : $today->copy();
        $months = (int) $data['months'];
        $end = $start->copy()->addMonthsNoOverflow($months)->subDay();
        $amount = (float) $data['amount'];

        $payment = DB::transaction(function () use ($data, $gym, $start, $end, $months, $amount, $today) {
            $payment = SubscriptionPayment::create([
                'gym_id' => $gym->id,
                'plan' => $data['plan'],
                'months' => $months,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference' => ($data['reference'] ?? null) ?: null,
                'paid_on' => ($data['paid_on'] ?? null) ?: $today->toDateString(),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'notes' => ($data['notes'] ?? null) ?: null,
                'recorded_by' => Auth::id(),
            ]);

            $gym->update([
                'subscription_plan' => $data['plan'],
                'subscription_status' => 'active',
                'subscription_expires_at' => $end->toDateString(),
            ]);

            // Let the branch Admin know (their Notifications panel).
            Notification::withoutGlobalScopes()->create([
                'gym_id' => $gym->id,
                'member_id' => null,
                'title' => 'Subscription Payment Received',
                'message' => 'FitGym received PHP ' . number_format($amount, 2) . " for {$months} month" . ($months === 1 ? '' : 's')
                    . ' of the ' . ucfirst($data['plan']) . ' plan. Your subscription now runs until ' . $end->format('M j, Y') . '.',
                'type' => 'green',
                'is_read' => 0,
                'read_at' => null,
                'is_archived' => 0,
            ]);

            return $payment;
        });

        AuditLog::record(
            'subscription.paid',
            "{$gym->name} paid PHP " . number_format($amount, 2) . " for {$months} month(s) of " . ucfirst($data['plan']) . ' (until ' . $end->format('M j, Y') . ')',
            ['type' => 'SubscriptionPayment', 'id' => $payment->id]
        );

        return $payment;
    }
}
