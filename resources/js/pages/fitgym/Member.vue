<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Menu, X } from 'lucide-vue-next';
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';
import { memberAuth, memberAttendance, membershipPlans, memberPayments, memberNotifications, memberClasses, memberProgress, gymInfo } from '@/api/fitgym';
import PasswordToggle from '@/components/PasswordToggle.vue';
import { useMobileNav } from '@/composables/useMobileNav';
import { usePasswordVisibility } from '@/composables/usePasswordVisibility';
import { useSessionGuard } from '@/composables/useSessionGuard';
import { goToEntry } from '@/lib/portal';
import QRCode from 'qrcode';

// Portal colors aligned with the admin/trainer portals
// Box colors come from the shared --fg-card-* tokens in app.css (the keys keep their old names)
const portal = {
  dark: 'var(--fg-card)',
  dark2: 'var(--fg-card-2)',
  dark3: 'var(--fg-card)',
  sidebar: 'var(--fg-card-sidebar)',
  border: 'var(--fg-card-border)',
  purple: '#8A2BE2',
  purpleLight: '#6A4BC4',
  pink: '#E0565B',
  cyan: '#1E88C8',
  lime: '#5FA82E',
  textDim: 'var(--fg-card-ink-soft)',
  primaryText: '#FFFFFF',
};

const isLoggedIn = ref(false);
const loading = ref(true);
const gymInfoData = ref<Record<string, unknown> | null>(null);
const gymDisplayName = computed(() => String(gymInfoData.value?.name || 'FitGym'));
const WEEK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const gymContact = computed(() => {
  const gym = gymInfoData.value ?? {};
  const rawHours = gym.operating_hours;
  const dayIndex = (day: string) => {
    const i = WEEK_DAYS.indexOf(day.toLowerCase());
    return i === -1 ? WEEK_DAYS.length : i;
  };
  const hours = rawHours && typeof rawHours === 'object'
    ? Object.entries(rawHours as Record<string, unknown>)
        .filter(([, value]) => String(value ?? '').trim() !== '')
        .sort(([a], [b]) => dayIndex(a) - dayIndex(b))
        .map(([day, value]) => `${day}: ${String(value)}`)
    : [];
  return {
    phone: String(gym.phone ?? '').trim(),
    email: String(gym.email ?? '').trim(),
    address: String(gym.address ?? '').trim(),
    hours,
  };
});

async function loadGymInfo() {
  try {
    const { data } = await gymInfo.get();
    const res = data as { gym?: Record<string, unknown> };
    gymInfoData.value = res?.gym || null;
  } catch {
    gymInfoData.value = null;
  }
}

const user = ref<{ name: string; email: string } | null>(null);
const member = ref<{
  id?: number;
  full_name?: string;
  email?: string;
  phone?: string;
  member_id?: string;
  status?: string;
  expiry_date?: string;
  membership_plan?: string;
  payment_status?: string;
  membership_plan_id?: number | null;
  fitness_goal?: string | null;
  created_at?: string;
} | null>(null);

type PageId = 'Dashboard' | 'Attendance' | 'Membership' | 'Payments' | 'Progress' | 'Schedule' | 'Profile' | 'Support';
const currentPage = ref<PageId>('Dashboard');
const hasPendingPayment = computed(() => String(member.value?.payment_status || '').toLowerCase() === 'pending');
const memberSinceLabel = computed(() => {
  if (!member.value?.created_at) return '–';
  return new Date(`${member.value.created_at}T00:00:00`).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
});
let memberPortalRefreshTimer: number | null = null;

const navItems: { page: PageId; label: string; description: string }[] = [
  { page: 'Dashboard', label: 'Dashboard', description: 'Overview and quick actions' },
  { page: 'Attendance', label: 'Attendance', description: 'Check-ins and history' },
  { page: 'Membership', label: 'Membership', description: 'Plan and renewal status' },
  { page: 'Payments', label: 'Payments', description: 'Bills, plans, and receipts' },
  { page: 'Progress', label: 'Progress', description: 'Goals and achievements' },
  { page: 'Schedule', label: 'Schedule', description: 'Classes and bookings' },
  { page: 'Profile', label: 'Profile', description: 'Account and preferences' },
  { page: 'Support', label: 'Support', description: 'Help and contact options' },
];

// Attendance (same as Blade: data + Check In/Out + Recent Activity + Calendar)
const attendanceLogs = ref<
  {
    id: number;
    date: string;
    time_in: string;
    time_out: string;
    status: 'Inside' | 'Completed';
    check_in_time: string;
    check_out_time: string | null;
  }[]
>([]);
const isCheckedIn = ref(false);
const attendanceLoading = ref(false);
const attendanceError = ref('');
const attendanceActionLoading = ref(false);

// Attendance QR (generated on demand)
const showAttendanceQr = ref(false);
const attendanceQrLoading = ref(false);
const attendanceQrDataUrl = ref<string>('');
const attendanceQrRawText = ref('');
const attendanceQrExpiresAt = ref('');
const attendanceQrCopyStatus = ref('');

