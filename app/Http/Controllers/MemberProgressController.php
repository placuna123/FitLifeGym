<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberWeightLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Real weight/goal tracking for the Member Portal's Progress tab — replaces
 * what used to be hardcoded demo numbers (75kg, BMI 22.4, 75%/90% bars).
 */
class MemberProgressController extends Controller
{
    public function index(): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $logs = $member->weightLogs()->get();
        $latest = $logs->last();
        $earliest = $logs->first();

        $currentWeight = $latest?->weight_kg;
        $startingWeight = $earliest?->weight_kg;
        $goalWeight = $member->goal_weight_kg;

        $goalProgressPercent = null;
        if ($currentWeight !== null && $startingWeight !== null && $goalWeight !== null && (float) $startingWeight !== (float) $goalWeight) {
            $total = abs((float) $startingWeight - (float) $goalWeight);
            $done = $total - abs((float) $currentWeight - (float) $goalWeight);
            $goalProgressPercent = (int) round(max(0, min(100, ($done / $total) * 100)));
        }

        $bmi = null;
        if ($currentWeight !== null && $member->height_cm) {
            $heightM = ((float) $member->height_cm) / 100;
            $bmi = round(((float) $currentWeight) / ($heightM * $heightM), 1);
        }

        return response()->json([
            'success' => true,
            'height_cm' => $member->height_cm,
            'goal_weight_kg' => $goalWeight,
            'current_weight_kg' => $currentWeight,
            'starting_weight_kg' => $startingWeight,
            'goal_progress_percent' => $goalProgressPercent,
            'bmi' => $bmi,
            'logs' => $logs->map(fn (MemberWeightLog $l) => [
                'date' => $l->logged_at->format('Y-m-d'),
                'weight_kg' => (float) $l->weight_kg,
            ]),
        ]);
    }

    public function logWeight(Request $request): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'weight_kg' => 'required|numeric|min:20|max:400',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        MemberWeightLog::updateOrCreate(
            ['member_id' => $member->id, 'logged_at' => now()->format('Y-m-d')],
            ['weight_kg' => $request->weight_kg]
        );

        return response()->json(['success' => true, 'message' => 'Weight logged.']);
    }

    public function updateGoal(Request $request): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'height_cm' => 'nullable|numeric|min:50|max:300',
            'goal_weight_kg' => 'nullable|numeric|min:20|max:400',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $member->update([
            'height_cm' => $request->height_cm,
            'goal_weight_kg' => $request->goal_weight_kg,
        ]);

        return response()->json(['success' => true, 'message' => 'Goal updated.']);
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
