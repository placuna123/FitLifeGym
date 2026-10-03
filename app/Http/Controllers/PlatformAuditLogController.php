<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Gym;
use Illuminate\Http\Request;

/**
 * Super Admin's activity feed across every gym — who did what, and where.
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

        $logs = $query->limit(200)->get();

        $gymNames = Gym::withoutGlobalScopes()->pluck('name', 'id');

        return response()->json([
            'success' => true,
            'logs' => $logs->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'gym_id' => $log->gym_id,
                'gym_name' => $log->gym_id ? ($gymNames[$log->gym_id] ?? 'Unknown gym') : 'Platform',
                'user_name' => $log->user_name,
                'role' => $log->role,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ]),
        ]);
    }
}
