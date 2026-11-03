<?php

namespace App\Http\Controllers;

use App\Models\ClassBooking;
use App\Models\GymClass;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin/Trainer-managed weekly class schedule (Yoga, CrossFit, etc.) that
 * members book into via ClassBookingController.
 */
class GymClassController extends Controller
{
    /** Plain wording for the Add / Edit Class form, so Admin and Staff see what to fix. */
    private const MESSAGES = [
        'name.required' => 'Enter a class name.',
        'trainer_id.exists' => 'That staff member no longer exists. Pick another one or leave it TBA.',
        'start_time.required' => 'Pick a start time.',
        'start_time.date_format' => 'Pick a start time.',
        'end_time.required' => 'Pick an end time.',
        'end_time.date_format' => 'Pick an end time.',
        'end_time.after' => 'End time must be later than the start time.',
        'capacity.required' => 'Enter how many people can join.',
        'capacity.min' => 'Capacity must be at least 1.',
        'capacity.max' => 'Capacity can be at most 200.',
    ];

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * Weekly classes. Each class also carries its date in the week of `?date=`
     * (default: this week) and how many members booked that occurrence.
     */
    public function index(Request $request)
    {
        $weekStart = Carbon::parse($request->query('date', now()->toDateString()))->startOfWeek(Carbon::MONDAY);

        $classes = GymClass::where('is_archived', 0)
            ->with('trainer')
            ->get()
            ->sortBy(fn (GymClass $c) => sprintf('%d-%s', array_search($c->day_of_week, self::DAYS, true), $c->start_time))
            ->values();

        $dates = $classes->mapWithKeys(fn (GymClass $c) => [
            $c->id => $weekStart->copy()->addDays((int) array_search($c->day_of_week, self::DAYS, true))->toDateString(),
        ]);

        $bookedCounts = ClassBooking::whereIn('gym_class_id', $classes->pluck('id'))
            ->where('status', 'Booked')
            ->whereBetween('class_date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->get(['gym_class_id', 'class_date'])
            ->filter(fn ($b) => $b->class_date?->toDateString() === ($dates[$b->gym_class_id] ?? null))
            ->countBy('gym_class_id');

        return response()->json([
            'success' => true,
            'week_start' => $weekStart->toDateString(),
            'classes' => $classes->map(fn (GymClass $c) => [
                ...$this->toPayload($c),
                'class_date' => $dates[$c->id],
                'booked_count' => (int) ($bookedCounts[$c->id] ?? 0),
            ]),
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
        ], self::MESSAGES);
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
        ], self::MESSAGES);
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
