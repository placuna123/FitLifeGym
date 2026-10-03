<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MembershipPlan;
use App\Models\PlanFeature;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MembershipPlanController extends Controller
{
    private const FEATURE_RULES = [
        'features' => 'sometimes|array|max:20',
        'features.*' => 'nullable|string|max:100',
    ];

    public function index()
    {
        $plans = MembershipPlan::where('is_archived', 0)
            ->with(['planFeatures' => fn ($q) => $q->where('is_archived', 0)->orderBy('id')])
            ->orderBy('name')
            ->get()
            ->map(fn ($plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'type' => $plan->type,
                'price' => $plan->price,
                'period' => $plan->period,
                'description' => $plan->description,
                'is_popular' => $plan->is_popular,
                'is_deactivated' => $plan->is_deactivated,
                'features' => $plan->planFeatures->pluck('feature_name')->values(),
            ]);
        return response()->json($plans);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('membership_plans', 'name')->where('gym_id', Tenancy::gymId())],
            'type' => 'required|in:Monthly,Quarterly,Annual',
            'price' => 'required|numeric|min:0',
            'period' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            ...self::FEATURE_RULES,
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $period = $request->period ?: match ($request->type) {
            'Quarterly' => 'per 3 months',
            'Annual' => 'per year',
            default => 'per month',
        };
        $plan = DB::transaction(function () use ($request, $period) {
            $plan = MembershipPlan::create([
                'name' => $request->name,
                'type' => $request->type,
                'price' => $request->price,
                'period' => $period,
                'description' => $request->description,
                'is_popular' => $request->is_popular ?? 0,
                'is_deactivated' => 0,
                'member_count' => 0,
                'monthly_revenue' => 0,
                'is_archived' => 0,
            ]);
            $this->syncFeatures($plan, (array) $request->input('features', []));

            return $plan;
        });
        return response()->json([
            'success' => true,
            'message' => 'Membership plan created successfully',
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'type' => $plan->type,
                'price' => $plan->price,
                'period' => $plan->period,
                'description' => $plan->description,
                'features' => $this->featureNames($plan),
            ]
        ], 201);
    }

    public function show(string $id)
    {
        $plan = MembershipPlan::where('id', $id)->where('is_archived', 0)->first();
        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'Membership plan not found'], 404);
        }
        return response()->json([
            'id' => $plan->id,
            'name' => $plan->name,
            'type' => $plan->type,
            'price' => $plan->price,
            'period' => $plan->period,
            'description' => $plan->description,
            'is_popular' => $plan->is_popular,
            'is_deactivated' => $plan->is_deactivated,
            'features' => $this->featureNames($plan),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $plan = MembershipPlan::where('id', $id)->where('is_archived', 0)->first();
        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'Membership plan not found'], 404);
        }
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('membership_plans', 'name')->where('gym_id', Tenancy::gymId())->ignore($id)],
            'type' => 'required|in:Monthly,Quarterly,Annual',
            'price' => 'required|numeric|min:0',
            'period' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'is_deactivated' => 'nullable|boolean',
            ...self::FEATURE_RULES,
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        DB::transaction(function () use ($request, $plan) {
            $plan->update([
                'name' => $request->name,
                'type' => $request->type,
                'price' => $request->price,
                'period' => $request->period ?? $plan->period,
                'description' => $request->description,
                'is_popular' => $request->is_popular ?? $plan->is_popular,
                'is_deactivated' => $request->has('is_deactivated') ? (int) $request->is_deactivated : $plan->is_deactivated,
            ]);
            // Only touch features when the caller sent them (e.g. the Deactivate button doesn't).
            if ($request->has('features')) {
                $this->syncFeatures($plan, (array) $request->input('features', []));
            }
        });
        return response()->json([
            'success' => true,
            'message' => 'Membership plan updated successfully',
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'type' => $plan->type,
                'price' => $plan->price,
                'period' => $plan->period,
                'description' => $plan->description,
                'features' => $this->featureNames($plan),
            ]
        ]);
    }

    public function destroy(string $id)
    {
        $plan = MembershipPlan::where('id', $id)->where('is_archived', 0)->first();
        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'Membership plan not found'], 404);
        }
        $plan->update(['is_archived' => 1]);
        return response()->json(['success' => true, 'message' => 'Membership plan deleted successfully']);
    }

    /**
     * Replace a plan's feature list with the given names (trimmed, blanks and duplicates dropped, order kept).
     */
    private function syncFeatures(MembershipPlan $plan, array $features): void
    {
        $names = collect($features)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->values();

        PlanFeature::where('membership_plan_id', $plan->id)->delete();

        foreach ($names as $name) {
            PlanFeature::create([
                'gym_id' => $plan->gym_id,
                'membership_plan_id' => $plan->id,
                'feature_name' => $name,
                'is_archived' => 0,
            ]);
        }
    }

    private function featureNames(MembershipPlan $plan): array
    {
        return PlanFeature::where('membership_plan_id', $plan->id)
            ->where('is_archived', 0)
            ->orderBy('id')
            ->pluck('feature_name')
            ->all();
    }
}
