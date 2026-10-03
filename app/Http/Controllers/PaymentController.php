<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Payment;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Services\SmsService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{
    /**
     * List payments for the authenticated member (Member Portal).
     */
    public function indexForMember(Request $request)
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        try {
            $payments = Payment::where('member_id', $member->id)
                ->where('is_archived', 0)
                ->with('membershipPlan')
                ->orderBy('payment_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'invoice_number' => $p->invoice_number,
                    'plan_name' => $p->membershipPlan?->name ?? 'N/A',
                    'amount' => number_format((float) $p->amount, 2),
                    'payment_method' => $p->payment_method,
                    'payment_date' => $p->payment_date?->format('Y-m-d'),
                    'payment_date_formatted' => $p->payment_date?->format('M j, Y'),
                    'status' => $p->status,
                    'receipt_proof_url' => $p->receipt_proof_path ? Storage::url($p->receipt_proof_path) : null,
                ]);
            return response()->json(['success' => true, 'payments' => $payments]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load payments: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Create a payment for the authenticated member (Member Portal – renew/subscribe).
     */
    public function storeForMember(Request $request)
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        // Members can only self-submit GCash (verifiable via receipt proof + admin
        // review). Cash/Card/etc. can't be verified remotely, so those must be
        // recorded by staff in person via the Admin/Trainer "Add Payment" flow —
        // otherwise a member could submit any other method here and it would land
        // as "Paid" immediately with nobody having actually received the money.
        $validator = Validator::make($request->all(), [
            'membership_plan_id' => 'required|exists:membership_plans,id',
            'payment_method' => 'required|in:GCash',
            'receipt_proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $plan = MembershipPlan::find($request->membership_plan_id);
        if (! $plan) {
            return response()->json(['success' => false, 'message' => 'Membership plan not found.'], 404);
        }
        try {
            DB::beginTransaction();
            $invoiceNumber = $this->generateInvoiceNumber();
            $receiptPath = $request->file('receipt_proof')->store('payment-proofs', 'public');
            $payment = Payment::create([
                'invoice_number' => $invoiceNumber,
                'member_id' => $member->id,
                'membership_plan_id' => $plan->id,
                // Derived from the plan's own price, never trusted from the client —
                // otherwise a member could POST amount=1 for an expensive plan.
                'amount' => $plan->price,
                'payment_method' => 'GCash',
                'payment_date' => now()->format('Y-m-d'),
                'status' => 'Pending',
                'notes' => 'Member portal GCash payment - awaiting admin verification',
                'receipt_proof_path' => $receiptPath,
                'is_archived' => 0,
            ]);
            // Admin alert so the receipt gets verified (member_id null = admin notification center).
            \App\Models\Notification::create([
                'gym_id' => $member->gym_id,
                'member_id' => null,
                'title' => 'Payment Received',
                'message' => "{$member->full_name} submitted a GCash payment of PHP " . number_format((float) $plan->price, 2) . " for {$plan->name} ({$invoiceNumber}). Verify the receipt on the Payments page.",
                'type' => 'green',
                'is_read' => 0,
                'read_at' => null,
                'is_archived' => 0,
            ]);
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'GCash payment submitted. Waiting for admin verification.',
                'payment' => [
                    'id' => $payment->id,
                    'invoice_number' => $payment->invoice_number,
                    'plan_name' => $payment->membershipPlan?->name ?? 'N/A',
                    'amount' => number_format((float) $payment->amount, 2),
                    'payment_method' => $payment->payment_method,
                    'payment_date' => $payment->payment_date?->format('Y-m-d'),
                    'status' => $payment->status,
                    'receipt_proof_url' => $payment->receipt_proof_path ? Storage::url($payment->receipt_proof_path) : null,
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $query = Payment::where('is_archived', 0)->with(['member', 'membershipPlan']);
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('member', fn ($mq) => $mq->where('full_name', 'like', "%{$search}%"));
                });
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }
            if ($request->filled('date_from')) {
                $query->where('payment_date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->where('payment_date', '<=', $request->date_to);
            }
            $payments = $query->orderBy('payment_date', 'desc')->orderBy('created_at', 'desc')->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'invoice_number' => $p->invoice_number,
                    'member_id' => $p->member_id,
                    'member_name' => $p->member?->full_name ?? 'N/A',
                    'membership_plan_id' => $p->membership_plan_id,
                    'plan_name' => $p->membershipPlan?->name ?? 'N/A',
                    'amount' => number_format((float) $p->amount, 2),
                    'payment_method' => $p->payment_method,
                    'payment_date' => $p->payment_date?->format('Y-m-d'),
                    'status' => $p->status,
                    'notes' => $p->notes,
                    'receipt_proof_url' => $p->receipt_proof_path ? Storage::url($p->receipt_proof_path) : null,
                    'created_at' => $p->created_at,
                ]);
            $totalRevenue = Payment::where('is_archived', 0)->where('status', 'Paid')->sum('amount');
            $pendingPayments = Payment::where('is_archived', 0)->where('status', 'Pending')->sum('amount');
            $overdueAmount = Payment::where('is_archived', 0)->where('status', 'Overdue')->sum('amount');
            return response()->json([
                'success' => true,
                'payments' => $payments,
                'summary' => [
                    'total_revenue' => number_format($totalRevenue, 2),
                    'pending_payments' => number_format($pendingPayments, 2),
                    'overdue_amount' => number_format($overdueAmount, 2),
                    'total_transactions' => Payment::where('is_archived', 0)->count(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load payments: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'member_id' => 'required|exists:members,id',
            'membership_plan_id' => 'nullable|exists:membership_plans,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:Cash,GCash,Credit Card,Bank Transfer,Other',
            'payment_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        // Derive the amount from the plan's price when a plan is given (this is an
        // Admin/Trainer-entered payment, e.g. cash at the front desk, so a plan-less
        // misc amount is still allowed — only skip trusting the client when we can
        // cross-check it against a real plan).
        $amount = $request->amount;
        if ($request->filled('membership_plan_id')) {
            $plan = MembershipPlan::find($request->membership_plan_id);
            if ($plan) {
                $amount = $plan->price;
            }
        }
        try {
            DB::beginTransaction();
            $invoiceNumber = $this->generateInvoiceNumber();
            $payment = Payment::create([
                'invoice_number' => $invoiceNumber,
                'member_id' => $request->member_id,
                'membership_plan_id' => $request->membership_plan_id,
                'amount' => $amount,
                'payment_method' => $request->payment_method,
                'payment_date' => $request->payment_date,
                // New payments always start Pending — they only become Paid through
                // the explicit Approve action, never straight from creation, so
                // there's always a distinct person confirming the payment is legit.
                'status' => 'Pending',
                'notes' => $request->notes,
                'is_archived' => 0,
            ]);
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'payment' => [
                    'id' => $payment->id,
                    'invoice_number' => $payment->invoice_number,
                    'member_name' => $payment->member?->full_name,
                    'plan_name' => $payment->membershipPlan?->name ?? 'N/A',
                    'amount' => number_format((float) $payment->amount, 2),
                    'payment_method' => $payment->payment_method,
                    'payment_date' => $payment->payment_date?->format('Y-m-d'),
                    'status' => $payment->status,
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $payment = Payment::where('id', $id)->where('is_archived', 0)->with(['member', 'membershipPlan'])->first();
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        return response()->json([
            'success' => true,
            'payment' => [
                'id' => $payment->id,
                'invoice_number' => $payment->invoice_number,
                'member_id' => $payment->member_id,
                'member_name' => $payment->member?->full_name,
                'member_email' => $payment->member?->email,
                'member_phone' => $payment->member?->phone,
                'membership_plan_id' => $payment->membership_plan_id,
                'plan_name' => $payment->membershipPlan?->name ?? 'N/A',
                'amount' => number_format((float) $payment->amount, 2),
                'payment_method' => $payment->payment_method,
                'payment_date' => $payment->payment_date?->format('Y-m-d'),
                'status' => $payment->status,
                'notes' => $payment->notes,
                'receipt_proof_url' => $payment->receipt_proof_path ? Storage::url($payment->receipt_proof_path) : null,
                'created_at' => $payment->created_at,
            ]
        ]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'member_id' => 'sometimes|required|exists:members,id',
            'membership_plan_id' => 'nullable|exists:membership_plans,id',
            'amount' => 'sometimes|required|numeric|min:0',
            'payment_method' => 'sometimes|required|in:Cash,GCash,Credit Card,Bank Transfer,Other',
            'payment_date' => 'sometimes|required|date',
            'status' => 'sometimes|required|in:Paid,Pending,Overdue,Cancelled',
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $payment = Payment::where('id', $id)->where('is_archived', 0)->first();
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        $wasPaid = $payment->status === 'Paid';
        $payment->update([
            'member_id' => $request->input('member_id', $payment->member_id),
            'membership_plan_id' => $request->input('membership_plan_id', $payment->membership_plan_id),
            'amount' => $request->input('amount', $payment->amount),
            'payment_method' => $request->input('payment_method', $payment->payment_method),
            'payment_date' => $request->input('payment_date', $payment->payment_date),
            'status' => $request->input('status', $payment->status),
            'notes' => $request->input('notes', $payment->notes),
        ]);

        // Approving a payment (e.g. verifying a member's GCash proof) should
        // actually extend their membership — this used to only flip the
        // payment row's own status and leave the member's expiry untouched.
        if (! $wasPaid && $payment->status === 'Paid' && $payment->member) {
            $this->applyPaidConsequences($payment);
        }

        if ($payment->wasChanged('status')) {
            \App\Models\AuditLog::record(
                'payment.status_changed',
                "Payment {$payment->invoice_number} marked {$payment->status}",
                ['type' => 'Payment', 'id' => $payment->id]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment updated successfully',
            'payment' => [
                'id' => $payment->id,
                'invoice_number' => $payment->invoice_number,
                'member_name' => $payment->member?->full_name,
                'plan_name' => $payment->membershipPlan?->name ?? 'N/A',
                'amount' => number_format((float) $payment->amount, 2),
                'payment_method' => $payment->payment_method,
                'payment_date' => $payment->payment_date?->format('Y-m-d'),
                'status' => $payment->status,
            ]
        ]);
    }

    public function destroy($id)
    {
        $payment = Payment::where('id', $id)->where('is_archived', 0)->first();
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        $payment->update(['is_archived' => 1]);
        return response()->json(['success' => true, 'message' => 'Payment deleted successfully']);
    }

    /**
     * A payment landing on "Paid" — whether created that way directly (e.g.
     * staff recording cash paid in person) or approved from "Pending" — should
     * extend the member's membership and let them know, by SMS and in-app.
     */
    private function applyPaidConsequences(Payment $payment): void
    {
        if (! $payment->member) {
            return;
        }

        $plan = $payment->membershipPlan;
        $baseDate = $payment->member->expiry_date && $payment->member->expiry_date->isFuture()
            ? $payment->member->expiry_date
            : now();
        $newExpiry = match (strtolower((string) ($plan?->type ?? ''))) {
            'annual' => $baseDate->copy()->addYear(),
            'quarterly' => $baseDate->copy()->addMonths(3),
            default => $baseDate->copy()->addMonth(),
        };
        $payment->member->update([
            'expiry_date' => $newExpiry,
            'payment_status' => 'Paid',
            'status' => 'Active',
        ]);

        $this->notifyMember(
            $payment->member->id,
            'Payment Verified',
            "Your payment of PHP {$payment->amount} was verified. Your membership is now active until {$newExpiry->format('M d, Y')}.",
            'green'
        );

        try {
            app(SmsService::class)->send(
                $payment->member->phone,
                "Hi {$payment->member->full_name}, your payment of PHP {$payment->amount} was verified! Membership active until {$newExpiry->format('M d, Y')}.",
                $payment->member->gym_id
            );
        } catch (\Throwable $exception) {
            // Payment recording must still succeed even if the SMS send fails.
        }
    }

    private function generateInvoiceNumber(): string
    {
        do {
            $invoiceNumber = 'INV-' . date('Ym') . '-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Payment::where('invoice_number', $invoiceNumber)->exists());
        return $invoiceNumber;
    }

    private function notifyMember(int $memberId, string $title, string $message, string $type = 'blue'): void
    {
        try {
            \App\Models\Notification::create([
                'member_id' => $memberId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'is_read' => 0,
                'read_at' => null,
                'is_archived' => 0,
            ]);
        } catch (\Throwable $exception) {
            // Payment verification must still succeed even if the notification write fails.
        }
    }

    private function resolveAuthenticatedMember(): ?Member
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        if (isset($user->role) && $user->role !== 'Member') {
            return null;
        }

        $member = Member::where('email', $user->email)
            ->where('is_archived', 0)
            ->first();

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
        if (strtolower((string) ($plan->type ?? '')) === 'annual') {
            $expiry = now()->addYear();
        } elseif (strtolower((string) ($plan->type ?? '')) === 'quarterly') {
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
}