// Schedule: real classes fetched from the backend, booked/cancelled per day.
interface ScheduleClass {
  id: number;
  name: string;
  trainer_name: string;
  start_time: string;
  end_time: string;
  capacity: number;
  booked_count: number;
  is_full: boolean;
  booked_by_me: boolean;
  my_booking_id?: number;
}
const scheduleClasses = ref<ScheduleClass[]>([]);
const scheduleDate = ref(toLocalDateStr(new Date()));
const scheduleLoading = ref(false);
const scheduleActionLoading = ref(false);
const scheduleError = ref('');
const scheduleDateLabel = computed(() =>
  new Date(`${scheduleDate.value}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
);

async function loadScheduleData() {
  scheduleLoading.value = true;
  scheduleError.value = '';
  try {
    const { data } = await memberClasses.list(scheduleDate.value);
    if (data.success && Array.isArray(data.classes)) {
      scheduleClasses.value = data.classes;
    }
  } catch {
    scheduleError.value = 'Failed to load classes.';
  } finally {
    scheduleLoading.value = false;
  }
}

function shiftScheduleDate(days: number) {
  const d = new Date(`${scheduleDate.value}T00:00:00`);
  d.setDate(d.getDate() + days);
  scheduleDate.value = toLocalDateStr(d);
  void loadScheduleData();
}

async function bookClass(cls: ScheduleClass) {
  scheduleActionLoading.value = true;
  try {
    const { data } = await memberClasses.book({ gym_class_id: cls.id, class_date: scheduleDate.value });
    if (data.success) {
      await loadScheduleData();
    } else {
      alert(data.message || 'Failed to book class.');
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    alert(ax.response?.data?.message || 'Failed to book class.');
  } finally {
    scheduleActionLoading.value = false;
  }
}

async function cancelBooking(cls: ScheduleClass) {
  if (!cls.my_booking_id) return;
  scheduleActionLoading.value = true;
  try {
    await memberClasses.cancel(cls.my_booking_id);
    await loadScheduleData();
  } catch {
    alert('Failed to cancel booking.');
  } finally {
    scheduleActionLoading.value = false;
  }
}

// Payments (same as Blade: methods + plans + history)
const paymentPlans = ref<{ id: number; name: string; type?: string; price: string; period?: string; description?: string; features: string[] }[]>([]);
const currentPlan = computed(() => paymentPlans.value.find((p) => p.id === Number(member.value?.membership_plan_id)) ?? null);
const paymentHistory = ref<{ id: number; plan_name: string; amount: string; payment_method: string; payment_date_formatted?: string; payment_date?: string; status: string }[]>([]);
const selectedPaymentMethod = ref('GCash');
const selectedPlanId = ref<number | null>(null);
const paymentsLoading = ref(false);
const paymentsError = ref('');
const paymentProcessing = ref(false);
const paymentReceiptFile = ref<File | null>(null);
const paymentReceiptInputKey = ref(0);
const gymPaymentInfo = ref<{ gcash_number: string; gcash_account_name: string; gcash_qr_url: string }>({
  gcash_number: '',
  gcash_account_name: '',
  gcash_qr_url: '',
});

// Profile (same as Blade: Personal Info + Settings + Edit modal + Add Payment modal)
const profileData = ref({
  name: '',
  email: '',
  phone: '',
  fitnessGoal: '',
});
const FITNESS_GOALS = ['Weight Loss', 'Muscle Gain', 'Endurance', 'General Fitness'];
const profileSaving = ref(false);
const profileError = ref('');
const profileNotice = ref('');
const showChangePassword = ref(false);
const passwordForm = ref({ current_password: '', new_password: '', new_password_confirmation: '' });
const passwordSaving = ref(false);
const pw = usePasswordVisibility();
const passwordError = ref('');
const showEditProfile = ref(false);

// Support (same as Blade: Contact form + success state)
const supportSubmitted = ref(false);
const supportSubject = ref('Equipment Issue');
const supportMessage = ref('');
const supportSending = ref(false);
const supportError = ref('');

// Notifications dropdown
const showNotificationsPanel = ref(false);
const { mobileNavOpen, closeMobileNav } = useMobileNav();
const notificationItems = ref<{ id: number; title: string; message: string; time: string; read: boolean }[]>([]);

async function loadNotifications() {
  try {
    const { data } = await memberNotifications.list();
    if (data.success && Array.isArray(data.notifications)) {
      notificationItems.value = data.notifications.map((n: { id: number; title: string; message: string; time: string; is_read: boolean }) => ({
        id: n.id,
        title: n.title,
        message: n.message,
        time: n.time,
        read: n.is_read,
      }));
    }
  } catch {
    // Keep whatever was already loaded; the bell just won't update this cycle.
  }
}

async function handleNotificationClick(n: { id: number; read: boolean }) {
  if (!n.read) {
    n.read = true;
    try {
      await memberNotifications.markRead(n.id);
    } catch {
      // Non-critical — the unread count will correct itself on the next poll.
    }
  }
  showNotificationsPanel.value = false;
}
const currentPageInfo = computed(() => navItems.find((item) => item.page === currentPage.value) ?? navItems[0]);
const unreadNotificationCount = computed(() => notificationItems.value.filter((item) => !item.read).length);
const memberDisplayName = computed(() => member.value?.full_name || user.value?.name || 'Member');
const memberFirstName = computed(() => memberDisplayName.value.split(/\s+/)[0] || 'Member');
const bookedClassesCount = computed(() => scheduleClasses.value.filter((item) => item.booked_by_me).length);

/**
 * `isInitial` marks the on-mount check. checkAuth also runs on the periodic
 * refresh, so outside the initial check we only leave for the unified sign-in
 * page when the server actually rejected the session (401/403) — a transient
 * network blip must not kick a signed-in member out mid-session.
 *
 * Sign-in itself lives on the unified entry page (fitgym/Portal.vue); this
 * portal only confirms an existing Member session and otherwise bounces there.
 */
async function checkAuth(isInitial = false) {
  // Stay on the loading screen while navigating away, so this page never
  // renders its own content before the redirect takes effect.
  let leaving = false;

  const leaveForEntry = () => {
    leaving = true;
    loading.value = true;
    goToEntry('member', { reason: 'session' });
  };

  try {
    const { data } = await memberAuth.user();
    if (data.success && data.user) {
      user.value = data.user;
      member.value = (data.member ?? null) as typeof member.value;
      isLoggedIn.value = true;
      profileData.value.name = (member.value?.full_name || user.value?.name) ?? '';
      profileData.value.email = (member.value?.email || user.value?.email) ?? '';
      profileData.value.phone = member.value?.phone ?? '';
      profileData.value.fitnessGoal = member.value?.fitness_goal ?? '';
      if (isInitial) {
        void loadGymInfo();
        void loadNotifications();
        void loadScheduleData();
        void loadProgressData();
        if (hasPendingPayment.value) currentPage.value = 'Payments';
      }
    } else {
      isLoggedIn.value = false;
      leaveForEntry();
    }
  } catch (err: unknown) {
    const status = (err as { response?: { status?: number } }).response?.status;
    const sessionRejected = status === 401 || status === 403;
    isLoggedIn.value = false;
    if (isInitial || sessionRejected) leaveForEntry();
  } finally {
    if (!leaving) loading.value = false;
  }
}

async function refreshMemberPortalViewData(page = currentPage.value) {
  if (!isLoggedIn.value) return;

  void loadNotifications();

  if (page === 'Schedule') {
    await loadScheduleData();
    return;
  }

  if (page === 'Attendance') {
    await loadAttendanceData();
    return;
  }

  if (page === 'Progress') {
    await Promise.all([loadAttendanceData(), loadProgressData()]);
    return;
  }

  if (page === 'Payments') {
    await loadPaymentsData();
    return;
  }

  if (page === 'Dashboard' || page === 'Membership' || page === 'Profile') {
    await checkAuth();
  }
}

function stopMemberPortalAutoRefresh() {
  if (memberPortalRefreshTimer != null) {
    window.clearInterval(memberPortalRefreshTimer);
    memberPortalRefreshTimer = null;
  }
}

function startMemberPortalAutoRefresh() {
  stopMemberPortalAutoRefresh();
  if (!isLoggedIn.value) return;

  memberPortalRefreshTimer = window.setInterval(() => {
    void refreshMemberPortalViewData();
  }, 15000);
}

async function handleLogout() {
  try {
    await memberAuth.logout();
  } catch {
    // If logout fails (e.g. already logged out), still land on the sign-in page.
  }
  user.value = null;
  member.value = null;
  isLoggedIn.value = false;
  currentPage.value = 'Dashboard';
  attendanceLogs.value = [];
  isCheckedIn.value = false;
  resetAttendanceQr();
  // Full navigation so Laravel sends a fresh session + CSRF token; avoids
  // "Session expired" on the next login.
  goToEntry('member');
}

async function loadAttendanceData() {
  if (!member.value?.id) return;
  attendanceLoading.value = true;
  attendanceError.value = '';
  try {
    const [logsRes, activeRes] = await Promise.all([
      memberAttendance.logs(),
      memberAttendance.active(),
    ]);
    if (logsRes.data.success && Array.isArray(logsRes.data.logs)) {
      attendanceLogs.value = logsRes.data.logs.map(
        (l: { id: number; date: string; check_in_time: string; check_out_time: string | null }) => {
          const inStr = l.check_in_time ? new Date(l.check_in_time.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
          const outStr = l.check_out_time ? new Date(l.check_out_time.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '-';
          const status = l.check_out_time ? 'Completed' : 'Inside';
          return {
            id: l.id,
            date: l.date,
            time_in: inStr,
            time_out: outStr,
            status,
            check_in_time: l.check_in_time,
            check_out_time: l.check_out_time ?? null,
          };
        }
      );
    } else {
      attendanceLogs.value = [];
    }
    if (activeRes.data.success && activeRes.data.active) {
      isCheckedIn.value = true;
    } else {
      isCheckedIn.value = false;
    }
  } catch {
    attendanceError.value = 'Failed to load attendance.';
    attendanceLogs.value = [];
    isCheckedIn.value = false;
  } finally {
    attendanceLoading.value = false;
  }
}

async function handleCheckInToggle() {
  if (!member.value?.id) return;
  attendanceActionLoading.value = true;
  attendanceError.value = '';
  try {
    if (isCheckedIn.value) {
      const { data } = await memberAttendance.checkOut();
      if (data.success) {
        isCheckedIn.value = false;
        resetAttendanceQr();
        await loadAttendanceData();
      } else {
        attendanceError.value = (data as { message?: string }).message || 'Check-out failed.';
      }
    } else {
      // Per spec: "Check In" generates a personalized QR code to be scanned by Trainer/Admin.
      await generateAttendanceQr();
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } }; message?: string };
    attendanceError.value = ax.response?.data?.message || ax.message || 'Action failed.';
  } finally {
    attendanceActionLoading.value = false;
  }
}

function resetAttendanceQr() {
  showAttendanceQr.value = false;
  attendanceQrDataUrl.value = '';
  attendanceQrRawText.value = '';
  attendanceQrExpiresAt.value = '';
  attendanceQrCopyStatus.value = '';
}

async function copyAttendanceQrText() {
  if (!attendanceQrRawText.value) return;

  if (!navigator.clipboard?.writeText) {
    attendanceQrCopyStatus.value = 'Copy is not available in this browser. Open the page in a secure tab and try again.';
    return;
  }

  try {
    await navigator.clipboard.writeText(attendanceQrRawText.value);
    attendanceQrCopyStatus.value = 'Backup scan code copied. Paste it in the staff scanner if the camera misses the QR.';
  } catch {
    attendanceQrCopyStatus.value = 'Copy failed. Please try again.';
  }
}

async function generateAttendanceQr() {
  if (!member.value?.id) return;
  attendanceQrLoading.value = true;
  attendanceError.value = '';
  try {
    const { data } = await memberAttendance.issueQr();
    const responseData = data as { qr_payload?: Record<string, unknown>; qr_code_text?: string; expires_at?: string };
    const qrPayload = responseData.qr_payload;
    if (!qrPayload) {
      throw new Error((data as { message?: string }).message || 'Failed to generate attendance QR.');
    }

    attendanceQrRawText.value = String(responseData.qr_code_text || '').trim() || JSON.stringify(qrPayload);
    attendanceQrCopyStatus.value = '';
    attendanceQrDataUrl.value = await QRCode.toDataURL(attendanceQrRawText.value, {
      margin: 2,
      width: 320,
      errorCorrectionLevel: 'L',
      color: { dark: '#11101A', light: '#FFFFFF' },
    });
    attendanceQrExpiresAt.value = String(responseData.expires_at || qrPayload.expires_at || '');
    showAttendanceQr.value = true;

    // Poll briefly so the screen updates after trainer/admin scans the QR.
    let tries = 0;
    const timer = window.setInterval(async () => {
      tries++;
      try {
        const res = await memberAttendance.active();
        if (res.data.success && res.data.active) {
          isCheckedIn.value = true;
          window.clearInterval(timer);
          await loadAttendanceData();
        }
      } catch {
        // ignore
      }
      if (tries >= 12) window.clearInterval(timer); // ~60s max
    }, 5000);
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } }; message?: string };
    attendanceError.value = ax.response?.data?.message || ax.message || 'Failed to generate QR code.';
    resetAttendanceQr();
  } finally {
    attendanceQrLoading.value = false;
  }
}

// Visits this month & streak from logs (same as Blade)
const visitsThisMonth = computed(() => {
  const now = new Date();
  const y = now.getFullYear();
  const m = now.getMonth();
  const dates = new Set(attendanceLogs.value.filter((l) => l.status === 'Completed').map((l) => l.date));
  return Array.from(dates).filter((d) => {
    const [year, month] = d.split('-').map(Number);
    return year === y && month - 1 === m;
  }).length;
});

const currentStreak = computed(() => {
  const dates = new Set(
    attendanceLogs.value.filter((l) => l.status === 'Completed').map((l) => l.date)
  );
  let streak = 0;
  let d = new Date();
  for (;;) {
    const dateStr = toLocalDateStr(d);
    if (!dates.has(dateStr)) break;
    streak++;
    d = new Date(d.getFullYear(), d.getMonth(), d.getDate() - 1);
  }
  return streak;
});

// Progress page: attendance rate this month (same as Blade)
const attendanceRatePercent = computed(() => {
  const daysInMonth = new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate();
  const pct = (visitsThisMonth.value / daysInMonth) * 100;
  return Math.min(100, Math.round(pct));
});

// Real weight/goal tracking (replaces the old hardcoded 75kg/BMI 22.4/75%/90% demo numbers)
const progressData = ref<{
  height_cm: number | null;
  goal_weight_kg: number | null;
  current_weight_kg: number | null;
  starting_weight_kg: number | null;
  goal_progress_percent: number | null;
  bmi: number | null;
}>({
  height_cm: null,
  goal_weight_kg: null,
  current_weight_kg: null,
  starting_weight_kg: null,
  goal_progress_percent: null,
  bmi: null,
});
const weightInput = ref('');
const heightInput = ref('');
const goalWeightInput = ref('');
const showGoalEditor = ref(false);
const progressActionLoading = ref(false);

async function loadProgressData() {
  try {
    const { data } = await memberProgress.get();
    if (data.success) {
      progressData.value = data;
      heightInput.value = data.height_cm != null ? String(data.height_cm) : '';
      goalWeightInput.value = data.goal_weight_kg != null ? String(data.goal_weight_kg) : '';
    }
  } catch {
    // Keep whatever was already loaded.
  }
}

async function submitWeightLog() {
  if (!weightInput.value) return;
  progressActionLoading.value = true;
  try {
    await memberProgress.logWeight(Number(weightInput.value));
    weightInput.value = '';
    await loadProgressData();
  } catch {
    alert('Failed to log weight.');
  } finally {
    progressActionLoading.value = false;
  }
}

async function submitGoal() {
  progressActionLoading.value = true;
  try {
    await memberProgress.updateGoal({
      height_cm: heightInput.value ? Number(heightInput.value) : null,
      goal_weight_kg: goalWeightInput.value ? Number(goalWeightInput.value) : null,
    });
    showGoalEditor.value = false;
    await loadProgressData();
  } catch {
    alert('Failed to save goal.');
  } finally {
    progressActionLoading.value = false;
  }
}

// Achievements for Progress page — every badge is now derived from real data
// (attendance logs, streak, goal progress, payment status), not hardcoded.
const progressAchievements = computed(() => {
  const completedCount = attendanceLogs.value.filter((l) => l.status === 'Completed').length;
  return [
    { title: '30-Day Streak', description: 'Completed 30 consecutive days', icon: '🔥', achieved: currentStreak.value >= 30 },
    { title: 'Consistent Attendee', description: 'Checked in 20 times', icon: '💪', achieved: completedCount >= 20 },
    { title: 'First Check-In', description: 'Completed your first gym visit', icon: '⭐', achieved: completedCount >= 1 },
    { title: 'Halfway There', description: 'Reached 50% of your weight goal', icon: '🎯', achieved: (progressData.value.goal_progress_percent ?? 0) >= 50 },
    { title: 'Goal Getter', description: 'Reached your weight goal', icon: '🏆', achieved: (progressData.value.goal_progress_percent ?? 0) >= 100 },
    { title: 'Paid Member', description: 'Membership payment fully verified', icon: '✅', achieved: !hasPendingPayment.value },
  ];
});

// Local Y-M-D string — toISOString() converts to UTC first, which misreports
// "today" as the previous day for anyone west of UTC during early morning
// hours (e.g. the Philippines, UTC+8, between 12am-8am).
function toLocalDateStr(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// Calendar: current month grid with visited / today (same as Blade)
const calendarDays = computed(() => {
  const now = new Date();
  const year = now.getFullYear();
  const month = now.getMonth();
  const first = new Date(year, month, 1);
  const last = new Date(year, month + 1, 0);
  const startPad = first.getDay();
  const daysInMonth = last.getDate();
  const todayStr = toLocalDateStr(now);
  const visitedSet = new Set(
    attendanceLogs.value.filter((l) => l.status === 'Completed').map((l) => l.date)
  );
  const out: { day: number; isPad: boolean; isToday: boolean; isVisited: boolean; isPast: boolean }[] = [];
  for (let i = 0; i < startPad; i++) {
    out.push({ day: 0, isPad: true, isToday: false, isVisited: false, isPast: true });
  }
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    out.push({
      day: d,
      isPad: false,
      isToday: dateStr === todayStr,
      isVisited: visitedSet.has(dateStr),
      isPast: dateStr < todayStr,
    });
  }
  return out;
});

watch(currentPage, (page) => {
  if (page === 'Attendance' && isLoggedIn.value && member.value?.id) {
    loadAttendanceData();
  }
  if ((page === 'Payments' || page === 'Membership') && isLoggedIn.value) {
    loadPaymentsData();
  }
  if (page === 'Progress' && isLoggedIn.value && member.value?.id) {
    loadAttendanceData();
    loadProgressData();
  }
  if (page === 'Schedule' && isLoggedIn.value) {
    loadScheduleData();
  }
  startMemberPortalAutoRefresh();
});

watch(isLoggedIn, (loggedIn) => {
  if (loggedIn) {
    startMemberPortalAutoRefresh();
  } else {
    stopMemberPortalAutoRefresh();
  }
});

async function loadPaymentsData() {
  paymentsLoading.value = true;
  paymentsError.value = '';
  try {
    const [plansRes, paymentsRes, gymRes] = await Promise.all([
      membershipPlans.list(),
      memberPayments.list(),
      gymInfo.get().catch(() => ({ data: { gym: null } })),
    ]);
    const plansRaw = Array.isArray(plansRes.data) ? plansRes.data : (plansRes.data as { plans?: unknown[] })?.plans;
    if (Array.isArray(plansRaw)) {
      paymentPlans.value = plansRaw.map((p: { id: number; name: string; type?: string; price: string | number; period?: string; description?: string; features?: unknown[] }) => ({
        id: p.id,
        name: p.name,
        type: p.type,
        price: String(p.price ?? 0),
        period: p.period,
        description: p.description,
        features: Array.isArray(p.features) ? p.features.map(String).filter(Boolean) : [],
      }));
      if (paymentPlans.value.length && !selectedPlanId.value) {
        selectedPlanId.value = paymentPlans.value[0].id;
      }
    } else {
      paymentPlans.value = [];
    }
    if (paymentsRes.data.success && Array.isArray(paymentsRes.data.payments)) {
      paymentHistory.value = paymentsRes.data.payments as typeof paymentHistory.value;
    } else {
      paymentHistory.value = [];
    }

    const gymData = (gymRes.data as { gym?: Record<string, unknown> })?.gym;
    gymPaymentInfo.value = {
      gcash_number: String(gymData?.gcash_number || ''),
      gcash_account_name: String(gymData?.gcash_account_name || ''),
      gcash_qr_url: String(gymData?.gcash_qr_url || ''),
    };
  } catch {
    paymentsError.value = 'Failed to load payments.';
    paymentPlans.value = [];
    paymentHistory.value = [];
    gymPaymentInfo.value = { gcash_number: '', gcash_account_name: '', gcash_qr_url: '' };
  } finally {
    paymentsLoading.value = false;
  }
}

function onPaymentReceiptChange(event: Event) {
  const input = event.target as HTMLInputElement | null;
  const file = input?.files?.[0] ?? null;
  paymentReceiptFile.value = file;
}

async function handleProceedToPayment() {
  if (!selectedPlanId.value || !selectedPaymentMethod.value) {
    alert('Please select both payment method and membership plan.');
    return;
  }
  const plan = paymentPlans.value.find((p) => p.id === selectedPlanId.value);
  if (!plan) {
    alert('Invalid plan selected.');
    return;
  }
  const amount = parseFloat(plan.price);
  if (Number.isNaN(amount) || amount < 0) {
    alert('Invalid amount.');
    return;
  }
  if (!paymentReceiptFile.value) {
    alert('Please upload your GCash receipt proof.');
    return;
  }
  if (!gymPaymentInfo.value.gcash_number) {
    alert('GCash receiver number is not set yet. Please contact admin.');
    return;
  }
  const confirmMsg = `Confirm payment:\n\nPlan: ${plan.name}\nPayment Method: GCash\nAmount: PHP ${amount.toLocaleString()}\n\nProceed with payment?`;
  if (!confirm(confirmMsg)) return;
  paymentProcessing.value = true;
  paymentsError.value = '';
  try {
    const payload = new FormData();
    payload.append('membership_plan_id', String(selectedPlanId.value));
    payload.append('amount', String(amount));
    payload.append('payment_method', 'GCash');
    payload.append('receipt_proof', paymentReceiptFile.value);

    const { data } = await memberPayments.create(payload);
    if (data.success) {
      alert(`Payment submitted!\n\nPlan: ${plan.name}\nPayment Method: GCash\nAmount: PHP ${amount.toLocaleString()}\nStatus: Pending (for admin verification)\n\nThank you!`);
      paymentReceiptFile.value = null;
      paymentReceiptInputKey.value += 1;
      await loadPaymentsData();
    } else {
      paymentsError.value = (data as { message?: string }).message || 'Payment failed.';
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } }; message?: string };
    paymentsError.value = ax.response?.data?.message || (ax as Error).message || 'Payment failed. Please try again.';
  } finally {
    paymentProcessing.value = false;
  }
}

function openChangePassword() {
  passwordForm.value = { current_password: '', new_password: '', new_password_confirmation: '' };
  passwordError.value = '';
  profileNotice.value = '';
  showChangePassword.value = true;
}

async function submitChangePassword() {
  if (passwordSaving.value) return;
  if (passwordForm.value.new_password !== passwordForm.value.new_password_confirmation) {
    passwordError.value = 'New passwords do not match.';
    return;
  }
  passwordSaving.value = true;
  passwordError.value = '';
  try {
    await memberAuth.changePassword({ ...passwordForm.value });
    showChangePassword.value = false;
    passwordForm.value = { current_password: '', new_password: '', new_password_confirmation: '' };
    profileNotice.value = 'Password changed successfully. Use your new password the next time you sign in.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    passwordError.value = ax.response?.data?.message || 'Failed to change password. Please try again.';
  } finally {
    passwordSaving.value = false;
  }
}

function openEditProfile() {
  profileError.value = '';
  profileNotice.value = '';
  showEditProfile.value = true;
}

async function handleEditProfile(event: Event) {
  event.preventDefault();
  if (profileSaving.value) return;
  const form = event.target as HTMLFormElement;
  const field = (name: string) => ((form.querySelector(`[name="${name}"]`) as HTMLInputElement | HTMLSelectElement | null)?.value ?? '').trim();

  const weightText = field('weight');
  const weight = weightText === '' ? null : Number(weightText);
  if (weight !== null && (!Number.isFinite(weight) || weight < 20 || weight > 400)) {
    profileError.value = 'Weight must be a number between 20 and 400 kg.';
    return;
  }
  // Only log a weight entry when it actually changed.
  const weightChanged = weight !== null && weight !== progressData.value.current_weight_kg;

  profileSaving.value = true;
  profileError.value = '';
  try {
    await memberAuth.updateProfile({
      name: field('name'),
      email: field('email'),
      phone: field('phone') || null,
      fitness_goal: field('fitnessGoal') || null,
      weight_kg: weightChanged ? weight : null,
    });
    await checkAuth();
    if (weightChanged) await loadProgressData();
    showEditProfile.value = false;
    profileNotice.value = 'Profile updated successfully.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    profileError.value = ax.response?.data?.message || 'Failed to update profile. Please try again.';
  } finally {
    profileSaving.value = false;
  }
}

function profileInitials() {
  const n = profileData.value.name.trim();
  if (!n) return '?';
  const parts = n.split(/\s+/);
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  return n.slice(0, 2).toUpperCase();
}

async function handleSupportSubmit(event: Event) {
  event.preventDefault();
  if (!supportMessage.value.trim() || supportSending.value) return;
  supportSending.value = true;
  supportError.value = '';
  try {
    await memberAuth.submitSupportTicket({ subject: supportSubject.value, message: supportMessage.value.trim() });
    supportSubmitted.value = true;
    supportMessage.value = '';
    supportSubject.value = 'Equipment Issue';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    supportError.value = ax.response?.data?.message || 'Failed to send your ticket. Please try again.';
  } finally {
    supportSending.value = false;
  }
}

function closeNotificationsPanel() {
  showNotificationsPanel.value = false;
}

function formatDate(dateString: string | undefined) {
  if (!dateString) return 'N/A';
  return new Date(dateString).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function daysRemaining(expiryDate: string | undefined) {
  if (!expiryDate) return 0;
  const diff = (new Date(expiryDate).getTime() - Date.now()) / (1000 * 60 * 60 * 24);
  return Math.max(0, Math.ceil(diff));
}

useSessionGuard({
  role: 'member',
  fetchUser: memberAuth.user,
  currentEmail: () => user.value?.email,
  isActive: () => isLoggedIn.value,
});

onMounted(async () => {
  await checkAuth(true);
  startMemberPortalAutoRefresh();
});

onUnmounted(() => {
  stopMemberPortalAutoRefresh();
});
</script>

<template>
  <Head :title="`${gymDisplayName} - Member Portal`" />
  <div class="member-portal fg-marble-bg min-h-screen text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark }">
    <!-- Sign-in/registration now live on the unified entry page (fitgym/Portal.vue).
         This portal only ever renders once a Member session is confirmed;
         checkAuth() redirects everyone else there before this template matters. -->
    <div v-if="!isLoggedIn || loading" class="flex min-h-screen items-center justify-center">
      <p :style="{ color: portal.textDim }">Loading...</p>
    </div>

    <div v-else class="sm:p-4 lg:p-6">
      <div class="member-shell fg-shell fg-marble-bg mx-auto flex min-h-[calc(100vh-2rem)] max-w-[1500px] overflow-hidden rounded-[1.75rem] border shadow-2xl" style="border-color: rgba(160, 122, 31, 0.35)">
        <div v-if="mobileNavOpen" class="fg-nav-backdrop" aria-hidden="true" @click="closeMobileNav" />
        <aside id="member-sidebar" class="member-sidebar fg-sidebar flex w-[17.5rem] shrink-0 flex-col px-5 py-8" :class="{ 'is-open': mobileNavOpen }" :style="{ backgroundColor: portal.sidebar }">
          <div class="flex items-start justify-between gap-3 pb-6">
            <div class="min-w-0">
              <h1 class="text-2xl font-bold break-words text-[var(--fg-card-ink)]">{{ gymDisplayName }}</h1>
              <p class="mt-1 text-sm" :style="{ color: portal.textDim }">Member Portal</p>
            </div>
            <button type="button" class="fg-menu-btn fg-sidebar-close" aria-label="Close navigation menu" @click="closeMobileNav">
              <X class="h-5 w-5" aria-hidden="true" />
            </button>
          </div>

          <div class="rounded-2xl border p-4" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }">
            <div class="flex items-center gap-3">
              <div class="flex h-12 w-12 items-center justify-center rounded-2xl text-lg font-bold" :style="{ backgroundColor: portal.purple, color: portal.primaryText }">
                {{ profileInitials() }}
              </div>
              <div class="min-w-0">
                <p class="truncate font-semibold text-[var(--fg-card-ink)]">{{ memberDisplayName }}</p>
                <p class="truncate text-xs" :style="{ color: portal.textDim }">{{ member?.membership_plan || 'Active Member' }}</p>
              </div>
            </div>
          </div>

          <nav class="mt-6 flex flex-1 flex-col gap-2">
            <button
              v-for="item in navItems"
              :key="item.page"
              type="button"
              class="member-nav-item flex w-full items-start rounded-xl px-4 py-3 text-left transition-colors"
              :class="{ active: currentPage === item.page }"
              :style="currentPage === item.page ? { backgroundColor: portal.purple, color: portal.primaryText } : { color: 'var(--fg-card-ink)' }"
              @click="currentPage = item.page; closeMobileNav()"
            >
              <span class="min-w-0">
                <span class="block text-sm font-semibold">{{ item.label }}</span>
                <span class="mt-1 block text-xs opacity-70">{{ item.description }}</span>
              </span>
            </button>
          </nav>

          <div class="pt-6">
            <button
              type="button"
              class="w-full rounded-xl px-4 py-3 text-sm font-semibold transition-opacity hover:opacity-90"
              :style="{ backgroundColor: portal.pink, color: '#fff' }"
              @click="handleLogout"
            >
              Logout
            </button>
          </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
          <div class="fg-mobile-bar">
            <button
              type="button"
              class="fg-menu-btn"
              aria-label="Open navigation menu"
              aria-controls="member-sidebar"
              :aria-expanded="mobileNavOpen"
              @click="mobileNavOpen = true"
            >
              <Menu class="h-5 w-5" aria-hidden="true" />
            </button>
            <div class="min-w-0">
              <p class="truncate text-base font-bold text-[var(--fg-card-ink)]">{{ gymDisplayName }}</p>
              <p class="truncate text-xs" :style="{ color: portal.textDim }">Member Portal</p>
            </div>
          </div>

          <header class="member-header flex flex-col gap-4 border-b px-4 py-5 sm:px-6 sm:py-6 lg:flex-row lg:items-center lg:justify-between lg:px-10" style="border-color: rgba(160, 122, 31, 0.3)">
            <div class="min-w-0">
              <p class="text-sm font-semibold uppercase tracking-[0.24em] text-[var(--fg-gold)]">Member Workspace</p>
              <h2 class="mt-2 text-2xl font-bold text-[var(--fg-ink)] sm:text-3xl">{{ currentPageInfo.label }}</h2>
              <p class="mt-2 max-w-2xl text-sm text-[var(--fg-ink-soft)]">{{ currentPageInfo.description }}</p>
            </div>

            <div class="relative flex flex-wrap items-center gap-3">
              <div class="rounded-xl border px-4 py-3" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
                <p class="text-xs uppercase tracking-[0.2em]" :style="{ color: portal.textDim }">Member ID</p>
                <p class="mt-1 text-sm font-semibold text-[var(--fg-card-ink)]">{{ member?.member_id || 'Pending' }}</p>
              </div>
              <button
                type="button"
                class="flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold transition-opacity hover:opacity-90"
                :style="{ backgroundColor: portal.dark3, color: 'var(--fg-card-ink)', border: `1px solid ${portal.border}` }"
                @click="showNotificationsPanel = !showNotificationsPanel"
              >
                <span>Notifications</span>
                <span v-if="unreadNotificationCount" class="flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1 text-[11px] font-bold" :style="{ backgroundColor: portal.purple, color: portal.primaryText }">
                  {{ unreadNotificationCount }}
                </span>
              </button>
              <div class="rounded-xl border px-4 py-3" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
                <p class="text-xs uppercase tracking-[0.2em]" :style="{ color: portal.textDim }">Status</p>
                <p class="mt-1 text-sm font-semibold" :style="{ color: member?.status === 'Active' ? portal.lime : portal.pink }">{{ member?.status || 'Inactive' }}</p>
              </div>

              <div
                v-if="showNotificationsPanel"
                class="absolute right-0 top-full z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-2xl border shadow-2xl"
                :style="{ backgroundColor: portal.dark3, borderColor: portal.border }"
                @click.stop
              >
                <div class="border-b px-4 py-3" :style="{ borderColor: portal.border }">
                  <h3 class="font-semibold text-[var(--fg-card-ink)]">Notifications</h3>
                </div>
                <div class="max-h-72 overflow-y-auto p-3">
                  <template v-if="notificationItems.length === 0">
                    <p class="py-4 text-center text-sm" :style="{ color: portal.textDim }">No new notifications</p>
                  </template>
                  <button
                    v-for="n in notificationItems"
                    :key="n.id"
                    type="button"
                    class="mb-2 w-full rounded-xl p-3 text-left transition-opacity hover:opacity-90"
                    :style="n.read ? { backgroundColor: 'transparent', color: portal.textDim } : { backgroundColor: portal.dark2 }"
                    @click="handleNotificationClick(n)"
                  >
                    <p class="text-sm font-medium text-[var(--fg-card-ink)]">{{ n.title }}</p>
                    <p class="mt-1 text-xs leading-5" :style="{ color: portal.textDim }">{{ n.message }}</p>
                    <p class="mt-2 text-[11px] uppercase tracking-[0.16em]" :style="{ color: portal.textDim }">{{ n.time }}</p>
                  </button>
                </div>
              </div>
            </div>

            <div
              v-if="showNotificationsPanel"
              class="fixed inset-0 z-40"
              aria-hidden="true"
              @click="closeNotificationsPanel"
            />
          </header>

          <div
            v-if="hasPendingPayment"
            class="mx-4 mt-5 flex flex-col items-start justify-between gap-3 rounded-xl border px-5 py-4 sm:mx-6 sm:mt-6 sm:flex-row sm:items-center lg:mx-10"
            :style="{ backgroundColor: 'rgba(255, 107, 107, 0.12)', borderColor: portal.pink }"
          >
            <div>
              <p class="text-sm font-semibold" :style="{ color: portal.pink }">⚠ Payment Pending</p>
              <p class="mt-1 text-sm" :style="{ color: portal.textDim }">
                Complete your GCash payment below, or pay in person at the front desk, to activate your membership.
              </p>
            </div>
            <button
              type="button"
              class="shrink-0 rounded-lg px-4 py-2 text-sm font-semibold transition-opacity hover:opacity-90"
              :style="{ backgroundColor: portal.pink, color: '#fff' }"
              @click="currentPage = 'Payments'"
            >
              Go to Payments
            </button>
          </div>

          <main class="flex-1 overflow-x-hidden overflow-y-auto px-4 py-5 sm:px-6 sm:py-6 lg:px-10">
          <!-- Dashboard -->
          <div v-if="currentPage === 'Dashboard'" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="rounded-xl border p-4 sm:p-6 lg:col-span-2" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">
                Welcome back, {{ memberFirstName }}!
              </h2>
              <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-lg p-4 text-center" :style="{ backgroundColor: portal.dark2 }">
                  <p class="text-3xl font-bold" :style="{ color: portal.cyan }">{{ attendanceLoading ? '-' : visitsThisMonth }}</p>
                  <p class="text-sm" :style="{ color: portal.textDim }">Visits This Month</p>
                </div>
                <div class="rounded-lg p-4 text-center" :style="{ backgroundColor: portal.dark2 }">
                  <p class="text-3xl font-bold" :style="{ color: portal.lime }">{{ attendanceLoading ? '-' : currentStreak }}</p>
                  <p class="text-sm" :style="{ color: portal.textDim }">Current Streak</p>
                </div>
                <div class="rounded-lg p-4 text-center" :style="{ backgroundColor: portal.dark2 }">
                  <p class="text-3xl font-bold" :style="{ color: portal.purple }">{{ bookedClassesCount }}</p>
                  <p class="text-sm" :style="{ color: portal.textDim }">Classes Booked</p>
                </div>
              </div>
              <div class="flex flex-col space-y-3 sm:flex-row sm:space-x-3 sm:space-y-0">
                <button type="button" class="flex-1 rounded-lg py-3 font-semibold transition-opacity hover:opacity-90" :style="{ backgroundColor: portal.purple, color: portal.primaryText }" @click="currentPage = 'Attendance'">Check Attendance</button>
                <button type="button" class="flex-1 rounded-lg py-3 font-semibold transition-opacity hover:opacity-90" :style="{ backgroundColor: portal.pink, color: portal.primaryText }" @click="currentPage = 'Membership'">Renew Membership</button>
                <button type="button" class="flex-1 rounded-lg py-3 font-semibold text-[var(--fg-card-ink)] transition-opacity hover:opacity-90" :style="{ backgroundColor: portal.dark2, border: `1px solid ${portal.border}` }" @click="currentPage = 'Schedule'">View Schedule</button>
              </div>
            </div>
            <div class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">Membership Status</h2>
              <div class="flex flex-col items-center text-center">
                <div
                  class="mb-3 rounded-full p-4"
                  :style="member?.status === 'Active'
                    ? { backgroundColor: 'rgba(163, 230, 53, 0.16)', color: portal.lime }
                    : { backgroundColor: 'rgba(255, 107, 107, 0.16)', color: portal.pink }"
                >
                  {{ member?.status === 'Active' ? '✓' : '✕' }}
                </div>
                <p class="text-lg font-semibold text-[var(--fg-card-ink)]">{{ member?.status || 'Inactive' }}</p>
                <p class="text-sm" :style="{ color: portal.textDim }">{{ member?.membership_plan || 'No Plan' }}</p>
                <div v-if="member?.expiry_date" class="mt-6 w-full">
                  <div class="mb-1 flex justify-between text-xs" :style="{ color: portal.textDim }">
                    <span>Days Remaining</span>
                    <span>{{ daysRemaining(member.expiry_date) }} days</span>
                  </div>
                  <button type="button" class="mt-6 w-full rounded-lg py-3 font-semibold transition-opacity hover:opacity-90" :style="{ backgroundColor: portal.purple, color: portal.primaryText }" @click="currentPage = 'Membership'">Renew Membership</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Membership -->
          <div v-else-if="currentPage === 'Membership'" class="grid grid-cols-1 gap-6 lg:grid-cols-5">
            <div class="rounded-xl border p-4 sm:p-6 text-center lg:col-span-2" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">Current Membership</h2>
              <div
                class="mb-3 inline-block rounded-full p-4"
                :style="member?.status === 'Active'
                  ? { backgroundColor: 'rgba(163, 230, 53, 0.16)', color: portal.lime }
                  : { backgroundColor: 'rgba(255, 107, 107, 0.16)', color: portal.pink }"
              >
                {{ member?.status === 'Active' ? '✓' : '✕' }}
              </div>
              <p class="text-2xl font-bold text-[var(--fg-card-ink)]">{{ member?.membership_plan || 'No Plan' }}</p>
              <p class="mb-6 font-semibold" :style="member?.status === 'Active' ? { color: portal.lime } : { color: portal.pink }">{{ member?.status || 'Inactive' }} Membership</p>
              <div class="member-detail-list my-6 border-t border-b py-6 text-left" :style="{ borderColor: portal.border }">
                <div class="flex justify-between text-sm">
                  <span :style="{ color: portal.textDim }">Plan Type:</span>
                  <span class="font-medium text-[var(--fg-card-ink)]">{{ member?.membership_plan || 'N/A' }}</span>
                </div>
                <div class="mt-3 flex justify-between text-sm">
                  <span :style="{ color: portal.textDim }">Status:</span>
                  <span class="font-medium" :style="member?.status === 'Active' ? { color: portal.lime } : { color: portal.pink }">{{ member?.status || 'Inactive' }}</span>
                </div>
                <div class="mt-3 flex justify-between text-sm">
                  <span :style="{ color: portal.textDim }">Payment Status:</span>
                  <span class="font-medium text-[var(--fg-card-ink)]">{{ member?.payment_status ?? 'N/A' }}</span>
                </div>
                <template v-if="member?.expiry_date">
                  <div class="mt-3 flex justify-between text-sm">
                    <span :style="{ color: portal.textDim }">End Date:</span>
                    <span class="font-medium text-[var(--fg-card-ink)]">{{ formatDate(member.expiry_date) }}</span>
                  </div>
                  <div class="mt-3 flex justify-between text-sm">
                    <span :style="{ color: portal.textDim }">Days Remaining:</span>
                    <span class="text-lg font-bold text-[var(--fg-card-ink)]">{{ daysRemaining(member.expiry_date) }} days</span>
                  </div>
                </template>
              </div>
              <button type="button" class="w-full rounded-lg py-3 font-semibold transition-opacity hover:opacity-90" :style="{ backgroundColor: portal.purple, color: portal.primaryText }" @click="currentPage = 'Payments'">Renew Membership</button>
            </div>
            <div class="rounded-xl border p-4 sm:p-6 lg:col-span-3" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">Membership Benefits</h2>
              <div class="space-y-4">
                <div class="rounded-lg border p-4" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }">
                  <h3 class="mb-1 flex items-center text-lg font-semibold text-[var(--fg-card-ink)]">⭐ {{ currentPlan?.name || member?.membership_plan || 'Your Plan' }}</h3>
                  <p v-if="currentPlan?.description" class="mb-3 text-sm" :style="{ color: portal.textDim }">{{ currentPlan.description }}</p>
                  <ul v-if="currentPlan?.features.length" class="list-inside list-disc space-y-2 text-sm leading-relaxed text-[var(--fg-card-ink)]">
                    <li v-for="feature in currentPlan.features" :key="feature">{{ feature }}</li>
                  </ul>
                  <p v-else-if="paymentsLoading" class="text-sm" :style="{ color: portal.textDim }">Loading plan details...</p>
                  <p v-else class="text-sm" :style="{ color: portal.textDim }">Your gym hasn't listed the features of this plan yet. Ask the front desk for details.</p>
                </div>
                <div class="rounded-lg border p-4" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }">
                  <h3 class="mb-3 flex items-center text-lg font-semibold text-[var(--fg-card-ink)]">📱 Included With Every Membership</h3>
                  <ul class="list-inside list-disc space-y-2 text-sm leading-relaxed text-[var(--fg-card-ink)]">
                    <li>QR code check-in and attendance history</li>
                    <li>Class schedule and online class booking</li>
                    <li>Weight, BMI, and goal progress tracking</li>
                    <li>Online GCash payments with receipt upload</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <!-- Attendance (same as Blade: Tracking + Summary + Recent Activity + Calendar) -->
          <div v-else-if="currentPage === 'Attendance'" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-1">
              <div class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
                <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">Attendance Tracking</h2>
                <p v-if="attendanceError" class="mb-3 text-sm text-red-400">{{ attendanceError }}</p>
                <button
                  type="button"
                  :disabled="attendanceActionLoading || attendanceQrLoading"
                  class="mb-6 w-full rounded-lg py-3 text-lg font-semibold transition-all duration-200 hover:opacity-90 disabled:opacity-50"
                  :style="isCheckedIn ? { backgroundColor: portal.pink, color: '#fff' } : { backgroundColor: portal.purple, color: portal.primaryText }"
                  @click="handleCheckInToggle"
                >
                  {{
                    attendanceActionLoading || attendanceQrLoading
                      ? 'Please wait...'
                      : isCheckedIn
                        ? 'Check Out Here'
                        : 'Get Attendance QR'
                  }}
                </button>
                <div
                  v-if="showAttendanceQr"
                  class="mb-6 rounded-lg border p-4 text-center"
                  :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
                >
                  <p class="mb-3 text-sm font-semibold text-[var(--fg-card-ink)]">Attendance QR</p>
                  <p class="mb-4 text-xs" :style="{ color: portal.textDim }">
                    {{
                      isCheckedIn
                        ? 'Scan this same QR again with Staff/Admin when leaving the gym, or tap "Check Out Here" as backup.'
                        : 'Show this QR to Staff/Admin to check in. The same QR can also be scanned once more for check-out.'
                    }}
                  </p>
                  <p v-if="attendanceQrExpiresAt" class="mb-4 text-[11px] uppercase tracking-wide font-semibold text-[#8fd16a]">
                    Valid until {{ new Date(attendanceQrExpiresAt).toLocaleString([], { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' }) }}
                  </p>
                  <div class="mx-auto mb-3 w-fit rounded-lg bg-white p-3">
                    <img v-if="attendanceQrDataUrl" :src="attendanceQrDataUrl" alt="Member Attendance QR" class="h-56 w-56 sm:h-64 sm:w-64" />
                    <p v-else class="px-6 py-10 text-sm text-gray-700">Generating...</p>
                  </div>
                  <div class="flex flex-col gap-2 sm:flex-row">
                    <button
                      type="button"
                      class="w-full rounded-lg py-2 text-sm font-semibold transition-opacity hover:opacity-90"
                      :style="{ backgroundColor: portal.dark3, color: 'var(--fg-card-ink)', border: `1px solid ${portal.border}` }"
                      @click="generateAttendanceQr"
                    >
                      Refresh QR
                    </button>
                    <button
                      type="button"
                      class="w-full rounded-lg py-2 text-sm font-semibold transition-opacity hover:opacity-90"
                      :style="{ backgroundColor: portal.purple, color: portal.primaryText }"
                      :disabled="!attendanceQrRawText"
                      @click="copyAttendanceQrText"
                    >
                      Copy Backup Code
                    </button>
                  </div>
                  <p v-if="attendanceQrCopyStatus" class="mt-3 text-xs text-cyan-300">
                    {{ attendanceQrCopyStatus }}
                  </p>
                </div>
                <div class="mb-4 flex justify-around rounded-lg p-4 text-center" :style="{ backgroundColor: portal.dark2 }">
                  <div>
                    <p class="text-sm" :style="{ color: portal.textDim }">This Month Summary</p>
                    <p class="text-2xl font-bold text-[var(--fg-card-ink)]">{{ attendanceLoading ? '–' : visitsThisMonth }}</p>
                    <p class="text-xs" :style="{ color: portal.textDim }">Total Visits</p>
                  </div>
                  <div class="border-l" :style="{ borderColor: portal.border }" />
                  <div>
                    <p class="text-sm" :style="{ color: portal.textDim }"> </p>
                    <p class="text-2xl font-bold text-[var(--fg-card-ink)]">{{ attendanceLoading ? '–' : currentStreak }}</p>
                    <p class="text-xs" :style="{ color: portal.textDim }">Current Streak</p>
                  </div>
                </div>
                <div>
                  <h3 class="mb-3 text-lg font-semibold text-[var(--fg-card-ink)]">Recent Activity</h3>
                  <div class="max-h-60 space-y-3 overflow-y-auto">
                    <template v-if="attendanceLoading">
                      <p class="py-4 text-center text-sm" :style="{ color: portal.textDim }">Loading...</p>
                    </template>
                    <template v-else-if="attendanceLogs.length === 0">
                      <p class="py-4 text-center text-sm" :style="{ color: portal.textDim }">No attendance records yet</p>
                    </template>
                    <template v-else>
                      <div
                        v-for="act in attendanceLogs.slice(0, 20)"
                        :key="act.id"
                        class="flex items-center justify-between text-sm"
                      >
                        <div>
                          <p class="text-[var(--fg-card-ink)]">{{ act.date }}</p>
                          <p class="text-xs" :style="{ color: portal.textDim }">
                            Time-In: {{ act.time_in }} • Time-Out: {{ act.time_out }}
                          </p>
                        </div>
                        <p :style="{ color: act.status === 'Inside' ? portal.lime : portal.cyan }">{{ act.status }}</p>
                      </div>
                    </template>
                  </div>
                </div>
              </div>
            </div>
            <div class="lg:col-span-2">
              <div class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
                <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">Calendar View</h2>
                <div class="mb-2 grid grid-cols-7 gap-1 text-center text-xs sm:gap-2 sm:text-sm" :style="{ color: portal.textDim }">
                  <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                </div>
                <div class="grid grid-cols-7 gap-1 sm:gap-2">
                  <div
                    v-for="(cell, idx) in calendarDays"
                    :key="idx"
                    class="py-2 text-center text-xs rounded-lg sm:text-sm"
                    :class="{
                      'font-bold': cell.isToday && !cell.isPad,
                      'text-black': (cell.isToday || cell.isVisited) && !cell.isPad,
                    }"
                    :style="cell.isPad ? {} : cell.isToday ? { backgroundColor: portal.cyan } : cell.isVisited ? { backgroundColor: portal.lime } : cell.isPast ? { backgroundColor: portal.dark2, color: portal.textDim } : { color: 'var(--fg-card-ink)' }"
                  >
                    {{ cell.isPad ? '' : cell.day }}
                  </div>
                </div>
                <div class="mt-4 flex space-x-6 text-sm">
                  <div class="flex items-center space-x-2">
                    <div class="h-4 w-4 rounded" :style="{ backgroundColor: portal.lime }" />
                    <span :style="{ color: portal.textDim }">Visited</span>
                  </div>
                  <div class="flex items-center space-x-2">
                    <div class="h-4 w-4 rounded" :style="{ backgroundColor: portal.cyan }" />
                    <span :style="{ color: portal.textDim }">Today</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Payments (same as Blade: Payment Methods + Plan + Proceed + History) -->
          <div v-else-if="currentPage === 'Payments'" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">Payment Methods</h2>
              <p v-if="paymentsError" class="mb-3 text-sm text-red-400">{{ paymentsError }}</p>
              <div class="mb-6">
                <h3 class="mb-3 text-sm font-semibold" :style="{ color: portal.textDim }">Payment Method</h3>
                <div class="rounded-lg p-4 text-center" :style="{ backgroundColor: portal.purple, color: portal.primaryText }">
                  GCash
                </div>
                <p class="mt-2 text-xs" :style="{ color: portal.textDim }">
                  Paying cash in person instead? Visit the front desk — staff will record your payment there.
                </p>
              </div>
              <div v-if="selectedPaymentMethod === 'GCash'" class="mb-6 rounded-lg border p-4" :style="{ borderColor: portal.border, backgroundColor: portal.dark2 }">
                <h3 class="mb-2 text-sm font-semibold text-[var(--fg-card-ink)]">Send Payment To</h3>
                <div class="mb-4 rounded-lg border p-3" :style="{ borderColor: portal.border, backgroundColor: portal.dark }">
                  <p class="text-xs uppercase tracking-wide" :style="{ color: portal.textDim }">Official GCash Number</p>
                  <p class="text-base font-semibold text-[var(--fg-card-ink)]">
                    {{ gymPaymentInfo.gcash_number || 'Not set yet. Please contact admin first.' }}
                  </p>
                  <p class="mt-2 text-xs uppercase tracking-wide" :style="{ color: portal.textDim }">Account Name</p>
                  <p class="text-sm text-[var(--fg-card-ink)]">
                    {{ gymPaymentInfo.gcash_account_name || 'Not set' }}
                  </p>
                  <div v-if="gymPaymentInfo.gcash_qr_url" class="mt-3">
                    <p class="mb-2 text-xs uppercase tracking-wide" :style="{ color: portal.textDim }">Scan QR</p>
                    <img :src="gymPaymentInfo.gcash_qr_url" alt="Official GCash QR" class="max-h-56 rounded border" :style="{ borderColor: portal.border }" />
                  </div>
                </div>
                <h3 class="mb-2 text-sm font-semibold text-[var(--fg-card-ink)]">Upload GCash Receipt Proof</h3>
                <input
                  :key="paymentReceiptInputKey"
                  type="file"
                  accept=".jpg,.jpeg,.png,.pdf"
                  class="w-full rounded border p-2 text-sm text-[var(--fg-card-ink)] file:mr-3 file:rounded file:border-0 file:px-3 file:py-1 file:font-medium"
                  :style="{ borderColor: portal.border, backgroundColor: portal.dark }"
                  @change="onPaymentReceiptChange"
                />
                <p class="mt-2 text-xs" :style="{ color: portal.textDim }">Accepted: JPG, PNG, PDF (max 5MB)</p>
                <p v-if="paymentReceiptFile" class="mt-1 text-xs text-[var(--fg-card-ink)]">Selected: {{ paymentReceiptFile.name }}</p>
              </div>
              <div>
                <h3 class="mb-3 text-sm font-semibold" :style="{ color: portal.textDim }">Select Membership Plan</h3>
                <div class="space-y-3">
                  <button
                    v-for="plan in paymentPlans"
                    :key="plan.id"
                    type="button"
                    class="flex w-full items-center justify-between rounded-lg p-4 text-left transition-colors"
                    :style="selectedPlanId === plan.id ? { backgroundColor: portal.dark3, color: 'var(--fg-card-ink)', border: `1px solid ${portal.purple}` } : { backgroundColor: portal.dark2, color: portal.textDim }"
                    @click="selectedPlanId = plan.id"
                  >
                    <div>
                      <p class="font-semibold text-[var(--fg-card-ink)]">{{ plan.name }}</p>
                      <p class="text-xs">{{ plan.period || plan.description || '' }}</p>
                    </div>
                    <p class="font-bold" :style="{ color: portal.lime }">₱{{ Number(plan.price).toLocaleString() }}</p>
                  </button>
                </div>
                <p v-if="paymentsLoading && !paymentPlans.length" class="py-4 text-sm" :style="{ color: portal.textDim }">Loading plans...</p>
                <p v-if="!paymentsLoading && !paymentPlans.length" class="py-4 text-sm" :style="{ color: portal.textDim }">No plans available.</p>
              </div>
              <button
                type="button"
                :disabled="paymentProcessing || !selectedPlanId"
                class="mt-6 w-full rounded-lg py-3 font-semibold transition-opacity disabled:cursor-not-allowed disabled:opacity-50"
                :style="paymentProcessing
                  ? { backgroundColor: portal.dark2, color: portal.textDim, border: `1px solid ${portal.border}` }
                  : { backgroundColor: portal.purple, color: portal.primaryText }"
                @click="handleProceedToPayment"
              >
                {{ paymentProcessing ? 'Processing Payment...' : 'Proceed to Payment' }}
              </button>
            </div>
            <div class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">Payment History</h2>
              <div class="space-y-4">
                <template v-if="paymentsLoading && !paymentHistory.length">
                  <p class="py-4 text-center text-sm" :style="{ color: portal.textDim }">Loading...</p>
                </template>
                <template v-else-if="!paymentHistory.length">
                  <p class="py-4 text-center text-sm" :style="{ color: portal.textDim }">No payment records yet</p>
                </template>
                <template v-else>
                  <div
                    v-for="item in paymentHistory"
                    :key="item.id"
                    class="flex items-center justify-between rounded-lg p-4"
                    :style="{ backgroundColor: portal.dark2 }"
                  >
                    <div>
                      <p class="font-semibold text-[var(--fg-card-ink)]">{{ item.plan_name }}</p>
                      <p class="text-xs" :style="{ color: portal.textDim }">{{ item.payment_date_formatted || item.payment_date }}</p>
                      <p class="text-xs" :style="{ color: portal.textDim }">{{ item.payment_method }}</p>
                    </div>
                    <div class="text-right">
                      <p class="text-lg font-bold text-[var(--fg-card-ink)]">₱{{ item.amount }}</p>
                      <p class="text-xs font-semibold" :style="{ color: portal.lime }">{{ item.status }}</p>
                    </div>
                  </div>
                </template>
              </div>
            </div>
          </div>

          <!-- Progress (same as Blade: Fitness Progress + Achievements) -->
          <div v-else-if="currentPage === 'Progress'" class="grid grid-cols-1 gap-6 lg:grid-cols-5">
            <div class="rounded-xl border p-4 sm:p-6 lg:col-span-2" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 flex items-center text-xl font-bold text-[var(--fg-card-ink)]">🏋️ Fitness Progress</h2>
              <div class="space-y-6">
                <div>
                  <div class="mb-1 flex justify-between text-sm">
                    <span class="font-semibold text-[var(--fg-card-ink)]">Attendance Rate</span>
                    <span class="font-bold" :style="{ color: portal.lime }">{{ attendanceRatePercent }}%</span>
                  </div>
                  <p class="mb-2 text-xs" :style="{ color: portal.textDim }">This month</p>
                  <div class="h-2.5 w-full rounded-full" :style="{ backgroundColor: portal.dark2 }">
                    <div class="h-2.5 rounded-full" :style="{ width: `${attendanceRatePercent}%`, backgroundColor: portal.lime }" />
                  </div>
                </div>
                <div v-if="progressData.goal_progress_percent !== null">
                  <p class="mb-2 font-semibold text-[var(--fg-card-ink)]">Goal Progress</p>
                  <div class="mb-1 flex justify-between text-sm">
                    <span :style="{ color: portal.textDim }">Toward {{ progressData.goal_weight_kg }}kg goal</span>
                    <span class="text-[var(--fg-card-ink)]">{{ progressData.goal_progress_percent }}%</span>
                  </div>
                  <div class="h-2.5 w-full rounded-full" :style="{ backgroundColor: portal.dark2 }">
                    <div class="h-2.5 rounded-full" :style="{ width: `${progressData.goal_progress_percent}%`, backgroundColor: portal.cyan }" />
                  </div>
                </div>
                <div>
                  <p class="mb-2 font-semibold text-[var(--fg-card-ink)]">Body Metrics</p>
                  <div class="flex justify-around rounded-lg p-4 text-center" :style="{ backgroundColor: portal.dark2 }">
                    <div>
                      <p class="text-2xl font-bold text-[var(--fg-card-ink)]">{{ progressData.current_weight_kg !== null ? `${progressData.current_weight_kg}kg` : '–' }}</p>
                      <p class="text-xs" :style="{ color: portal.textDim }">Current Weight</p>
                    </div>
                    <div>
                      <p class="text-2xl font-bold text-[var(--fg-card-ink)]">{{ progressData.bmi ?? '–' }}</p>
                      <p class="text-xs" :style="{ color: portal.textDim }">BMI</p>
                    </div>
                  </div>
                </div>
                <div class="rounded-lg p-4" :style="{ backgroundColor: portal.dark2 }">
                  <p class="mb-2 font-semibold text-[var(--fg-card-ink)]">Log Today's Weight</p>
                  <div class="flex gap-2">
                    <input
                      v-model="weightInput"
                      type="number"
                      step="0.1"
                      min="20"
                      max="400"
                      placeholder="kg"
                      class="w-full rounded-lg border px-3 py-2 text-sm text-[var(--fg-card-ink)]"
                      :style="{ backgroundColor: portal.dark, borderColor: portal.border }"
                    />
                    <button
                      type="button"
                      class="shrink-0 rounded-lg px-4 py-2 text-sm font-semibold transition-opacity hover:opacity-90 disabled:opacity-50"
                      :style="{ backgroundColor: portal.purple, color: portal.primaryText }"
                      :disabled="progressActionLoading || !weightInput"
                      @click="submitWeightLog"
                    >
                      Log
                    </button>
                  </div>
                  <button
                    type="button"
                    class="mt-3 text-xs underline"
                    :style="{ color: portal.textDim }"
                    @click="showGoalEditor = !showGoalEditor"
                  >
                    {{ showGoalEditor ? 'Hide goal settings' : 'Set height / goal weight' }}
                  </button>
                  <div v-if="showGoalEditor" class="mt-3 space-y-2">
                    <div class="flex gap-2">
                      <input v-model="heightInput" type="number" step="0.1" placeholder="Height (cm)" class="w-full rounded-lg border px-3 py-2 text-sm text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark, borderColor: portal.border }" />
                      <input v-model="goalWeightInput" type="number" step="0.1" placeholder="Goal weight (kg)" class="w-full rounded-lg border px-3 py-2 text-sm text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark, borderColor: portal.border }" />
                    </div>
                    <button
                      type="button"
                      class="w-full rounded-lg py-2 text-sm font-semibold transition-opacity hover:opacity-90 disabled:opacity-50"
                      :style="{ backgroundColor: portal.dark3, color: 'var(--fg-card-ink)', border: `1px solid ${portal.border}` }"
                      :disabled="progressActionLoading"
                      @click="submitGoal"
                    >
                      Save
                    </button>
                  </div>
                </div>
              </div>
            </div>
            <div class="rounded-xl border p-4 sm:p-6 lg:col-span-3" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 flex items-center text-xl font-bold text-[var(--fg-card-ink)]">🏆 Achievements</h2>
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div
                  v-for="a in progressAchievements"
                  :key="a.title"
                  class="flex items-center space-x-4 rounded-lg p-4"
                  :style="a.achieved ? { backgroundColor: 'rgba(163, 230, 53, 0.14)', borderWidth: '1px', borderColor: portal.lime } : { backgroundColor: portal.dark2 }"
                >
                  <span class="text-3xl" :style="{ color: a.achieved ? portal.lime : portal.textDim }">{{ a.icon }}</span>
                  <div>
                    <p class="font-semibold" :style="{ color: a.achieved ? 'var(--fg-card-ink)' : portal.textDim }">{{ a.title }}</p>
                    <p class="text-xs" :style="{ color: portal.textDim }">{{ a.description }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Schedule (same as Blade: Class Schedule + Book Class) -->
          <div v-else-if="currentPage === 'Schedule'" class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <h2 class="flex items-center text-xl font-bold text-[var(--fg-card-ink)]">📅 Class Schedule</h2>
              <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="rounded-md px-3 py-1.5 text-sm font-semibold" :style="{ backgroundColor: portal.dark2, color: 'var(--fg-card-ink)' }" @click="shiftScheduleDate(-1)">‹ Prev</button>
                <span class="text-sm font-semibold text-[var(--fg-card-ink)]">{{ scheduleDateLabel }}</span>
                <button type="button" class="rounded-md px-3 py-1.5 text-sm font-semibold" :style="{ backgroundColor: portal.dark2, color: 'var(--fg-card-ink)' }" @click="shiftScheduleDate(1)">Next ›</button>
              </div>
            </div>
            <p v-if="scheduleError" class="mb-3 text-sm text-red-400">{{ scheduleError }}</p>
            <p v-if="scheduleLoading" class="text-sm" :style="{ color: portal.textDim }">Loading classes...</p>
            <p v-else-if="scheduleClasses.length === 0" class="text-sm" :style="{ color: portal.textDim }">
              No classes scheduled for this day.
            </p>
            <div v-else class="space-y-4">
              <div
                v-for="cls in scheduleClasses"
                :key="cls.id"
                class="flex flex-col rounded-lg p-4 sm:flex-row sm:items-center sm:justify-between"
                :style="{ backgroundColor: portal.dark2 }"
              >
                <div>
                  <p class="text-lg font-bold text-[var(--fg-card-ink)]">{{ cls.name }}</p>
                  <p class="text-sm" :style="{ color: portal.textDim }">Time: {{ cls.start_time }} - {{ cls.end_time }}</p>
                  <p class="text-sm" :style="{ color: portal.textDim }">Trainer: {{ cls.trainer_name }}</p>
                  <p class="text-xs" :style="{ color: portal.textDim }">{{ cls.booked_count }} / {{ cls.capacity }} booked</p>
                </div>
                <div class="mt-3 sm:mt-0">
                  <button
                    v-if="cls.booked_by_me"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border px-4 py-1.5 text-sm font-semibold transition-opacity hover:opacity-90"
                    :style="{ borderColor: portal.lime, color: portal.lime, backgroundColor: 'rgba(163, 230, 53, 0.16)' }"
                    :disabled="scheduleActionLoading"
                    @click="cancelBooking(cls)"
                  >
                    <span>✓</span> Booked — Cancel?
                  </button>
                  <span
                    v-else-if="cls.is_full"
                    class="inline-block rounded-md px-4 py-1.5 text-sm font-semibold cursor-not-allowed"
                    :style="{ backgroundColor: portal.dark2, color: portal.textDim }"
                  >
                    Full
                  </span>
                  <button
                    v-else
                    type="button"
                    class="rounded-md px-4 py-1.5 text-sm font-semibold transition-opacity hover:opacity-90 disabled:opacity-50"
                    :style="{ backgroundColor: portal.purple, color: portal.primaryText }"
                    :disabled="scheduleActionLoading"
                    @click="bookClass(cls)"
                  >
                    Book Class
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Profile (same as Blade: Personal Info + Settings + Edit + Add Payment modals) -->
          <div v-else-if="currentPage === 'Profile'" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">Personal Information</h2>
              <div class="flex flex-col items-center text-center">
                <div class="mb-3 flex h-24 w-24 items-center justify-center rounded-full text-4xl font-bold" :style="{ backgroundColor: portal.purple, color: portal.primaryText }">
                  {{ profileInitials() }}
                </div>
                <p class="text-2xl font-bold text-[var(--fg-card-ink)]">{{ profileData.name || 'Member' }}</p>
                <p class="text-sm" :style="{ color: portal.textDim }">{{ member?.membership_plan || 'Member' }}</p>
              </div>
              <div class="member-detail-list my-6 space-y-3 border-t pt-6 text-sm" :style="{ borderColor: portal.border }">
                <div class="flex justify-between">
                  <span :style="{ color: portal.textDim }">Email:</span>
                  <span class="text-[var(--fg-card-ink)]">{{ profileData.email || '–' }}</span>
                </div>
                <div class="flex justify-between">
                  <span :style="{ color: portal.textDim }">Phone:</span>
                  <span class="text-[var(--fg-card-ink)]">{{ profileData.phone || '–' }}</span>
                </div>
                <div class="flex justify-between">
                  <span :style="{ color: portal.textDim }">Member Since:</span>
                  <span class="text-[var(--fg-card-ink)]">{{ memberSinceLabel }}</span>
                </div>
                <div class="flex justify-between">
                  <span :style="{ color: portal.textDim }">Fitness Goal:</span>
                  <span class="text-[var(--fg-card-ink)]">{{ profileData.fitnessGoal || 'Not set' }}</span>
                </div>
                <div class="flex justify-between">
                  <span :style="{ color: portal.textDim }">Weight:</span>
                  <span class="text-[var(--fg-card-ink)]">{{ progressData.current_weight_kg !== null ? `${progressData.current_weight_kg}kg` : 'Not logged yet' }}</span>
                </div>
              </div>
              <button
                type="button"
                class="w-full rounded-lg py-3 font-semibold transition-opacity hover:opacity-90"
                :style="{ backgroundColor: portal.purple, color: portal.primaryText }"
                @click="openEditProfile"
              >
                Edit Profile
              </button>
              <p v-if="profileNotice" class="mt-3 text-center text-sm" role="status" :style="{ color: portal.lime }">{{ profileNotice }}</p>
            </div>
            <div class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">Settings</h2>
              <div class="space-y-4">
                <div class="rounded-lg p-4" :style="{ backgroundColor: portal.dark2 }">
                  <h3 class="mb-3 text-lg font-semibold text-[var(--fg-card-ink)]">Account Settings</h3>
                  <ul class="space-y-2 text-sm" :style="{ color: portal.textDim }">
                    <li><button type="button" class="cursor-pointer transition-colors hover:text-[var(--fg-card-ink)]" @click="openChangePassword">Change Password</button></li>
                    <li><button type="button" class="cursor-pointer transition-colors hover:text-[var(--fg-card-ink)]" @click="openEditProfile">Update Email</button></li>
                  </ul>
                </div>
                <div class="rounded-lg p-4" :style="{ backgroundColor: portal.dark2 }">
                  <h3 class="mb-3 text-lg font-semibold text-[var(--fg-card-ink)]">Notifications</h3>
                  <p class="text-sm [overflow-wrap:anywhere]" :style="{ color: portal.textDim }">
                    Payment, approval, and class booking updates appear in your Notifications and are sent by SMS to
                    <span class="text-[var(--fg-card-ink)]">{{ profileData.phone || 'your phone number on file' }}</span>.
                    Keep your phone number up to date in Edit Profile.
                  </p>
                </div>
                <div class="rounded-lg p-4" :style="{ backgroundColor: portal.dark2 }">
                  <h3 class="mb-3 text-lg font-semibold text-[var(--fg-card-ink)]">Payment Methods</h3>
                  <ul class="mb-3 list-inside list-disc space-y-1 text-sm" :style="{ color: portal.textDim }">
                    <li><span class="text-[var(--fg-card-ink)]">GCash</span> — pay online and upload your receipt on the Payments page</li>
                    <li><span class="text-[var(--fg-card-ink)]">Cash</span> — pay at the front desk; staff will record it for you</li>
                  </ul>
                  <button type="button" class="cursor-pointer text-sm hover:underline" :style="{ color: portal.purpleLight }" @click="currentPage = 'Payments'">
                    Go to Payments →
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Support (same as Blade: Contact Support form + success + Quick Contact) -->
          <div v-else-if="currentPage === 'Support'" class="mx-auto max-w-2xl">
            <div v-if="supportSubmitted" class="rounded-xl border p-4 sm:p-6 text-center" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <div class="mb-4 inline-block rounded-full p-4" :style="{ backgroundColor: 'rgba(163, 230, 53, 0.16)', color: portal.lime }">
                <span class="text-2xl">✓</span>
              </div>
              <h2 class="mb-2 text-2xl font-bold text-[var(--fg-card-ink)]">Ticket Submitted!</h2>
              <p class="mb-6 text-sm" :style="{ color: portal.textDim }">The gym staff has been notified and will get back to you. Thank you for your patience.</p>
              <button
                type="button"
                class="rounded-lg py-2 px-6 font-semibold text-[var(--fg-card-ink)] transition-opacity hover:opacity-90"
                :style="{ backgroundColor: portal.purple, color: portal.primaryText }"
                @click="supportSubmitted = false"
              >
                Submit Another Ticket
              </button>
            </div>
            <div v-else class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <h2 class="mb-4 flex items-center text-xl font-bold text-[var(--fg-card-ink)]">💬 Contact Support</h2>
              <form @submit="handleSupportSubmit" class="space-y-4">
                <div>
                  <label for="support-subject" class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">Subject</label>
                  <select
                    id="support-subject"
                    v-model="supportSubject"
                    class="w-full rounded-lg border p-2.5 text-[var(--fg-card-ink)]"
                    :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
                  >
                    <option value="Equipment Issue">Equipment Issue</option>
                    <option value="Billing Inquiry">Billing Inquiry</option>
                    <option value="Membership Question">Membership Question</option>
                    <option value="General Feedback">General Feedback</option>
                  </select>
                </div>
                <div>
                  <label for="support-message" class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">Message</label>
                  <textarea
                    id="support-message"
                    v-model="supportMessage"
                    rows="5"
                    required
                    placeholder="Describe your issue..."
                    maxlength="2000"
                    class="w-full rounded-lg border p-2.5 text-[var(--fg-card-ink)] placeholder-gray-500"
                    :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
                  />
                </div>
                <p v-if="supportError" class="rounded-lg border px-3 py-2 text-sm text-red-300" role="alert" :style="{ borderColor: portal.pink, backgroundColor: 'rgba(255, 107, 107, 0.12)' }">{{ supportError }}</p>
                <button
                  type="submit"
                  class="w-full rounded-lg py-3 font-semibold transition-opacity hover:opacity-90 disabled:opacity-50"
                  :style="{ backgroundColor: portal.purple, color: portal.primaryText }"
                  :disabled="supportSending"
                >
                  {{ supportSending ? 'Sending...' : 'Submit Ticket' }}
                </button>
              </form>
              <div class="mt-6 border-t pt-6" :style="{ borderColor: portal.border }">
                <h3 class="mb-3 text-lg font-semibold text-[var(--fg-card-ink)]">Quick Contact — {{ gymDisplayName }}</h3>
                <div class="space-y-2 text-sm [overflow-wrap:anywhere]" :style="{ color: portal.textDim }">
                  <p v-if="gymContact.phone">📞 Phone: {{ gymContact.phone }}</p>
                  <p v-if="gymContact.email">✉️ Email: {{ gymContact.email }}</p>
                  <p v-if="gymContact.address">📍 Address: {{ gymContact.address }}</p>
                  <div v-if="gymContact.hours.length">
                    <p>🕒 Hours:</p>
                    <ul class="mt-1 ml-6 list-disc space-y-0.5">
                      <li v-for="line in gymContact.hours" :key="line">{{ line }}</li>
                    </ul>
                  </div>
                  <p v-if="!gymContact.phone && !gymContact.email && !gymContact.address && !gymContact.hours.length">
                    Contact details haven't been set up yet. Please ask at the front desk.
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Other pages: placeholder (v-else must be adjacent to v-else-if) -->
          <div v-else class="rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
            <h2 class="mb-4 text-xl font-bold text-[var(--fg-card-ink)]">{{ currentPage }}</h2>
            <p :style="{ color: portal.textDim }">Content for {{ currentPage }} — same as Blade member portal.</p>
          </div>
        </main>

        <!-- Edit Profile modal (Teleport after main so v-if chain is unbroken) -->
        <Teleport to="body">
          <div
            v-if="showEditProfile"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @click.self="showEditProfile = false"
          >
            <div class="mx-4 max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }" @click.stop>
              <div class="mb-4 flex items-center justify-between">
                <h2 class="text-xl font-bold text-[var(--fg-card-ink)]">Edit Profile</h2>
                <button type="button" class="text-2xl hover:text-[var(--fg-card-ink)]" :style="{ color: portal.textDim }" @click="showEditProfile = false">×</button>
              </div>
              <form @submit="handleEditProfile" class="space-y-4">
                <div>
                  <label class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">Full Name</label>
                  <input name="name" type="text" required maxlength="255" :value="profileData.name" class="w-full rounded-lg border p-3 text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">Email</label>
                  <input name="email" type="email" required :value="profileData.email" class="w-full rounded-lg border p-3 text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                  <p class="mt-1 text-xs" :style="{ color: portal.textDim }">This is also your sign-in email.</p>
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">Phone</label>
                  <input name="phone" type="tel" :value="profileData.phone" class="w-full rounded-lg border p-3 text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">Fitness Goal</label>
                  <select name="fitnessGoal" class="w-full rounded-lg border p-3 text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" :value="profileData.fitnessGoal">
                    <option value="">Not set</option>
                    <option v-for="goal in FITNESS_GOALS" :key="goal" :value="goal">{{ goal }}</option>
                  </select>
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">Weight (kg)</label>
                  <input name="weight" type="number" step="0.1" min="20" max="400" placeholder="e.g. 65" :value="progressData.current_weight_kg ?? ''" class="w-full rounded-lg border p-3 text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                  <p class="mt-1 text-xs" :style="{ color: portal.textDim }">A new value is logged as today's weight in Progress.</p>
                </div>
                <p v-if="profileError" class="rounded-lg border px-3 py-2 text-sm text-red-300" role="alert" :style="{ borderColor: portal.pink, backgroundColor: 'rgba(255, 107, 107, 0.12)' }">{{ profileError }}</p>
                <div class="flex space-x-3 pt-4">
                  <button type="submit" class="flex-1 rounded-lg py-3 font-semibold transition-opacity hover:opacity-90 disabled:opacity-50" :style="{ backgroundColor: portal.purple, color: portal.primaryText }" :disabled="profileSaving">{{ profileSaving ? 'Saving...' : 'Save Changes' }}</button>
                  <button type="button" class="flex-1 rounded-lg py-3 font-semibold text-[var(--fg-card-ink)] transition-opacity hover:opacity-90" :style="{ backgroundColor: portal.dark2 }" @click="showEditProfile = false">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        </Teleport>

        <!-- Change Password modal -->
        <Teleport to="body">
          <div
            v-if="showChangePassword"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @click.self="showChangePassword = false"
          >
            <div class="mx-4 max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-xl border p-4 sm:p-6" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }" @click.stop>
              <div class="mb-4 flex items-center justify-between">
                <h2 class="text-xl font-bold text-[var(--fg-card-ink)]">Change Password</h2>
                <button type="button" class="text-2xl hover:text-[var(--fg-card-ink)]" aria-label="Close" :style="{ color: portal.textDim }" @click="showChangePassword = false">×</button>
              </div>
              <form class="space-y-4" @submit.prevent="submitChangePassword">
                <div>
                  <label for="member-current-password" class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">Current Password</label>
                  <div class="fg-password-field">
                    <input id="member-current-password" v-model="passwordForm.current_password" :type="pw.type('current')" required autocomplete="current-password" class="w-full rounded-lg border p-3 text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                    <PasswordToggle :shown="pw.isShown('current')" @toggle="pw.toggle('current')" />
                  </div>
                </div>
                <div>
                  <label for="member-new-password" class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">New Password</label>
                  <div class="fg-password-field">
                    <input id="member-new-password" v-model="passwordForm.new_password" :type="pw.type('new')" required minlength="8" autocomplete="new-password" class="w-full rounded-lg border p-3 text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                    <PasswordToggle :shown="pw.isShown('new')" @toggle="pw.toggle('new')" />
                  </div>
                  <p class="mt-1 text-xs" :style="{ color: portal.textDim }">At least 8 characters.</p>
                </div>
                <div>
                  <label for="member-confirm-password" class="mb-1 block text-sm font-medium" :style="{ color: portal.textDim }">Confirm New Password</label>
                  <div class="fg-password-field">
                    <input id="member-confirm-password" v-model="passwordForm.new_password_confirmation" :type="pw.type('confirm')" required minlength="8" autocomplete="new-password" class="w-full rounded-lg border p-3 text-[var(--fg-card-ink)]" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                    <PasswordToggle :shown="pw.isShown('confirm')" @toggle="pw.toggle('confirm')" />
                  </div>
                </div>
                <p v-if="passwordError" class="rounded-lg border px-3 py-2 text-sm text-red-300" role="alert" :style="{ borderColor: portal.pink, backgroundColor: 'rgba(255, 107, 107, 0.12)' }">{{ passwordError }}</p>
                <div class="flex space-x-3 pt-2">
                  <button type="submit" class="flex-1 rounded-lg py-3 font-semibold transition-opacity hover:opacity-90 disabled:opacity-50" :style="{ backgroundColor: portal.purple, color: portal.primaryText }" :disabled="passwordSaving">{{ passwordSaving ? 'Saving...' : 'Change Password' }}</button>
                  <button type="button" class="flex-1 rounded-lg py-3 font-semibold text-[var(--fg-card-ink)] transition-opacity hover:opacity-90" :style="{ backgroundColor: portal.dark2 }" @click="showChangePassword = false">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        </Teleport>
      </div>
    </div>
  </div>
  </div>
</template>

<style scoped>
/* backdrop-filter would trap the fixed-position mobile drawer inside the shell */
@media (min-width: 1024px) {
  .member-shell {
    backdrop-filter: blur(12px);
  }
}

.member-detail-list > div {
  gap: 1rem;
}

.member-detail-list > div > span:last-child {
  min-width: 0;
  text-align: right;
  overflow-wrap: anywhere;
}

.member-sidebar {
  border-right: 1px solid var(--fg-card-border);
  background:
    linear-gradient(180deg, rgba(217, 180, 90, 0.07) 0%, rgba(217, 180, 90, 0) 45%),
    var(--fg-card-sidebar);
}

.member-nav-item {
  border: 1px solid transparent;
  background: transparent;
}

.member-nav-item:hover {
  background: rgba(217, 180, 90, 0.1);
  border-color: rgba(106, 90, 205, 0.3);
}

.member-nav-item.active {
  box-shadow: 0 14px 28px rgba(138, 43, 226, 0.28);
}

.member-header {
  background: transparent;
}

/* light cards get a soft shadow so they stand off the marble */
.member-portal main .rounded-xl.border {
  box-shadow: 0 10px 28px rgba(92, 70, 30, 0.08);
}

.member-portal input,
.member-portal select,
.member-portal textarea {
  outline: none;
}

.member-portal input:focus,
.member-portal select:focus,
.member-portal textarea:focus {
  border-color: rgba(138, 43, 226, 0.72) !important;
  box-shadow: 0 0 0 1px rgba(138, 43, 226, 0.4);
}

/* 16px inputs stop iOS Safari from zooming the page on focus */
@media (max-width: 767.98px) {
  .member-portal input:not([type='checkbox']):not([type='file']),
  .member-portal select,
  .member-portal textarea {
    font-size: 1rem;
  }
}
</style>
