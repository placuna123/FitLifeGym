<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Trainer;
use App\Models\TrainerSchedule;
use App\Models\Member;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TrainerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $trainers = Trainer::where('is_archived', 0)
                ->orderBy('full_name')
                ->get();

            return response()->json([
                'success' => true,
                'trainer' => $trainers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load staff: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if ($limited = $this->planLimitResponse(\App\Models\Gym::forCurrentUser(), 'staff')) {
            return $limited;
        }

        $normalizedEmail = strtolower(trim((string) $request->input('email', '')));
        $normalizedPassword = trim((string) $request->input('password', ''));
        $request->merge([
            'email' => $normalizedEmail,
            'password' => $normalizedPassword,
        ]);

        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('trainers', 'email')->where('gym_id', Tenancy::gymId()),
                'unique:users,email',
            ],
            'phone' => 'required|string|max:50',
            'position' => 'required|string|max:100',
            'department' => 'nullable|string|max:100',
            'hire_date' => 'required|date',
            'status' => 'required|in:Active,Inactive,On Leave',
            'password' => 'required|string|min:6',
            'schedule' => 'nullable|string|max:255',
        ], [
            'email.unique' => 'This email is already used by another FitGym account. Use a different email.',
            'password.min' => 'The password must be at least 6 characters.',
            'phone.required' => 'Enter a phone number.',
            'position.required' => 'Enter a position (e.g. Trainer or Front Desk).',
            'hire_date.required' => 'Choose a hire date.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Generate trainer ID
            $maxId = Trainer::max('id') ?? 0;
            $staffId = 'STF' . str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);

            // Create trainer record
            $trainer = Trainer::create([
                'staff_id' => $staffId,
                'full_name' => $request->full_name,
                'email' => $normalizedEmail,
                'phone' => $request->phone,
                'position' => $request->position,
                'department' => $request->department,
                'hire_date' => $request->hire_date,
                'status' => $request->status,
                'is_archived' => 0,
            ]);

            // Create the staff login account in the same branch as the staff record.
            $user = User::create([
                'name' => $request->full_name,
                'email' => $normalizedEmail,
                'password' => Hash::make($normalizedPassword),
                'role' => 'Staff',
                'gym_id' => $trainer->gym_id,
                'is_archived' => 0,
            ]);

            DB::commit();

            \App\Models\AuditLog::record('trainer.created', "Created trainer {$trainer->full_name}", ['type' => 'Staff', 'id' => $trainer->id]);

            \App\Support\Email::send($user, new \App\Notifications\AccountInvite(
                'Staff',
                \App\Models\Gym::find($trainer->gym_id)?->name ?? 'your gym',
                \App\Support\Email::setPasswordUrl($user)
            ));

            return response()->json([
                'success' => true,
                'message' => 'Staff created successfully',
                'trainer' => $trainer,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create staff: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $trainer = Trainer::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$trainer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'trainer' => $trainer,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load staff: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $normalizedEmail = strtolower(trim((string) $request->input('email', '')));
            $normalizedPassword = trim((string) $request->input('password', ''));
            $request->merge([
                'email' => $normalizedEmail,
                'password' => $normalizedPassword,
            ]);

            $trainer = Trainer::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$trainer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff not found'
                ], 404);
            }

            $linkedUser = User::where('email', $trainer->email)->first();

            $validator = Validator::make($request->all(), [
                'full_name' => 'required|string|max:255',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('trainers', 'email')->where('gym_id', Tenancy::gymId())->ignore($trainer->id),
                    Rule::unique('users', 'email')->ignore($linkedUser?->id),
                ],
                'phone' => 'required|string|max:50',
                'position' => 'required|string|max:100',
                'department' => 'nullable|string|max:100',
                'status' => 'required|in:Active,Inactive,On Leave',
                'password' => 'nullable|string|min:6',
            ], [
                'email.unique' => 'This email is already used by another FitGym account. Use a different email.',
                'password.min' => 'The password must be at least 6 characters.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $trainer->update([
                'full_name' => $request->full_name,
                'email' => $normalizedEmail,
                'phone' => $request->phone,
                'position' => $request->position,
                'department' => $request->department ?? $trainer->department,
                'status' => $request->status,
            ]);

            if ($linkedUser) {
                $userUpdate = [
                    'name' => $request->full_name,
                    'email' => $normalizedEmail,
                ];
                if ($request->filled('password')) {
                    $userUpdate['password'] = Hash::make($normalizedPassword);
                }
                // Repairs staff logins created before they were linked to a branch.
                if (empty($linkedUser->gym_id)) {
                    $userUpdate['gym_id'] = $trainer->gym_id;
                }
                $linkedUser->update($userUpdate);
            } else {
                User::create([
                    'name' => $request->full_name,
                    'email' => $normalizedEmail,
                    'password' => Hash::make($normalizedPassword !== '' ? $normalizedPassword : 'password'),
                    'role' => 'Staff',
                    'gym_id' => $trainer->gym_id,
                    'is_archived' => 0,
                ]);
            }

            DB::commit();

            \App\Models\AuditLog::record('trainer.updated', "Updated trainer {$trainer->full_name}", ['type' => 'Staff', 'id' => $trainer->id]);

            return response()->json([
                'success' => true,
                'message' => 'Staff updated successfully',
                'trainer' => $trainer,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update staff: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $trainer = Trainer::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$trainer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff not found'
                ], 404);
            }

            // Soft delete by archiving
            $trainer->update(['is_archived' => 1]);

            // Close their login too; otherwise a deleted staff member could still sign in.
            if ($trainer->email) {
                \App\Models\User::where('gym_id', $trainer->gym_id)
                    ->where('role', 'Staff')
                    ->whereRaw('LOWER(email) = ?', [strtolower($trainer->email)])
                    ->update(['is_archived' => 1]);
            }

            // Their classes stay on the schedule as "Unassigned (TBA)" until someone takes them.
            \App\Models\GymClass::where('trainer_id', $trainer->id)->update(['trainer_id' => null]);

            \App\Models\AuditLog::record('trainer.deleted', "Deleted trainer {$trainer->full_name}", ['type' => 'Staff', 'id' => $trainer->id]);

            return response()->json([
                'success' => true,
                'message' => 'Staff deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete staff: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign members to a trainer.
     *
     * NOTE: real persistence (trainer_member_assignments pivot) lands with the
     * workout-plan feature in Phase 2 — this still just reports a count.
     */
    public function assignMembers(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'member_ids' => 'required|array',
            'member_ids.*' => 'exists:members,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $trainer = Trainer::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$trainer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff not found'
                ], 404);
            }

            $assignedCount = count($request->member_ids);

            return response()->json([
                'success' => true,
                'message' => "Successfully assigned {$assignedCount} member(s) to {$trainer->full_name}",
                'trainer' => $trainer,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign members: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Replace a trainer's weekly schedule.
     */
    public function updateSchedule(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'days' => 'required|array|min:1',
            'days.*' => 'string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $trainer = Trainer::where('id', $id)
                ->where('is_archived', 0)
                ->first();

            if (!$trainer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff not found'
                ], 404);
            }

            DB::beginTransaction();

            // Replace the trainer's schedule rows with the submitted days/times.
            TrainerSchedule::where('staff_id', $trainer->id)->delete();

            foreach ($request->days as $day) {
                TrainerSchedule::create([
                    'staff_id' => $trainer->id,
                    'day_of_week' => $day,
                    'start_time' => $request->start_time,
                    'end_time' => $request->end_time,
                    'is_archived' => 0,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Schedule updated successfully',
                'schedule' => TrainerSchedule::where('staff_id', $trainer->id)->get(),
                'trainer' => $trainer,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update schedule: ' . $e->getMessage()
            ], 500);
        }
    }
}
