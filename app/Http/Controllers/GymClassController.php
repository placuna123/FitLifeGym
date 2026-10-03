<?php

namespace App\Http\Controllers;

use App\Models\GymClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin/Trainer-managed weekly class schedule (Yoga, CrossFit, etc.) that
 * members book into via ClassBookingController.
 */
class GymClassController extends Controller
{
    public function index()
    {
        $classes = GymClass::where('is_archived', 0)
            ->with('trainer')
            ->orderByRaw("FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'classes' => $classes->map(fn (GymClass $c) => $this->toPayload($c)),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'trainer_id' => 'nullable|exists:trainers,id',
            'day_of_week' => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'capacity' => 'required|integer|min:1|max:200',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $class = GymClass::create([
            'name' => $request->name,
            'trainer_id' => $request->trainer_id,
            'day_of_week' => $request->day_of_week,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'capacity' => $request->capacity,
            'is_archived' => 0,
        ]);

        return response()->json(['success' => true, 'message' => 'Class created successfully', 'class' => $this->toPayload($class->fresh('trainer'))], 201);
    }

    public function update(Request $request, $id)
    {
        $class = GymClass::where('id', $id)->where('is_archived', 0)->first();
        if (! $class) {
            return response()->json(['success' => false, 'message' => 'Class not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'trainer_id' => 'nullable|exists:trainers,id',
            'day_of_week' => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'capacity' => 'required|integer|min:1|max:200',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $class->update([
            'name' => $request->name,
            'trainer_id' => $request->trainer_id,
            'day_of_week' => $request->day_of_week,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'capacity' => $request->capacity,
        ]);

        return response()->json(['success' => true, 'message' => 'Class updated successfully', 'class' => $this->toPayload($class->fresh('trainer'))]);
    }

    public function destroy($id)
    {
        $class = GymClass::where('id', $id)->where('is_archived', 0)->first();
        if (! $class) {
            return response()->json(['success' => false, 'message' => 'Class not found'], 404);
        }
        $class->update(['is_archived' => 1]);

        return response()->json(['success' => true, 'message' => 'Class deleted successfully']);
    }

    private function toPayload(GymClass $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'trainer_id' => $c->trainer_id,
            'trainer_name' => $c->trainer?->full_name,
            'day_of_week' => $c->day_of_week,
            'start_time' => substr((string) $c->start_time, 0, 5),
            'end_time' => substr((string) $c->end_time, 0, 5),
            'capacity' => $c->capacity,
        ];
    }
}
