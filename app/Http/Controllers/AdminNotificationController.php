<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class AdminNotificationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            ...$this->notificationListsPayload(),
        ]);
    }

    public function markRead(Notification $notification): JsonResponse
    {
        $notification->forceFill([
            'is_read' => 1,
            'read_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            ...$this->notificationListsPayload(),
        ]);
    }

    public function dismiss(Notification $notification): JsonResponse
    {
        $notification->forceFill([
            'is_read' => 1,
            'read_at' => $notification->read_at ?: now(),
            'is_archived' => 1,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Notification dismissed.',
            ...$this->notificationListsPayload(),
        ]);
    }

    public function markAllRead(): JsonResponse
    {
        // member_id set means the row is addressed to one member's own portal,
        // not the gym-wide admin feed this controller serves.
        Notification::query()
            ->whereNull('member_id')
            ->where('is_archived', 0)
            ->where('is_read', 0)
            ->update([
                'is_read' => 1,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All active notifications marked as read.',
            ...$this->notificationListsPayload(),
        ]);
    }

    private function notificationListsPayload(): array
    {
        $active = Notification::query()
            ->whereNull('member_id')
            ->where('is_archived', 0)
            ->where('is_read', 0)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $history = Notification::query()
            ->whereNull('member_id')
            ->where(function ($query) {
                $query->where('is_read', 1)
                    ->orWhere('is_archived', 1);
            })
            ->orderByDesc('read_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return [
            'success' => true,
            'active' => $active->map(fn (Notification $n) => $this->toPayload($n)),
            'history' => $history->map(fn (Notification $n) => $this->toPayload($n)),
        ];
    }

    private function toPayload(Notification $n): array
    {
        $icon = 'fas fa-bell';
        $priority = 'info';

        // Member feedback (older rows were titled "Support Ticket: …")
        if (str_starts_with((string) $n->title, 'Member Feedback') || str_starts_with((string) $n->title, 'Support Ticket')) {
            $icon = 'fas fa-comment-dots';
            $priority = 'high';
        }

        // Match the icon/colors used by `resources/js/pages/fitgym/Admin.vue`
        switch ($n->title) {
            case 'Membership Expiring Soon':
            case 'Membership Ends Today':
            case 'Membership Expired':
                $icon = 'fas fa-calendar-times';
                $priority = 'high';
                break;
            case 'Payment Overdue':
                $icon = 'fas fa-exclamation-triangle';
                $priority = 'high';
                break;
            case 'New Member Joined':
                $icon = 'fas fa-user-plus';
                $priority = 'info';
                break;
            case 'Payment Received':
                $icon = 'fas fa-peso-sign';
                $priority = 'info';
                break;
            case 'Membership Renewal':
                $icon = 'fas fa-sync-alt';
                $priority = 'info';
                break;
            case 'System Maintenance':
                $icon = 'fas fa-cog';
                $priority = 'info';
                break;
        }

        $recordedAt = $n->read_at ?: $n->created_at;
        $historyStatus = $n->is_archived ? 'dismissed' : ((bool) $n->is_read ? 'read' : 'active');
        // Always when it actually happened (not when it was read), in Philippine time.
        $time = self::happenedAt($n->created_at);

        return [
            'id' => $n->id,
            'type' => $n->type,
            'icon' => $icon,
            'title' => $n->title,
            'message' => $n->message,
            'time' => $time,
            'priority' => $priority,
            'is_read' => (bool) $n->is_read,
            'is_archived' => (bool) $n->is_archived,
            'history_status' => $historyStatus,
            'action_label' => $historyStatus === 'dismissed' ? 'Dismissed' : ($historyStatus === 'read' ? 'Marked as Read' : 'Unread'),
            'recorded_at' => $recordedAt ? Carbon::parse($recordedAt)->setTimezone('Asia/Manila')->format('M d, Y h:i A') : null,
        ];
    }

    /**
     * "Oct 2, 2026 · 6:15 PM (3 weeks ago)": the real date and time, in Philippine
     * time (stored in UTC), so nobody has to guess what day "3 weeks ago" was.
     */
    public static function happenedAt($at): string
    {
        if (! $at) {
            return '';
        }
        $local = Carbon::parse($at)->setTimezone('Asia/Manila');

        return $local->format('M j, Y · g:i A') . ' (' . $local->diffForHumans() . ')';
    }
}
