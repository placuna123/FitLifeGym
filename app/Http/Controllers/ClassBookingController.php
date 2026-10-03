<?php

namespace App\Http\Controllers;

use App\Models\ClassBooking;
use App\Models\GymClass;
use App\Models\Member;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Member-facing side of the class schedule: view a date's classes (with real
 * seat counts) and book/cancel a seat. Classes themselves are managed by
 * Admin/Trainer via GymClassController.
 */
class ClassBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $date = $request->filled('date') ? Carbon::parse($request->date) : Carbon::today();
        $dateStr = $date->format('Y-m-d');
        $dayOfWeek = $date->format('l');

        $classes = GymClass::where('is_archived', 0)
            ->where('day_of_week', $dayOfWeek)
            ->with('trainer')
            ->orderBy('start_time')
            ->get();

        $bookings = ClassBooking::whereIn('gym_class_id', $classes->pluck('id'))
            ->where('class_date', $dateStr)
            ->where('status', 'Booked')
            ->get();

        return response()->json([
            'success' => true,
            'date' => $dateStr,
            'classes' => $classes->map(function (GymClass $c) use ($bookings, $member) {
                $booked = $bookings->where('gym_class_id', $c->id);
                $myBooking = $booked->firstWhere('member_id', $member->id);

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'trainer_name' => $c->trainer?->full_name ?? 'TBA',
                    'start_time' => substr((string) $c->start_time, 0, 5),
                    'end_time' => substr((string) $c->end_time, 0, 5),
                    'capacity' => $c->capacity,
                    'booked_count' => $booked->count(),
                    'is_full' => $booked->count() >= $c->capacity,
                    'booked_by_me' => $myBooking !== null,
                    'my_booking_id' => $myBooking?->id,
                ];
            }),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'gym_class_id' => 'required|exists:gym_classes,id',
            'class_date' => 'required|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $class = GymClass::where('id', $request->gym_class_id)->where('is_archived', 0)->first();
        if (! $class) {
            return response()->json(['success' => false, 'message' => 'Class not found'], 404);
        }

        $classDate = Carbon::parse($request->class_date);
        if ($classDate->format('l') !== $class->day_of_week) {
            return response()->json(['success' => false, 'message' => 'That date does not match this class\'s day.'], 422);
        }
        if ($classDate->isPast() && ! $classDate->isToday()) {
            return response()->json(['success' => false, 'message' => 'Cannot book a class in the past.'], 422);
        }

        $existing = ClassBooking::where('gym_class_id', $class->id)
            ->where('member_id', $member->id)
            ->where('class_date', $classDate->format('Y-m-d'))
            ->first();
        if ($existing && $existing->status === 'Booked') {
            return response()->json(['success' => false, 'message' => 'You already booked this class.'], 422);
        }

        $bookedCount = ClassBooking::where('gym_class_id', $class->id)
            ->where('class_date', $classDate->format('Y-m-d'))
            ->where('status', 'Booked')
            ->count();
        if ($bookedCount >= $class->capacity) {
            return response()->json(['success' => false, 'message' => 'This class is full.'], 422);
        }

        if ($existing) {
            $existing->update(['status' => 'Booked']);
            $booking = $existing;
        } else {
            $booking = ClassBooking::create([
                'gym_class_id' => $class->id,
                'member_id' => $member->id,
                'class_date' => $classDate->format('Y-m-d'),
                'status' => 'Booked',
            ]);
        }

        $this->sendBookingConfirmationSms($member, $class->name, $classDate->format('M j, Y'));

        return response()->json(['success' => true, 'message' => "Booked into {$class->name}!", 'booking_id' => $booking->id], 201);
    }

    public function destroy($id): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $booking = ClassBooking::where('id', $id)->where('member_id', $member->id)->first();
        if (! $booking) {
            return response()->json(['success' => false, 'message' => 'Booking not found'], 404);
        }

        $booking->update(['status' => 'Cancelled']);

        return response()->json(['success' => true, 'message' => 'Booking cancelled.']);
    }

    private function sendBookingConfirmationSms(Member $member, string $className, string $classDateFormatted): void
    {
        try {
            app(SmsService::class)->send(
                $member->phone,
                "Hi {$member->full_name}, your class booking for {$className} on {$classDateFormatted} is confirmed!",
                $member->gym_id
            );
        } catch (\Throwable $exception) {
            // Booking must still succeed even if the SMS send fails.
        }
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
