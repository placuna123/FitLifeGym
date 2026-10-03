<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

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

    private function toPayload(Notification $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->title,
            'message' => $n->message,
            'type' => $n->type,
            'is_read' => (bool) $n->is_read,
            'time' => $n->created_at ? Carbon::parse($n->created_at)->diffForHumans() : '',
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
