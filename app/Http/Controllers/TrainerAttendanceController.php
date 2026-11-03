<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Member;
use App\Models\Trainer;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class TrainerAttendanceController extends Controller
{
    private const MEMBER_ATTENDANCE_QR_TTL_MINUTES = 480;
    private const MEMBER_ATTENDANCE_QR_PREFIX = 'fitgym-attendance:';

    /**
     * Record member check-in
     */
    public function checkIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'member_id' => 'required|exists:members,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $member = Member::findOrFail($request->member_id);

            // Check if member is archived
            if ($member->is_archived) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member account is archived'
                ], 422);
            }

            if ($reason = $member->checkInBlockReason()) {
                return response()->json([
                    'success' => false,
                    'message' => "{$member->full_name} can't check in: {$reason}",
                ], 422);
            }

            // Check if member already has an active check-in (no check-out)
            $activeCheckIn = DB::table('attendance')
                ->where('gym_id', Tenancy::gymId())
                ->where('member_id', $member->id)
                ->whereNull('check_out_time')
                ->where('is_archived', 0)
                ->first();

            if ($activeCheckIn) {
                return response()->json([
                    'success' => false,
                    'message' => 'Member already has an active check-in. Please check them out first.'
                ], 422);
            }

            // Create check-in record
            $checkInTime = now();
            $attendanceId = DB::table('attendance')->insertGetId([
                'gym_id' => Tenancy::gymId(),
                'member_id' => $member->id,
                'check_in_time' => $checkInTime,
                'check_out_time' => null,
                'duration_minutes' => null,
                'is_archived' => 0,
                'created_at' => $checkInTime,
                'updated_at' => $checkInTime,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Check-in recorded successfully',
                'attendance' => [
                    'id' => $attendanceId,
                    'member_id' => $member->id,
                    'member_name' => $member->full_name,
                    'check_in_time' => $checkInTime->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record check-in: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check a member out by hand (the "Check out" button on Members Currently Inside),
     * for when the member has no phone to scan their QR a second time.
     */
    public function checkOut(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'attendance_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Choose a member to check out.'], 422);
        }

        $visit = DB::table('attendance')
            ->where('id', $request->integer('attendance_id'))
            ->where('gym_id', Tenancy::gymId())
            ->whereNull('check_out_time')
            ->where('is_archived', 0)
            ->first();
        if (! $visit) {
            return response()->json(['success' => false, 'message' => 'This member is not checked in anymore.'], 404);
        }

        $now = now();
        $minutes = Carbon::parse($visit->check_in_time)->diffInMinutes($now);
        DB::table('attendance')->where('id', $visit->id)->update([
            'check_out_time' => $now,
            'duration_minutes' => (int) $minutes,
            'updated_at' => $now,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Check-out recorded successfully',
            'duration_minutes' => (int) $minutes,
        ]);
    }

    /**
     * Issue or reuse the authenticated member's attendance QR payload.
     */
    public function issueMemberAttendanceQr(): JsonResponse
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // A member who is already inside still gets a QR, so they can check out.
        $isInside = DB::table('attendance')
            ->where('gym_id', Tenancy::gymId())
            ->where('member_id', $member->id)
            ->whereNull('check_out_time')
            ->where('is_archived', 0)
            ->exists();

        if (! $isInside && ($reason = $member->checkInBlockReason())) {
            return response()->json([
                'success' => false,
                'message' => $member->status === 'Pending'
                    ? 'Your account is still awaiting approval from the gym staff. You can check in once it is approved.'
                    : str_replace(['This account', 'the membership'], ['Your account', 'your membership'], $reason),
            ], 403);
        }

        $payload = $this->issueOrReuseMemberAttendanceQrPayload($member);

        return response()->json([
            'success' => true,
            ...$payload,
        ]);
    }

    /**
     * Process a member QR payload from the trainer/admin scanner and toggle attendance.
     */
    public function processMemberQr(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! in_array(($user->role ?? ''), ['Admin', 'Staff'], true)) {
            return response()->json(['success' => false, 'message' => 'Only staff or admin can scan member QR codes.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payload' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $parsedPayload = $this->parseAttendanceQrPayload($request->string('payload')->toString());
        if (! $parsedPayload) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired attendance QR code. Ask the member to generate a new one.',
            ], 422);
        }

        $member = Member::where('id', $parsedPayload['member_id'])
            ->where('is_archived', 0)
            ->first();

        if (! $member) {
            if (! empty($parsedPayload['qr_token'])) {
                $this->forgetMemberAttendanceQrToken($parsedPayload['member_id'], $parsedPayload['qr_token']);
            }

            return response()->json([
                'success' => false,
                'message' => 'Member record not found for this QR code.',
            ], 404);
        }

        $now = now();
        $activeAttendance = DB::table('attendance')
            ->where('gym_id', Tenancy::gymId())
            ->where('member_id', $member->id)
            ->whereNull('check_out_time')
            ->where('is_archived', 0)
            ->first();

        if ($activeAttendance) {
            $checkInTime = Carbon::parse($activeAttendance->check_in_time);
            $durationMinutes = $checkInTime->diffInMinutes($now);

            DB::table('attendance')
                ->where('id', $activeAttendance->id)
                ->update([
                    'check_out_time' => $now,
                    'duration_minutes' => $durationMinutes,
                    'updated_at' => $now,
                ]);

            if (! empty($parsedPayload['qr_token'])) {
                $this->forgetMemberAttendanceQrToken($member->id, $parsedPayload['qr_token']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Check-out recorded successfully',
                'action' => 'check_out',
                'attendance' => [
                    'id' => $activeAttendance->id,
                    'member_id' => $member->id,
                    'member_name' => $member->full_name,
                    'check_in_time' => $checkInTime->format('Y-m-d H:i:s'),
                    'check_out_time' => $now->format('Y-m-d H:i:s'),
                    'duration_minutes' => $durationMinutes,
                ],
            ]);
        }

        if ($reason = $member->checkInBlockReason()) {
            return response()->json([
                'success' => false,
                'message' => "{$member->full_name} can't check in: {$reason}",
            ], 422);
        }

        if (! empty($parsedPayload['checkin_used_at'])) {
            if (! empty($parsedPayload['qr_token'])) {
                $this->forgetMemberAttendanceQrToken($member->id, $parsedPayload['qr_token']);
            }

            return response()->json([
                'success' => false,
                'message' => 'This attendance QR has already been used. Ask the member to generate a new one.',
            ], 422);
        }

        $attendanceId = DB::table('attendance')->insertGetId([
            'gym_id' => Tenancy::gymId(),
            'member_id' => $member->id,
            'check_in_time' => $now,
            'check_out_time' => null,
            'duration_minutes' => null,
            'is_archived' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! empty($parsedPayload['qr_token'])) {
            $parsedPayload['checkin_used_at'] = $now->toISOString();
            $this->storeMemberAttendanceQrToken($parsedPayload['qr_token'], $parsedPayload);
        }

        return response()->json([
            'success' => true,
            'message' => 'Check-in recorded successfully',
            'action' => 'check_in',
            'attendance' => [
                'id' => $attendanceId,
                'member_id' => $member->id,
                'member_name' => $member->full_name,
                'check_in_time' => $now->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Get attendance logs
     */
    public function getLogs(Request $request)
    {
        try {
            $query = DB::table('attendance')
                ->join('members', 'attendance.member_id', '=', 'members.id')
                ->where('attendance.gym_id', Tenancy::gymId())
                ->where('attendance.is_archived', 0)
                ->where('members.is_archived', 0)
                ->select(
                    'attendance.id',
                    'attendance.member_id',
                    'members.full_name as member_name',
                    'attendance.check_in_time',
                    'attendance.check_out_time',
                    'attendance.duration_minutes'
                )
                ->orderBy('attendance.check_in_time', 'desc')
                ->limit(100);

            // Search filter
            if ($request->has('search') && $request->search) {
                $query->where('members.full_name', 'like', '%' . $request->search . '%');
            }

            $logs = $query->get();

            $formattedLogs = $logs->map(function ($log) {
                $checkInTime = Carbon::parse($log->check_in_time);
                $checkOutTime = $log->check_out_time ? Carbon::parse($log->check_out_time) : null;

                $status = $checkOutTime ? 'Completed' : 'Inside';
                $statusClass = $checkOutTime ? 'bg-gray-500/20 text-gray-300' : 'bg-green-500/20 text-green-300';

                return [
                    'id' => $log->id,
                    'member_id' => $log->member_id,
                    'member_name' => $log->member_name,
                    'date' => $checkInTime->format('Y-m-d'),
                    'check_in' => $checkInTime->format('H:i'),
                    'check_out' => $checkOutTime ? $checkOutTime->format('H:i') : '-',
                    'status' => $status,
                    'status_class' => $statusClass,
                    'initials' => $this->getInitials($log->member_name),
                ];
            });

            return response()->json([
                'success' => true,
                'logs' => $formattedLogs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendance logs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent check-ins for dashboard
     */
    public function getRecentCheckIns()
    {
        try {
            $recent = DB::table('attendance')
                ->join('members', 'attendance.member_id', '=', 'members.id')
                ->where('attendance.gym_id', Tenancy::gymId())
                ->where('attendance.is_archived', 0)
                ->where('members.is_archived', 0)
                ->whereDate('attendance.check_in_time', today())
                ->select(
                    'attendance.id',
                    'attendance.member_id',
                    'members.full_name as member_name',
                    'attendance.check_in_time',
                    'attendance.check_out_time'
                )
                ->orderBy('attendance.check_in_time', 'desc')
                ->limit(10)
                ->get();

            $formatted = $recent->map(function ($item) {
                $checkInTime = Carbon::parse($item->check_in_time);
                $checkOutTime = $item->check_out_time ? Carbon::parse($item->check_out_time) : null;

                $status = $checkOutTime ? 'Completed' : 'Inside';
                $statusClass = $checkOutTime ? 'bg-gray-600 text-gray-200' : 'bg-green-500 text-green-100';

                return [
                    'id' => $item->id,
                    'member_name' => $item->member_name,
                    'check_in_time' => $checkInTime->format('H:i'),
                    'status' => $status,
                    'status_class' => $statusClass,
                    'initials' => $this->getInitials($item->member_name),
                ];
            });

            return response()->json([
                'success' => true,
                'checkins' => $formatted
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent check-ins: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get members currently in gym (checked in, no check-out)
     */
    public function getCurrentlyInGym()
    {
        try {
            $inGym = DB::table('attendance')
                ->join('members', 'attendance.member_id', '=', 'members.id')
                ->where('attendance.gym_id', Tenancy::gymId())
                ->where('attendance.is_archived', 0)
                ->where('members.is_archived', 0)
                ->whereNull('attendance.check_out_time')
                ->select(
                    'attendance.id',
                    'attendance.member_id',
                    'members.full_name as member_name',
                    'attendance.check_in_time'
                )
                ->orderBy('attendance.check_in_time', 'desc')
                ->get();

            $formatted = $inGym->map(function ($item) {
                $checkInTime = Carbon::parse($item->check_in_time);
                return [
                    'id' => $item->id,
                    'member_name' => $item->member_name,
                    'check_in_time' => $checkInTime->format('H:i'),
                    'check_in_date' => $checkInTime->format('Y-m-d'),
                    'initials' => $this->getInitials($item->member_name),
                ];
            });

            return response()->json([
                'success' => true,
                'members' => $formatted
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch currently in gym: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get member initials for avatar
     */
    private function getInitials($name)
    {
        $names = explode(' ', $name);
        if (count($names) >= 2) {
            return strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }

    /**
     * Get attendance logs for the authenticated member only (Member Portal).
     */
    public function getMemberLogs(Request $request)
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        try {
            $query = DB::table('attendance')
                ->where('member_id', $member->id)
                ->where('is_archived', 0)
                ->orderBy('check_in_time', 'desc')
                ->limit(100);
            $logs = $query->get();
            $formatted = $logs->map(function ($log) {
                $checkInTime = Carbon::parse($log->check_in_time);
                $checkOutTime = $log->check_out_time ? Carbon::parse($log->check_out_time) : null;
                return [
                    'id' => $log->id,
                    'date' => $checkInTime->format('Y-m-d'),
                    'time' => $checkInTime->format('h:i A'),
                    'check_in_time' => $checkInTime->format('Y-m-d H:i:s'),
                    'check_out_time' => $checkOutTime ? $checkOutTime->format('Y-m-d H:i:s') : null,
                    'status' => $checkOutTime ? 'Check Out' : 'Check In',
                ];
            });
            return response()->json(['success' => true, 'logs' => $formatted]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check out the authenticated member (Member Portal). Finds active check-in and sets check_out_time.
     */
    public function memberCheckOut(Request $request)
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        try {
            $active = DB::table('attendance')
                ->where('member_id', $member->id)
                ->whereNull('check_out_time')
                ->where('is_archived', 0)
                ->first();
            if (!$active) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active check-in found. Please check in first.'
                ], 422);
            }
            $checkOutTime = now();
            $checkInTime = Carbon::parse($active->check_in_time);
            $durationMinutes = $checkInTime->diffInMinutes($checkOutTime);
            DB::table('attendance')->where('id', $active->id)->update([
                'check_out_time' => $checkOutTime,
                'duration_minutes' => $durationMinutes,
                'updated_at' => $checkOutTime,
            ]);
            $this->forgetCurrentMemberAttendanceQrToken($member->id);
            return response()->json([
                'success' => true,
                'message' => 'Check-out recorded successfully',
                'attendance' => [
                    'id' => $active->id,
                    'check_in_time' => $checkInTime->format('Y-m-d H:i:s'),
                    'check_out_time' => $checkOutTime->format('Y-m-d H:i:s'),
                    'duration_minutes' => $durationMinutes,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record check-out: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if the authenticated member currently has an active check-in (Member Portal).
     */
    public function getMemberActiveCheckIn(Request $request)
    {
        $member = $this->resolveAuthenticatedMember();
        if (! $member) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        $active = DB::table('attendance')
            ->where('member_id', $member->id)
            ->whereNull('check_out_time')
            ->where('is_archived', 0)
            ->first();
        if (!$active) {
            return response()->json(['success' => true, 'active' => null]);
        }
        $checkInTime = Carbon::parse($active->check_in_time);
        return response()->json([
            'success' => true,
            'active' => [
                'id' => $active->id,
                'check_in_time' => $checkInTime->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    // ============================================
    // TRAINER ATTENDANCE METHODS (for trainers)
    // ============================================

    /**
     * Record trainer check-in
     */
    public function trainerCheckIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'staff_id' => 'required|exists:trainers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $trainer = Trainer::findOrFail($request->staff_id);

            // Check if trainer is archived
            if ($trainer->is_archived) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff account is archived'
                ], 422);
            }

            $today = Carbon::today();

            // Check if trainer already has attendance record for today
            $existingAttendance = DB::table('trainer_attendance')
                ->where('gym_id', Tenancy::gymId())
                ->where('staff_id', $trainer->id)
                ->where('attendance_date', $today->format('Y-m-d'))
                ->where('is_archived', 0)
                ->first();

            if ($existingAttendance) {
                // Update existing record with check-in time
                if ($existingAttendance->check_in_time) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Staff already checked in today'
                    ], 422);
                }

                $checkInTime = now();
                DB::table('trainer_attendance')
                    ->where('id', $existingAttendance->id)
                    ->update([
                        'check_in_time' => $checkInTime,
                        'status' => 'Present',
                        'updated_at' => $checkInTime,
                    ]);

                $attendanceId = $existingAttendance->id;
            } else {
                // Create new attendance record
                $checkInTime = now();
                $attendanceId = DB::table('trainer_attendance')->insertGetId([
                    'gym_id' => Tenancy::gymId(),
                    'staff_id' => $trainer->id,
                    'attendance_date' => $today->format('Y-m-d'),
                    'check_in_time' => $checkInTime,
                    'check_out_time' => null,
                    'status' => 'Present',
                    'is_archived' => 0,
                    'created_at' => $checkInTime,
                    'updated_at' => $checkInTime,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Staff check-in recorded successfully',
                'attendance' => [
                    'id' => $attendanceId,
                    'staff_id' => $trainer->id,
                    'staff_name' => $trainer->full_name,
                    'check_in_time' => $checkInTime->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record staff check-in: ' . $e->getMessage()
            ], 500);
        }
    }

    // Only the member's own, non-deleted record. Never creates or revives one: that
    // used to hand a deleted member a free Active + Paid month.
    private function resolveAuthenticatedMember(): ?Member
    {
        $user = Auth::user();
        if (! $user || ($user->role ?? null) !== 'Member') {
            return null;
        }

        return Member::where('email', $user->email)->where('is_archived', 0)->first();
    }

    private function issueOrReuseMemberAttendanceQrPayload(Member $member): array
    {
        $currentToken = Cache::get($this->memberCurrentQrTokenKey($member->id));
        if (is_string($currentToken) && $currentToken !== '') {
            $existing = Cache::get($this->memberAttendanceQrTokenKey($currentToken));
            if (is_array($existing) && (int) ($existing['member_id'] ?? 0) === (int) $member->id) {
                return $this->attendanceQrResponsePayload($existing);
            }

            $this->forgetMemberAttendanceQrToken($member->id, $currentToken);
        }

        $expiresAt = now()->addMinutes(self::MEMBER_ATTENDANCE_QR_TTL_MINUTES);
        $token = bin2hex(random_bytes(24));
        $payload = [
            'member_id' => $member->id,
            'qr_token' => $token,
            'issued_at' => now()->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'checkin_used_at' => null,
        ];

        $this->storeMemberAttendanceQrToken($token, $payload);
        Cache::put($this->memberCurrentQrTokenKey($member->id), $token, $expiresAt);

        return $this->attendanceQrResponsePayload($payload);
    }

    private function attendanceQrResponsePayload(array $tokenData): array
    {
        return [
            'qr_payload' => [
                'v' => 1,
                'type' => 'fitgym_member_attendance',
                'qr_token' => $tokenData['qr_token'],
                'issued_at' => $tokenData['issued_at'],
                'expires_at' => $tokenData['expires_at'],
            ],
            'qr_code_text' => $this->memberAttendanceQrText((string) $tokenData['qr_token']),
            'issued_at' => $tokenData['issued_at'],
            'expires_at' => $tokenData['expires_at'],
        ];
    }

    private function parseAttendanceQrPayload(string $rawPayload): ?array
    {
        $trimmed = trim($rawPayload);
        if ($trimmed === '') {
            return null;
        }

        $compactToken = $this->extractCompactAttendanceQrToken($trimmed);
        if ($compactToken !== null) {
            return $this->resolveAttendanceQrToken($compactToken);
        }

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            $memberId = (int) $trimmed;

            return $memberId > 0
                ? [
                    'member_id' => $memberId,
                    'qr_token' => null,
                    'checkin_used_at' => null,
                    'legacy_payload' => true,
                ]
                : null;
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return null;
        }

        $type = trim((string) ($decoded['type'] ?? ''));
        if ($type === 'fitgym_member_checkin') {
            $memberId = (int) ($decoded['member_id'] ?? 0);

            return $memberId > 0
                ? [
                    'member_id' => $memberId,
                    'qr_token' => null,
                    'checkin_used_at' => null,
                    'legacy_payload' => true,
                ]
                : null;
        }

        if ($type !== 'fitgym_member_attendance') {
            return null;
        }

        $token = trim((string) ($decoded['qr_token'] ?? ''));
        if ($token === '') {
            return null;
        }

        return $this->resolveAttendanceQrToken($token);
    }

    private function resolveAttendanceQrToken(string $token): ?array
    {
        $cached = Cache::get($this->memberAttendanceQrTokenKey($token));
        if (! is_array($cached)) {
            return null;
        }

        $expiresAt = isset($cached['expires_at']) ? Carbon::parse((string) $cached['expires_at']) : null;
        if (! $expiresAt || $expiresAt->isPast()) {
            $this->forgetMemberAttendanceQrToken((int) ($cached['member_id'] ?? 0), $token);

            return null;
        }

        return $cached;
    }

    private function extractCompactAttendanceQrToken(string $rawPayload): ?string
    {
        if (! str_starts_with($rawPayload, self::MEMBER_ATTENDANCE_QR_PREFIX)) {
            return null;
        }

        $token = trim(substr($rawPayload, strlen(self::MEMBER_ATTENDANCE_QR_PREFIX)));

        return $token !== '' ? $token : null;
    }

    private function memberAttendanceQrText(string $token): string
    {
        return self::MEMBER_ATTENDANCE_QR_PREFIX . $token;
    }

    private function storeMemberAttendanceQrToken(string $token, array $tokenData): void
    {
        $expiresAt = Carbon::parse((string) $tokenData['expires_at']);
        Cache::put($this->memberAttendanceQrTokenKey($token), $tokenData, $expiresAt);
        Cache::put($this->memberCurrentQrTokenKey((int) $tokenData['member_id']), $token, $expiresAt);
    }

    private function forgetCurrentMemberAttendanceQrToken(int $memberId): void
    {
        $currentToken = Cache::get($this->memberCurrentQrTokenKey($memberId));
        if (is_string($currentToken) && $currentToken !== '') {
            Cache::forget($this->memberAttendanceQrTokenKey($currentToken));
        }

        Cache::forget($this->memberCurrentQrTokenKey($memberId));
    }

    private function forgetMemberAttendanceQrToken(int $memberId, string $token): void
    {
        Cache::forget($this->memberAttendanceQrTokenKey($token));

        if ($memberId > 0) {
            $currentToken = Cache::get($this->memberCurrentQrTokenKey($memberId));
            if ($currentToken === $token) {
                Cache::forget($this->memberCurrentQrTokenKey($memberId));
            }
        }
    }

    private function memberAttendanceQrTokenKey(string $token): string
    {
        return 'fitgym:member-attendance-qr:token:' . $token;
    }

    private function memberCurrentQrTokenKey(int $memberId): string
    {
        return 'fitgym:member-attendance-qr:member:' . $memberId;
    }

    /**
     * Record trainer check-out
     */
    public function trainerCheckOut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'staff_id' => 'required|exists:trainers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $trainer = Trainer::findOrFail($request->staff_id);

            $today = Carbon::today();

            // Find today's attendance record
            $attendance = DB::table('trainer_attendance')
                ->where('staff_id', $trainer->id)
                ->where('attendance_date', $today->format('Y-m-d'))
                ->where('is_archived', 0)
                ->first();

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'No check-in record found for today'
                ], 422);
            }

            if ($attendance->check_out_time) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff already checked out today'
                ], 422);
            }

            $checkOutTime = now();
            $checkInTime = Carbon::parse($attendance->check_in_time);
            $durationMinutes = $checkInTime->diffInMinutes($checkOutTime);

            DB::table('trainer_attendance')
                ->where('id', $attendance->id)
                ->update([
                    'check_out_time' => $checkOutTime,
                    'updated_at' => $checkOutTime,
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Staff check-out recorded successfully',
                'attendance' => [
                    'id' => $attendance->id,
                    'staff_id' => $trainer->id,
                    'staff_name' => $trainer->full_name,
                    'check_in_time' => $checkInTime->format('Y-m-d H:i:s'),
                    'check_out_time' => $checkOutTime->format('Y-m-d H:i:s'),
                    'duration_minutes' => $durationMinutes,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record staff check-out: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get trainer attendance records
     */
    public function getTrainerAttendance(Request $request)
    {
        try {
            $date = $request->has('date') ? Carbon::parse($request->date) : Carbon::today();

            $query = DB::table('trainer_attendance')
                ->join('trainers', 'trainer_attendance.staff_id', '=', 'trainers.id')
                ->where('trainer_attendance.gym_id', Tenancy::gymId())
                ->where('trainer_attendance.is_archived', 0)
                ->where('trainers.is_archived', 0)
                ->whereDate('trainer_attendance.attendance_date', $date->format('Y-m-d'))
                ->select(
                    'trainer_attendance.id',
                    'trainer_attendance.staff_id',
                    'trainers.full_name as staff_name',
                    'trainers.position',
                    'trainer_attendance.attendance_date',
                    'trainer_attendance.check_in_time',
                    'trainer_attendance.check_out_time',
                    'trainer_attendance.status'
                )
                ->orderBy('trainer_attendance.check_in_time', 'asc');

            $records = $query->get();

            // Get all active trainers to show who hasn't checked in
            $allTrainers = DB::table('trainers')
                ->where('gym_id', Tenancy::gymId())
                ->where('is_archived', 0)
                ->where('status', 'Active')
                ->select('id', 'full_name', 'position')
                ->orderBy('full_name', 'asc')
                ->get();

            $checkedInTrainerIds = $records->pluck('staff_id')->toArray();
            $notCheckedIn = $allTrainers->whereNotIn('id', $checkedInTrainerIds);

            // Log for debugging
            \Log::info('Trainer Attendance Query', [
                'date' => $date->format('Y-m-d'),
                'records_count' => $records->count(),
                'all_trainers_count' => $allTrainers->count(),
                'not_checked_in_count' => $notCheckedIn->count()
            ]);

            $formattedRecords = $records->map(function ($record) {
                $checkInTime = $record->check_in_time ? Carbon::parse($record->check_in_time) : null;
                $checkOutTime = $record->check_out_time ? Carbon::parse($record->check_out_time) : null;

                // Use status from database, or determine based on check-in/check-out
                $status = $record->status ?? 'Absent';
                $statusClass = 'bg-gray-500/20 text-gray-300';

                // Override status based on check-in/check-out if status is "Present"
                if ($checkInTime && $status === 'Present') {
                    if ($checkOutTime) {
                        $status = 'Completed';
                        $statusClass = 'bg-blue-500/20 text-blue-300';
                    } else {
                        $status = 'Present';
                        $statusClass = 'bg-green-500/20 text-green-300';
                    }
                } elseif ($status === 'On Leave') {
                    $statusClass = 'bg-yellow-500/20 text-yellow-300';
                } elseif ($status === 'Absent') {
                    $statusClass = 'bg-gray-500/20 text-gray-300';
                }

                return [
                    'id' => $record->id,
                    'staff_id' => $record->staff_id,
                    'staff_name' => $record->staff_name,
                    'position' => $record->position,
                    'check_in_time' => $checkInTime ? $checkInTime->format('H:i') : '-',
                    'check_out_time' => $checkOutTime ? $checkOutTime->format('H:i') : '-',
                    'status' => $status,
                    'status_class' => $statusClass,
                    'initials' => $this->getInitials($record->staff_name),
                ];
            });

            // Add trainers who haven't checked in
            $notCheckedInFormatted = $notCheckedIn->map(function ($trainer) {
                return [
                    'id' => null,
                    'staff_id' => $trainer->id,
                    'staff_name' => $trainer->full_name,
                    'position' => $trainer->position,
                    'check_in_time' => '-',
                    'check_out_time' => '-',
                    'status' => 'Absent',
                    'status_class' => 'bg-gray-500/20 text-gray-300',
                    'initials' => $this->getInitials($trainer->full_name),
                ];
            });

            $allRecords = $formattedRecords->merge($notCheckedInFormatted);

            return response()->json([
                'success' => true,
                'attendance' => $allRecords->values(),
                'date' => $date->format('Y-m-d')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch staff attendance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark trainer as absent or on leave
     */
    public function markTrainerStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'staff_id' => 'required|exists:trainers,id',
            'status' => 'required|in:Absent,On Leave',
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $trainer = Trainer::findOrFail($request->staff_id);
            $date = $request->has('date') ? Carbon::parse($request->date) : Carbon::today();

            // Check if record exists
            $existing = DB::table('trainer_attendance')
                ->where('gym_id', Tenancy::gymId())
                ->where('staff_id', $trainer->id)
                ->where('attendance_date', $date->format('Y-m-d'))
                ->where('is_archived', 0)
                ->first();

            $now = now();

            if ($existing) {
                // Update existing record
                DB::table('trainer_attendance')
                    ->where('id', $existing->id)
                    ->update([
                        'status' => $request->status,
                        'updated_at' => $now,
                    ]);

                $attendanceId = $existing->id;
            } else {
                // Create new record
                $attendanceId = DB::table('trainer_attendance')->insertGetId([
                    'gym_id' => Tenancy::gymId(),
                    'staff_id' => $trainer->id,
                    'attendance_date' => $date->format('Y-m-d'),
                    'check_in_time' => null,
                    'check_out_time' => null,
                    'status' => $request->status,
                    'is_archived' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Staff status marked as {$request->status}",
                'attendance' => [
                    'id' => $attendanceId,
                    'staff_id' => $trainer->id,
                    'staff_name' => $trainer->full_name,
                    'status' => $request->status,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark staff status: ' . $e->getMessage()
            ], 500);
        }
    }
}
