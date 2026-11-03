<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MemberNotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $notifications = Notification::where('member_id', $member->id)
            ->where('is_archived', 0)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'notifications' => $notifications->map(fn (Notification $n) => $this->toPayload($n)),
            'unread_count' => $notifications->where('is_read', false)->count(),
        ]);
    }

    public function markRead(Notification $notification): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member || $notification->member_id !== $member->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $notification->forceFill(['is_read' => 1, 'read_at' => now()])->save();

        return response()->json(['success' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        Notification::where('member_id', $member->id)
            ->where('is_read', 0)
            ->update(['is_read' => 1, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public const SUPPORT_SUBJECTS = ['Equipment Issue', 'Billing Inquiry', 'Membership Question', 'General Feedback'];

    /**
     * A member's feedback lands in their branch's Admin notification center.
     */
    public function submitSupportTicket(Request $request): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'subject' => ['required', Rule::in(self::SUPPORT_SUBJECTS)],
            'message' => 'required|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $contact = trim(implode(' · ', array_filter([$member->member_id ? "ID {$member->member_id}" : null, $member->phone, $member->email])));

        Notification::create([
            'gym_id' => $member->gym_id,
            'member_id' => null,
            'title' => 'Member Feedback: ' . $request->subject,
            'message' => "From {$member->full_name} ({$contact}):\n" . trim($request->message),
            'type' => 'pink',
            'is_read' => 0,
            'read_at' => null,
            'is_archived' => 0,
        ]);

        return response()->json(['success' => true, 'message' => 'Thank you! Your feedback was sent to the gym.']);
    }

    private function toPayload(Notification $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->title,
            'message' => $n->message,
            'type' => $n->type,
            'is_read' => (bool) $n->is_read,
            'time' => AdminNotificationController::happenedAt($n->created_at),
        ];
    }

    private function resolveAuthenticatedMember(): ?Member
    {
        $user = Auth::user();
        if (! $user || ($user->role ?? null) !== 'Member') {
            return null;
        }

        return Member::where('email', $user->email)->where('is_archived', 0)->first();
    }
}
