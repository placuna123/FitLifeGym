<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Features;

// Unified FitGym entry: pick Admin / Trainer / Member, then sign in from one page.
Route::get('/', fn () => Inertia::render('fitgym/Portal'))->name('home');
Route::get('fitgym', fn () => Inertia::render('fitgym/Portal'))->name('fitgym.portal');

Route::get('welcome', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('welcome');

// The starter-kit dashboard isn't part of FitGym; send anyone who lands here to the FitGym sign-in.
Route::get('dashboard', fn () => redirect('/'))->name('dashboard');

// FitGym portals (Vue SPA pages – auth handled in frontend via API)
Route::get('fitgym/admin', fn () => Inertia::render('fitgym/Admin'))->name('fitgym.admin');
Route::get('fitgym/staff', fn () => Inertia::render('fitgym/Trainer'))->name('fitgym.staff');
Route::get('fitgym/member', fn () => Inertia::render('fitgym/Member'))->name('fitgym.member');
// Super Admin / platform portal — deliberately not on the shared role-selector.
Route::get('fitgym/platform', fn () => Inertia::render('fitgym/Platform'))->name('fitgym.platform');

// FitGym API (session-based auth for admin; guest for login)
Route::get('api/csrf-token', function (Request $request) {
    $request->session()->regenerateToken();

    return response()->json([
        'success' => true,
        'csrf_token' => csrf_token(),
    ]);
})->name('api.csrf-token');

// Unified entry page: who is signed in right now, and where do they belong?
Route::get('api/portal/session', [App\Http\Controllers\PortalController::class, 'session'])->name('portal.session');
Route::post('api/portal/logout', [App\Http\Controllers\PortalController::class, 'logout'])->name('portal.logout');

Route::post('api/admin/login', [App\Http\Controllers\AdminAuthController::class, 'login'])->name('admin.login');
Route::post('api/admin/logout', [App\Http\Controllers\AdminAuthController::class, 'logout'])->middleware('auth')->name('admin.logout');
Route::get('api/admin/user', [App\Http\Controllers\AdminAuthController::class, 'user'])->name('admin.user');
Route::get('api/admin/profile', [App\Http\Controllers\AdminAuthController::class, 'profile'])->middleware(['auth', 'tenant', 'role:Admin'])->name('admin.profile');
Route::put('api/admin/profile', [App\Http\Controllers\AdminAuthController::class, 'updateProfile'])->middleware(['auth', 'tenant', 'role:Admin'])->name('admin.update-profile');
Route::post('api/admin/change-password', [App\Http\Controllers\AdminAuthController::class, 'changePassword'])->middleware(['auth', 'tenant', 'role:Admin'])->name('admin.change-password');

// Profile photo for the signed-in account (Admin, Staff, Member or Super Admin)
Route::post('api/profile/photo', [App\Http\Controllers\ProfilePhotoController::class, 'store'])->middleware('auth')->name('profile.photo.store');
Route::delete('api/profile/photo', [App\Http\Controllers\ProfilePhotoController::class, 'destroy'])->middleware('auth')->name('profile.photo.destroy');

// Admin notifications
Route::get('api/admin/notifications', [App\Http\Controllers\AdminNotificationController::class, 'index'])
    ->middleware(['auth', 'tenant', 'role:Admin'])
    ->name('admin.notifications.index');
Route::post('api/admin/notifications/mark-all-read', [App\Http\Controllers\AdminNotificationController::class, 'markAllRead'])
    ->middleware(['auth', 'tenant', 'role:Admin'])
    ->name('admin.notifications.mark-all-read');
Route::post('api/admin/notifications/{notification}/read', [App\Http\Controllers\AdminNotificationController::class, 'markRead'])
    ->middleware(['auth', 'tenant', 'role:Admin'])
    ->name('admin.notifications.read');
Route::post('api/admin/notifications/{notification}/dismiss', [App\Http\Controllers\AdminNotificationController::class, 'dismiss'])
    ->middleware(['auth', 'tenant', 'role:Admin'])
    ->name('admin.notifications.dismiss');

Route::get('api/gym/info', [App\Http\Controllers\GymInfoController::class, 'index'])->name('gym.info');
Route::put('api/gym/info', [App\Http\Controllers\GymInfoController::class, 'update'])->middleware(['auth', 'tenant', 'role:Admin'])->name('gym.update');

Route::get('api/members', [App\Http\Controllers\MemberController::class, 'index'])->middleware(['auth', 'tenant'])->name('members.index');
Route::post('api/members', [App\Http\Controllers\MemberController::class, 'store'])->middleware(['auth', 'tenant'])->name('members.store');
Route::post('api/members/register/send-otp', [App\Http\Controllers\MemberController::class, 'sendRegistrationOtp'])->middleware('throttle:5,10')->name('members.register.send-otp');
Route::post('api/members/register', [App\Http\Controllers\MemberController::class, 'register'])->name('members.register');
Route::post('api/members/{id}/send-sms', [App\Http\Controllers\MemberController::class, 'sendSms'])->middleware(['auth', 'tenant', 'role:Admin'])->name('members.send-sms');
Route::post('api/members/{id}/approve', [App\Http\Controllers\MemberController::class, 'approveMember'])->middleware(['auth', 'tenant', 'role:Admin,Staff'])->name('members.approve');
Route::get('api/members/{id}', [App\Http\Controllers\MemberController::class, 'show'])->middleware(['auth', 'tenant'])->name('members.show');
Route::put('api/members/{id}', [App\Http\Controllers\MemberController::class, 'update'])->middleware(['auth', 'tenant'])->name('members.update');
Route::delete('api/members/{id}', [App\Http\Controllers\MemberController::class, 'destroy'])->middleware(['auth', 'tenant'])->name('members.destroy');

Route::get('api/membership-plans', [App\Http\Controllers\MembershipPlanController::class, 'index'])->middleware(['auth', 'tenant'])->name('membership-plans.index');
Route::post('api/membership-plans', [App\Http\Controllers\MembershipPlanController::class, 'store'])->middleware(['auth', 'tenant', 'role:Admin,Staff'])->name('membership-plans.store');
Route::get('api/membership-plans/{id}', [App\Http\Controllers\MembershipPlanController::class, 'show'])->middleware(['auth', 'tenant'])->name('membership-plans.show');
Route::put('api/membership-plans/{id}', [App\Http\Controllers\MembershipPlanController::class, 'update'])->middleware(['auth', 'tenant', 'role:Admin,Staff'])->name('membership-plans.update');
Route::delete('api/membership-plans/{id}', [App\Http\Controllers\MembershipPlanController::class, 'destroy'])->middleware(['auth', 'tenant', 'role:Admin,Staff'])->name('membership-plans.destroy');

Route::get('api/payments', [App\Http\Controllers\PaymentController::class, 'index'])->middleware(['auth', 'tenant'])->name('payments.index');
Route::post('api/payments', [App\Http\Controllers\PaymentController::class, 'store'])->middleware(['auth', 'tenant'])->name('payments.store');
Route::get('api/payments/{id}', [App\Http\Controllers\PaymentController::class, 'show'])->middleware(['auth', 'tenant'])->name('payments.show');
Route::put('api/payments/{id}', [App\Http\Controllers\PaymentController::class, 'update'])->middleware(['auth', 'tenant'])->name('payments.update');
Route::delete('api/payments/{id}', [App\Http\Controllers\PaymentController::class, 'destroy'])->middleware(['auth', 'tenant'])->name('payments.destroy');

Route::post('api/member/login', [App\Http\Controllers\MemberAuthController::class, 'login'])->name('member.login');
Route::put('api/member/profile', [App\Http\Controllers\MemberAuthController::class, 'updateProfile'])->middleware(['auth', 'tenant'])->name('member.profile.update');
Route::post('api/member/change-password', [App\Http\Controllers\MemberAuthController::class, 'changePassword'])->middleware(['auth', 'tenant'])->name('member.change-password');
Route::post('api/member/logout', [App\Http\Controllers\MemberAuthController::class, 'logout'])->middleware('auth')->name('member.logout');
Route::get('api/member/user', [App\Http\Controllers\MemberAuthController::class, 'user'])->name('member.user');
Route::get('api/member/attendance/logs', [App\Http\Controllers\TrainerAttendanceController::class, 'getMemberLogs'])->middleware(['auth', 'tenant'])->name('member.attendance.logs');
Route::get('api/member/attendance/active', [App\Http\Controllers\TrainerAttendanceController::class, 'getMemberActiveCheckIn'])->middleware(['auth', 'tenant'])->name('member.attendance.active');
Route::post('api/member/attendance/qr', [App\Http\Controllers\TrainerAttendanceController::class, 'issueMemberAttendanceQr'])->middleware(['auth', 'tenant'])->name('member.attendance.qr');
Route::post('api/member/attendance/check-out', [App\Http\Controllers\TrainerAttendanceController::class, 'memberCheckOut'])->middleware(['auth', 'tenant'])->name('member.attendance.checkout');
Route::get('api/member/payments', [App\Http\Controllers\PaymentController::class, 'indexForMember'])->middleware(['auth', 'tenant'])->name('member.payments.index');
Route::post('api/member/payments', [App\Http\Controllers\PaymentController::class, 'storeForMember'])->middleware(['auth', 'tenant'])->name('member.payments.store');
Route::get('api/member/notifications', [App\Http\Controllers\MemberNotificationController::class, 'index'])->middleware(['auth', 'tenant'])->name('member.notifications.index');
Route::post('api/member/notifications/mark-all-read', [App\Http\Controllers\MemberNotificationController::class, 'markAllRead'])->middleware(['auth', 'tenant'])->name('member.notifications.mark-all-read');
Route::post('api/member/support-tickets', [App\Http\Controllers\MemberNotificationController::class, 'submitSupportTicket'])->middleware(['auth', 'tenant', 'throttle:10,60'])->name('member.support-tickets.store');
Route::post('api/member/notifications/{notification}/read', [App\Http\Controllers\MemberNotificationController::class, 'markRead'])->middleware(['auth', 'tenant'])->name('member.notifications.read');

// Gym classes (Admin/Trainer-managed weekly schedule) + Member bookings
Route::get('api/gym-classes', [App\Http\Controllers\GymClassController::class, 'index'])->middleware(['auth', 'tenant'])->name('gym-classes.index');
Route::post('api/gym-classes', [App\Http\Controllers\GymClassController::class, 'store'])->middleware(['auth', 'tenant', 'role:Admin,Staff'])->name('gym-classes.store');
Route::put('api/gym-classes/{id}', [App\Http\Controllers\GymClassController::class, 'update'])->middleware(['auth', 'tenant', 'role:Admin,Staff'])->name('gym-classes.update');
Route::delete('api/gym-classes/{id}', [App\Http\Controllers\GymClassController::class, 'destroy'])->middleware(['auth', 'tenant', 'role:Admin,Staff'])->name('gym-classes.destroy');

Route::get('api/member/classes', [App\Http\Controllers\ClassBookingController::class, 'index'])->middleware(['auth', 'tenant'])->name('member.classes.index');
Route::post('api/member/classes/book', [App\Http\Controllers\ClassBookingController::class, 'store'])->middleware(['auth', 'tenant'])->name('member.classes.book');
Route::delete('api/member/classes/bookings/{id}', [App\Http\Controllers\ClassBookingController::class, 'destroy'])->middleware(['auth', 'tenant'])->name('member.classes.cancel');

Route::get('api/member/progress', [App\Http\Controllers\MemberProgressController::class, 'index'])->middleware(['auth', 'tenant'])->name('member.progress.index');
Route::post('api/member/progress/weight', [App\Http\Controllers\MemberProgressController::class, 'logWeight'])->middleware(['auth', 'tenant'])->name('member.progress.log-weight');
Route::put('api/member/progress/goal', [App\Http\Controllers\MemberProgressController::class, 'updateGoal'])->middleware(['auth', 'tenant'])->name('member.progress.update-goal');

Route::post('api/trainer/login', [App\Http\Controllers\TrainerAuthController::class, 'login'])->name('trainer.login');
Route::get('api/trainer/user', [App\Http\Controllers\TrainerAuthController::class, 'user'])->name('trainer.user');
Route::post('api/trainer/logout', [App\Http\Controllers\TrainerAuthController::class, 'logout'])->name('trainer.logout');

// Reports
Route::get('api/reports/revenue', [App\Http\Controllers\ReportsController::class, 'revenue'])->middleware(['auth', 'tenant'])->name('reports.revenue');
Route::get('api/reports/attendance', [App\Http\Controllers\ReportsController::class, 'attendance'])->middleware(['auth', 'tenant'])->name('reports.attendance');
Route::get('api/reports/membership', [App\Http\Controllers\ReportsController::class, 'membership'])->middleware(['auth', 'tenant'])->name('reports.membership');
Route::get('api/reports/export-all', [App\Http\Controllers\ReportsController::class, 'exportAll'])->middleware(['auth', 'tenant'])->name('reports.export-all');
Route::get('api/reports/quarterly', [App\Http\Controllers\ReportsController::class, 'quarterly'])->middleware(['auth', 'tenant'])->name('reports.quarterly');
Route::get('api/reports/breakdown', [App\Http\Controllers\ReportsController::class, 'breakdown'])->middleware(['auth', 'tenant'])->name('reports.breakdown');
Route::get('api/reports/export-pdf', [App\Http\Controllers\ReportsController::class, 'exportPdf'])->middleware(['auth', 'tenant'])->name('reports.export-pdf');
Route::get('api/reports/export-excel', [App\Http\Controllers\ReportsController::class, 'exportExcel'])->middleware(['auth', 'tenant'])->name('reports.export-excel');
Route::get('api/reports/export-csv', [App\Http\Controllers\ReportsController::class, 'exportCsv'])->middleware(['auth', 'tenant'])->name('reports.export-csv');
Route::get('api/reports/export-json', [App\Http\Controllers\ReportsController::class, 'exportJson'])->middleware(['auth', 'tenant'])->name('reports.export-json');

// Trainer CRUD & schedule (managed by Admin)
Route::get('api/trainer', [App\Http\Controllers\TrainerController::class, 'index'])->middleware(['auth', 'tenant'])->name('trainer.index');
Route::post('api/trainer', [App\Http\Controllers\TrainerController::class, 'store'])->middleware(['auth', 'tenant', 'role:Admin'])->name('trainer.store');
Route::get('api/trainer/{id}', [App\Http\Controllers\TrainerController::class, 'show'])->middleware(['auth', 'tenant'])->whereNumber('id')->name('trainer.show');
Route::put('api/trainer/{id}', [App\Http\Controllers\TrainerController::class, 'update'])->middleware(['auth', 'tenant', 'role:Admin'])->whereNumber('id')->name('trainer.update');
Route::delete('api/trainer/{id}', [App\Http\Controllers\TrainerController::class, 'destroy'])->middleware(['auth', 'tenant', 'role:Admin'])->whereNumber('id')->name('trainer.destroy');
Route::post('api/trainer/{id}/assign', [App\Http\Controllers\TrainerController::class, 'assignMembers'])->middleware(['auth', 'tenant', 'role:Admin'])->whereNumber('id')->name('trainer.assign');
Route::put('api/trainer/{id}/schedule', [App\Http\Controllers\TrainerController::class, 'updateSchedule'])->middleware(['auth', 'tenant', 'role:Admin'])->whereNumber('id')->name('trainer.update-schedule');

// Member attendance (check-in, logs, recent)
Route::post('api/trainer/attendance/check-in', [App\Http\Controllers\TrainerAttendanceController::class, 'checkIn'])->middleware(['auth', 'tenant'])->name('trainer.attendance.checkin');
Route::post('api/trainer/attendance/check-out', [App\Http\Controllers\TrainerAttendanceController::class, 'checkOut'])->middleware(['auth', 'tenant', 'role:Admin,Staff'])->name('trainer.attendance.checkout');
Route::post('api/trainer/attendance/process-qr', [App\Http\Controllers\TrainerAttendanceController::class, 'processMemberQr'])->middleware(['auth', 'tenant'])->name('trainer.attendance.process-qr');
Route::get('api/trainer/attendance/logs', [App\Http\Controllers\TrainerAttendanceController::class, 'getLogs'])->middleware(['auth', 'tenant'])->name('trainer.attendance.logs');
Route::get('api/trainer/attendance/recent', [App\Http\Controllers\TrainerAttendanceController::class, 'getRecentCheckIns'])->middleware(['auth', 'tenant'])->name('trainer.attendance.recent');
Route::get('api/trainer/attendance/currently-in-gym', [App\Http\Controllers\TrainerAttendanceController::class, 'getCurrentlyInGym'])->middleware(['auth', 'tenant'])->name('trainer.attendance.currently-in-gym');

// Admin-managed trainer attendance
Route::post('api/admin/trainer-attendance/check-in', [App\Http\Controllers\TrainerAttendanceController::class, 'trainerCheckIn'])->middleware(['auth', 'tenant'])->name('admin.trainer.attendance.checkin');
Route::post('api/admin/trainer-attendance/check-out', [App\Http\Controllers\TrainerAttendanceController::class, 'trainerCheckOut'])->middleware(['auth', 'tenant'])->name('admin.trainer.attendance.checkout');
Route::get('api/admin/trainer-attendance', [App\Http\Controllers\TrainerAttendanceController::class, 'getTrainerAttendance'])->middleware(['auth', 'tenant'])->name('admin.trainer.attendance');
Route::post('api/admin/trainer-attendance/mark-status', [App\Http\Controllers\TrainerAttendanceController::class, 'markTrainerStatus'])->middleware(['auth', 'tenant'])->name('admin.trainer.attendance.mark-status');

// Trainer members (add, active, renew, expiring)
Route::post('api/trainer/members', [App\Http\Controllers\TrainerMemberController::class, 'store'])->middleware(['auth', 'tenant'])->name('trainer.members.store');
Route::get('api/trainer/members/active', [App\Http\Controllers\TrainerMemberController::class, 'getActiveMembers'])->middleware(['auth', 'tenant'])->name('trainer.members.active');
Route::post('api/trainer/members/{id}/renew', [App\Http\Controllers\TrainerMemberController::class, 'renew'])->middleware(['auth', 'tenant'])->name('trainer.members.renew');
Route::get('api/trainer/members/expiring', [App\Http\Controllers\TrainerMemberController::class, 'getExpiringMembers'])->middleware(['auth', 'tenant'])->name('trainer.members.expiring');

// Trainer profile
Route::get('api/trainer/profile', [App\Http\Controllers\TrainerProfileController::class, 'show'])->middleware(['auth', 'tenant'])->name('trainer.profile.show');
Route::put('api/trainer/profile', [App\Http\Controllers\TrainerProfileController::class, 'update'])->middleware(['auth', 'tenant'])->name('trainer.profile.update');
Route::post('api/trainer/profile/upload-photo', [App\Http\Controllers\TrainerProfileController::class, 'uploadPhoto'])->middleware(['auth', 'tenant'])->name('trainer.profile.upload-photo');
Route::post('api/trainer/profile/change-password', [App\Http\Controllers\TrainerProfileController::class, 'changePassword'])->middleware(['auth', 'tenant'])->name('trainer.profile.change-password');

// Gym self-signup (SaaS onboarding) — public.
Route::get('api/gyms/public', [App\Http\Controllers\GymSignupController::class, 'publicList'])->name('gyms.public');
Route::post('api/gyms/signup', [App\Http\Controllers\GymSignupController::class, 'store'])->name('gyms.signup');

// Platform / Super Admin
Route::post('api/platform/login', [App\Http\Controllers\PlatformAuthController::class, 'login'])->name('platform.login');
Route::get('api/platform/user', [App\Http\Controllers\PlatformAuthController::class, 'user'])->name('platform.user');
Route::post('api/platform/logout', [App\Http\Controllers\PlatformAuthController::class, 'logout'])->name('platform.logout');
Route::post('api/platform/change-password', [App\Http\Controllers\PlatformAuthController::class, 'changePassword'])->middleware(['auth', 'role:SuperAdmin'])->name('platform.change-password');
Route::get('api/platform/gyms', [App\Http\Controllers\PlatformGymController::class, 'index'])->middleware(['auth', 'role:SuperAdmin'])->name('platform.gyms.index');
Route::post('api/platform/gyms', [App\Http\Controllers\PlatformGymController::class, 'store'])->middleware(['auth', 'role:SuperAdmin'])->name('platform.gyms.store');
Route::get('api/platform/gyms/{id}', [App\Http\Controllers\PlatformGymController::class, 'show'])->middleware(['auth', 'role:SuperAdmin'])->name('platform.gyms.show');
Route::put('api/platform/gyms/{id}', [App\Http\Controllers\PlatformGymController::class, 'update'])->middleware(['auth', 'role:SuperAdmin'])->name('platform.gyms.update');
Route::delete('api/platform/gyms/{id}', [App\Http\Controllers\PlatformGymController::class, 'destroy'])->middleware(['auth', 'role:SuperAdmin'])->name('platform.gyms.destroy');
Route::get('api/platform/analytics', [App\Http\Controllers\PlatformGymController::class, 'analytics'])->middleware(['auth', 'role:SuperAdmin'])->name('platform.analytics');
Route::get('api/platform/audit-logs', [App\Http\Controllers\PlatformAuditLogController::class, 'index'])->middleware(['auth', 'role:SuperAdmin'])->name('platform.audit-logs');

require __DIR__.'/settings.php';
