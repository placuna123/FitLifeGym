<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Gym;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Super Admin's activity feed across every gym — who did what, and where.
 * Filters: branch, free-text search, and a date range (Philippine dates).
 */
class PlatformAuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()->orderByDesc('created_at');

        if ($request->filled('gym_id')) {
            $query->where('gym_id', $request->gym_id);
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->action . '%');
        }

        if ($request->filled('q')) {
            $term = '%' . trim((string) $request->q) . '%';
            $query->where(fn ($q) => $q->where('description', 'like', $term)
                ->orWhere('user_name', 'like', $term)
                ->orWhere('action', 'like', $term));
        }

        // Dates are picked in Philippine time; the log is stored in UTC.
        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->from, 'Asia/Manila')->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->to, 'Asia/Manila')->endOfDay()->utc());
        }

        $total = (clone $query)->count();
        $logs = $query->limit(500)->get();

        $gymNames = Gym::withoutGlobalScopes()->pluck('name', 'id');

        return response()->json([
            'success' => true,
            'total' => $total,
            'logs' => $logs->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'gym_id' => $log->gym_id,
                'gym_name' => $log->gym_id ? ($gymNames[$log->gym_id] ?? 'Unknown gym') : 'Platform',
                'user_name' => $log->user_name,
                'role' => $log->role,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->copy()->setTimezone('Asia/Manila')->format('M j, Y · g:i A'),
            ]),
        ]);
    }
}
