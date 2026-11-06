<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRequest;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * A branch Admin's view of their own FitGym subscription: plan, end date, usage
 * against the plan's limits, prices, where to pay, and what they have paid.
 */
class GymSubscriptionController extends Controller
{
    public function show()
    {
        $gym = Gym::find(Tenancy::gymId());
        if (! $gym) {
            return response()->json(['success' => false, 'message' => 'No branch found for this account.'], 404);
        }

        $today = Carbon::now('Asia/Manila')->startOfDay();
        $end = $gym->subscription_expires_at ? Carbon::parse($gym->subscription_expires_at->toDateString(), 'Asia/Manila') : null;
        $plans = config('fitgym.plans', []);

        return response()->json([
            'success' => true,
            'subscription' => [
                'plan' => $gym->subscription_plan ?: 'trial',
                'plan_label' => $plans[$gym->subscription_plan ?: 'trial']['label'] ?? ucfirst((string) $gym->subscription_plan),
                'status' => $gym->effectiveStatus(),
                'ends_on' => $end?->format('M j, Y'),
                'days_left' => $end ? (int) $today->diffInDays($end, false) : null,
                'members' => ['used' => $gym->activeCount('members'), 'limit' => $gym->planLimit('members')],
                'staff' => ['used' => $gym->activeCount('staff'), 'limit' => $gym->planLimit('staff')],
            ],
            'plans' => collect($plans)->except('trial')->map(fn ($p, $key) => [
                'key' => $key,
                'label' => $p['label'],
                'price' => (float) ($p['price'] ?? 0),
                'members' => $p['members'],
                'staff' => $p['staff'],
            ])->values(),
            'payment_details' => PlatformSetting::paymentDetails(),
            'methods' => SubscriptionPaymentRequest::METHODS,
            'proofs' => SubscriptionPaymentRequest::where('gym_id', $gym->id)->orderByDesc('id')->limit(10)->get()
                ->map(fn (SubscriptionPaymentRequest $r) => [
                    'id' => $r->id,
                    'sent_on' => $r->created_at->timezone('Asia/Manila')->format('M j, Y'),
                    'plan' => ucfirst($r->plan),
                    'months' => $r->months,
                    'amount' => (float) $r->amount,
                    'payment_method' => $r->payment_method,
                    'reference' => $r->reference,
                    'status' => $r->status,
                    'review_note' => $r->review_note,
                ]),
            'payments' => SubscriptionPayment::where('gym_id', $gym->id)->orderByDesc('paid_on')->orderByDesc('id')->get()
                ->map(fn (SubscriptionPayment $p) => [
                    'id' => $p->id,
                    'paid_on' => $p->paid_on->format('M j, Y'),
                    'plan' => ucfirst($p->plan),
                    'months' => $p->months,
                    'amount' => (float) $p->amount,
                    'payment_method' => $p->payment_method,
                    'period' => $p->period_start->format('M j, Y') . ' – ' . $p->period_end->format('M j, Y'),
                ]),
        ]);
    }

    /** The Admin sends FitGym their GCash/bank receipt; the Super Admin approves it in Billing. */
    public function submitProof(Request $request)
    {
        $gym = Gym::find(Tenancy::gymId());
        if (! $gym) {
            return response()->json(['success' => false, 'message' => 'No branch found for this account.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'plan' => 'required|in:basic,pro',
            'months' => 'required|integer|min:1|max:24',
            'payment_method' => 'required|in:' . implode(',', SubscriptionPaymentRequest::METHODS),
            'reference' => 'required|string|max:100',
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'notes' => 'nullable|string|max:500',
        ], [
            'plan.required' => 'Choose the plan you paid for.',
            'plan.in' => 'Choose the Basic or Pro plan.',
            'months.min' => 'Enter at least 1 month.',
            'months.max' => 'You can pay for up to 24 months at a time.',
            'reference.required' => 'Enter the reference number from your GCash or bank receipt.',
            'receipt.required' => 'Attach a photo or PDF of your receipt.',
            'receipt.mimes' => 'The receipt must be a JPG, PNG or PDF file.',
            'receipt.max' => 'The receipt file must be 5 MB or smaller.',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        if (SubscriptionPaymentRequest::where('gym_id', $gym->id)->where('status', 'Pending')->exists()) {
            return response()->json(['success' => false, 'message' => 'You already sent a payment that FitGym is still checking. Please wait for it to be approved.'], 422);
        }

        $months = (int) $request->months;
        $price = (float) (config("fitgym.plans.{$request->plan}.price") ?? 0);

        $proof = SubscriptionPaymentRequest::create([
            'gym_id' => $gym->id,
            'plan' => $request->plan,
            'months' => $months,
            'amount' => $price * $months,
            'payment_method' => $request->payment_method,
            'reference' => trim($request->reference),
            'receipt_path' => $request->file('receipt')->store('subscription-receipts', 'public'),
            'notes' => $request->notes ?: null,
            'status' => 'Pending',
            'submitted_by' => Auth::id(),
        ]);

        AuditLog::record(
            'subscription.proof_sent',
            "{$gym->name} sent proof of a PHP " . number_format((float) $proof->amount, 2) . " {$request->payment_method} payment for {$months} month(s) of " . ucfirst($request->plan),
            ['type' => 'SubscriptionPaymentRequest', 'id' => $proof->id]
        );

        \App\Models\PlatformNotification::notify(
            'payment',
            "Payment receipt from {$gym->name}",
            "{$gym->name} sent a PHP " . number_format((float) $proof->amount, 2) . " {$request->payment_method} receipt for {$months} month(s) of " . ucfirst($request->plan) . ". Check it in Billing.",
            $gym->id,
            'billing',
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment sent. FitGym will check your receipt, and your plan and end date update once it is approved.',
        ], 201);
    }
}
