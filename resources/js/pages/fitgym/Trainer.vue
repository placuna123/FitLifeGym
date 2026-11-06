<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, ClipboardList, Clock, Menu, QrCode, UserCheck, UserPlus, Users, X } from 'lucide-vue-next';
import { ref, shallowRef, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import {
  trainerAuth,
  trainerAttendance,
  trainerMembers,
  members,
  membershipPlans,
  payments,
  reports,
  trainerProfile,
  gymInfo,
  gymClasses,
  trainer,
} from '@/api/fitgym';
import { Chart, type ChartConfiguration } from 'chart.js/auto';
import FgThemeToggle from '@/components/FgThemeToggle.vue';
import PasswordToggle from '@/components/PasswordToggle.vue';
import ProfilePhotoPicker from '@/components/ProfilePhotoPicker.vue';
import { useMobileNav } from '@/composables/useMobileNav';
import { usePasswordVisibility } from '@/composables/usePasswordVisibility';
import { useQrCheckinScanner } from '@/composables/useQrCheckinScanner';
import { useSessionGuard } from '@/composables/useSessionGuard';
import { mergeMemberStreetAddress, memberStreetAddress } from '@/lib/memberAddress';
import { goToEntry } from '@/lib/portal';
import { isUserEditing } from '@/lib/editing';
import { chartPalette } from '@/lib/chartPalette';
import { useFgTheme } from '@/composables/useFgTheme';

const { mobileNavOpen, closeMobileNav } = useMobileNav();
const pw = usePasswordVisibility();
const isLoggedIn = ref(false);
const loading = ref(true);
const loginLoading = ref(false);
const gymInfoData = ref<Record<string, unknown> | null>(null);
const gymDisplayName = computed(() => String(gymInfoData.value?.name || 'FitGym'));

async function loadGymInfo() {
  try {
    const { data } = await gymInfo.get();
    const res = data as { gym?: Record<string, unknown> };
    gymInfoData.value = res?.gym || null;
  } catch {
    gymInfoData.value = null;
  }
}
const loginError = ref('');
const user = ref<{ name: string; email: string; photo_url?: string | null } | null>(null);
/** The signed-in staff member's profile photo; the Profile picker writes it back so the sidebar updates too. */
const userPhotoUrl = computed<string | null>({
  get: () => user.value?.photo_url ?? null,
  set: (url) => {
    if (user.value) user.value = { ...user.value, photo_url: url };
  },
});

const loginForm = ref({
  email: '',
  password: '',
  remember: false,
});

const currentView = ref('dashboard');
let trainerPortalRefreshTimer: number | null = null;
const recentCheckins = ref<Record<string, unknown>[]>([]);
const currentlyInGym = ref<Record<string, unknown>[]>([]);
const attendanceLogs = ref<Record<string, unknown>[]>([]);
const membersList = ref<Record<string, unknown>[]>([]);
const plansList = ref<Record<string, unknown>[]>([]);
const expiringMembers = ref<Record<string, unknown>[]>([]);
const reportAttendance = ref<Record<string, unknown> | null>(null);
const reportRevenue = ref<Record<string, unknown> | null>(null);
const reportMembership = ref<Record<string, unknown> | null>(null);
const paymentsList = ref<Record<string, unknown>[]>([]);
const paymentSummary = ref<Record<string, unknown> | null>(null);
const processingPaymentId = ref<number | null>(null);
const profileTrainer = ref<Record<string, unknown> | null>(null);
const attendanceSearch = ref('');
const memberSearch = ref('');
const memberStatusFilter = ref('');
const memberPlanFilter = ref('');
const dataLoading = ref(false);
const showViewMemberModal = ref(false);
const selectedMemberForView = ref<Record<string, unknown> | null>(null);
const selectedMemberForEdit = ref<Record<string, unknown> | null>(null);
const reportsTab = ref<'overview' | 'trends' | 'breakdown'>('overview');
const reportCharts = shallowRef<{ weekly?: InstanceType<typeof Chart>; growth?: InstanceType<typeof Chart>; planDist?: InstanceType<typeof Chart>; revenue?: InstanceType<typeof Chart> }>({});
const dashboardChartCanvas = ref<HTMLCanvasElement | null>(null);
let dashboardChart: InstanceType<typeof Chart> | null = null;
const profileForm = ref({ full_name: '', email: '', phone: '' });
const profileNotice = ref('');
const showPasswordModal = ref(false);
const passwordForm = ref({ current_password: '', new_password: '', new_password_confirmation: '' });

const showCheckinModal = ref(false);
const showAddMemberModal = ref(false);
const showAddPaymentModal = ref(false);
const showViewPaymentModal = ref(false);
const selectedPaymentForView = ref<Record<string, unknown> | null>(null);
const selectedPaymentForEdit = ref<Record<string, unknown> | null>(null);
const showScheduleModal = ref(false);
const selectedManagedClassForEdit = ref<number | null>(null);
const checkinForm = ref({ member_id: '' });
const paymentForm = ref({
  member_id: '',
  amount: '',
  payment_date: new Date().toISOString().slice(0, 10),
  payment_method: 'Cash',
});
const memberForm = ref({
  full_name: '',
  email: '',
  phone: '',
  address: '',
  membership_plan_id: '',
});
const scheduleClassForm = ref({
  name: '',
  trainer_id: '',
  days: [] as string[],
  start_time: '09:00',
  end_time: '10:00',
  capacity: '10',
});
const formLoading = ref(false);
const formError = ref('');

const showAddPlanModal = ref(false);
const planForm = ref({ name: '', type: 'Monthly', price: '', period: 'per month', description: '', is_popular: false, features: [] as string[] });
const MAX_PLAN_FEATURES = 20;

function addPlanFeature() {
  if (planForm.value.features.length < MAX_PLAN_FEATURES) planForm.value.features.push('');
}

function removePlanFeature(index: number) {
  planForm.value.features.splice(index, 1);
}
const selectedPlanForEdit = ref<Record<string, unknown> | null>(null);
const navItems = [
  { id: 'dashboard', label: 'Dashboard', description: 'Overview and quick actions' },
  { id: 'attendance', label: 'Attendance', description: 'Check-ins, logs, and gym activity' },
  { id: 'members', label: 'Members', description: 'Accounts, status, and updates' },
  { id: 'memberships', label: 'Membership Plans', description: 'Plans, pricing, and summaries' },
  { id: 'schedule', label: 'Schedule', description: 'Classes, staff, and availability' },
  { id: 'profile', label: 'Profile', description: 'Account and staff preferences' },
] as const;
const currentViewInfo = computed(() => navItems.find((item) => item.id === currentView.value) ?? navItems[0]);
const trainerDisplayName = computed(() => {
  const profileName = String((profileTrainer.value as Record<string, unknown> | null)?.full_name ?? '').trim();
  return profileName || user.value?.name || 'Staff User';
});
const trainerInitials = computed(() =>
  trainerDisplayName.value
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || 'ST'
);
const trainerRoleLabel = computed(() => {
  const profile = profileTrainer.value as Record<string, unknown> | null;
  return String(profile?.position ?? profile?.role ?? 'Staff');
});
const trainerCheckinsToday = computed(() => Number(reportAttendance.value?.today_checkins ?? recentCheckins.value.length ?? 0));

// Schedule: the branch's real weekly classes — the same ones Admin manages and members book.
type ScheduleClass = {
  id: number;
  name: string;
  trainer_id: number | null;
  trainer_name: string | null;
  day_of_week: string;
  start_time: string;
  end_time: string;
  capacity: number;
  class_date: string;
  booked_count: number;
};
const scheduleViewTab = ref<'Calendar' | 'TrainerAvailability' | 'ClassManagement'>('Calendar');
const scheduleDate = ref(new Date().toISOString().slice(0, 10));
const scheduleClasses = ref<ScheduleClass[]>([]);
const scheduleStaff = ref<Array<{ id: number; name: string }>>([]);
const scheduleLoading = ref(false);
const scheduleError = ref('');

async function checkAuth() {
  // Stay on the loading screen while navigating away, so the old portal-local
  // login form never flashes on the way to the unified sign-in page.
  let leaving = false;

  try {
    const { data } = await trainerAuth.user();
    if (data.success && data.user) {
      // Extra safety: only allow the exact Staff (trainer) role to enter the dashboard.
      const role = String((data.user as Record<string, unknown>).role ?? '');
      if (role !== 'Staff') {
        try {
          await trainerAuth.logout();
        } catch {
          // ignore
        }
        user.value = null;
        isLoggedIn.value = false;
        leaving = true;
        goToEntry('trainer', { reason: 'session' });
        return;
      }

      user.value = data.user;
      isLoggedIn.value = true;
      await Promise.all([loadDashboardData(), loadGymInfo()]);
      startTrainerPortalAutoRefresh();
      nextTick(() => buildDashboardChart());
    } else {
      isLoggedIn.value = false;
      user.value = null;
      leaving = true;
      goToEntry('trainer', { reason: 'session' });
    }
  } catch {
    // Clear any possibly-stale session so the unified entry page starts clean.
    try {
      await trainerAuth.logout();
    } catch {
      // ignore
    }
      isLoggedIn.value = false;
      user.value = null;
      stopTrainerPortalAutoRefresh();
      leaving = true;
      goToEntry('trainer', { reason: 'session' });
    } finally {
    if (!leaving) loading.value = false;
  }
}

async function loadDashboardData() {
  if (!isLoggedIn.value) return;
  dataLoading.value = true;
  try {
    const [recentRes, inGymRes, expiringRes, attReportRes, paymentsRes] = await Promise.all([
      trainerAttendance.recent().catch(() => ({ data: { checkins: [] } })),
      trainerAttendance.currentlyInGym().catch(() => ({ data: { members: [] } })),
      trainerMembers.expiring().catch(() => ({ data: { members: [] } })),
      reports.attendance().catch(() => ({ data: {} })),
      payments.list().catch(() => ({ data: { payments: [] } })),
    ]);
    recentCheckins.value = Array.isArray((recentRes.data as { checkins?: unknown[] })?.checkins)
      ? (recentRes.data as { checkins: Record<string, unknown>[] }).checkins
      : [];
    paymentsList.value = Array.isArray((paymentsRes.data as { payments?: unknown[] })?.payments)
      ? (paymentsRes.data as { payments: Record<string, unknown>[] }).payments
      : [];
    currentlyInGym.value = Array.isArray((inGymRes.data as { members?: unknown[] })?.members)
      ? (inGymRes.data as { members: Record<string, unknown>[] }).members
      : [];
    expiringMembers.value = Array.isArray((expiringRes.data as { members?: unknown[] })?.members)
      ? (expiringRes.data as { members: Record<string, unknown>[] }).members
      : [];
    reportAttendance.value = (attReportRes.data as Record<string, unknown>) || null;
  } catch {
    recentCheckins.value = [];
    currentlyInGym.value = [];
    expiringMembers.value = [];
  } finally {
    dataLoading.value = false;
    if (currentView.value === 'dashboard') {
      buildDashboardChart();
    }
  }
}

function destroyDashboardChart() {
  dashboardChart?.destroy();
  dashboardChart = null;
  todayChart?.destroy();
  todayChart = null;
}

// Check-ins per day for the last 7 days (today last), from the attendance report.
const staffWeek = computed(() => {
  const daily = (reportAttendance.value?.daily_stats as Array<{ date?: string; count?: number }>) || [];
  return daily.slice(-7).map((d, i, all) => ({
    label: i === all.length - 1 ? 'Today' : new Date(String(d.date)).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' }),
    count: Number(d.count ?? 0),
  }));
});
const staffWeekTotal = computed(() => Number(reportAttendance.value?.week_checkins ?? staffWeek.value.reduce((n, d) => n + d.count, 0)));

function initialsOf(name: unknown): string {
  return String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '-';
}

function to12Hour(time: unknown): string {
  const [h, m] = String(time ?? '').split(':').map(Number);
  if (Number.isNaN(h)) return String(time ?? '');
  return `${h % 12 || 12}:${String(m || 0).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
}

// Expiring memberships with a readable date and how soon; the next 3 days are flagged.
const expiringRows = computed(() => {
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  return expiringMembers.value.map((m) => {
    const expiry = new Date(`${String(m.expiry_date)}T00:00:00`);
    const days = Math.round((expiry.getTime() - today.getTime()) / 86_400_000);
    return {
      id: String(m.id),
      name: String(m.full_name || ''),
      initials: initialsOf(m.full_name),
      date: expiry.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }),
      when: days === 0 ? 'ends today' : days === 1 ? 'ends tomorrow' : `in ${days} days`,
      urgent: days <= 3,
    };
  });
});

// Dashboard header line, e.g. "Thursday, November 5"
const dashboardToday = new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });

// Today's visits split into members still inside and those who already left.
const todaySplit = computed(() => {
  const inside = currentlyInGym.value.length;
  const done = Math.max(0, trainerCheckinsToday.value - inside);
  return { inside, done, total: inside + done };
});
const todayCanvas = ref<HTMLCanvasElement | null>(null);
let todayChart: InstanceType<typeof Chart> | null = null;

function buildDashboardChart() {
  if (currentView.value !== 'dashboard') return;
  destroyDashboardChart();
  const c = chartPalette();

  if (dashboardChartCanvas.value) {
    const days = staffWeek.value;
    dashboardChart = new Chart(dashboardChartCanvas.value, {
      type: 'line',
      data: {
        labels: days.map((d) => d.label),
        datasets: [
          {
            label: 'Check-ins',
            data: days.map((d) => d.count),
            borderColor: c.accent,
            // gold fill that fades towards the bottom of the chart
            backgroundColor: (ctx: { chart: Chart }) => {
              const { ctx: g, chartArea } = ctx.chart;
              if (!chartArea) return `rgba(${c.accentRgb}, 0.18)`;
              const gradient = g.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
              gradient.addColorStop(0, `rgba(${c.accentRgb}, 0.35)`);
              gradient.addColorStop(1, `rgba(${c.accentRgb}, 0)`);
              return gradient;
            },
            fill: true,
            tension: 0.35,
            borderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBackgroundColor: c.accent,
            pointBorderColor: c.surface,
            pointBorderWidth: 2,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (ctx) => `${ctx.parsed.y} check-in${ctx.parsed.y === 1 ? '' : 's'}` } },
        },
        scales: {
          y: { beginAtZero: true, grid: { color: c.grid }, border: { display: false }, ticks: { color: c.text, precision: 0 } },
          x: { grid: { display: false }, ticks: { color: c.text } },
        },
      } as ChartConfiguration<'line'>['options'],
    });
  }

  const split = todaySplit.value;
  if (todayCanvas.value && split.total > 0) {
    todayChart = new Chart(todayCanvas.value, {
      type: 'doughnut',
      data: {
        labels: ['In the gym now', 'Checked out'],
        datasets: [
          {
            data: [split.inside, split.done],
            backgroundColor: [c.accent, `rgba(${c.neutralRgb}, 0.45)`],
            borderColor: c.surface,
            borderWidth: 3,
            hoverOffset: 4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${ctx.parsed}` } },
        },
      } as ChartConfiguration<'doughnut'>['options'],
    });
  }
}

// The canvases only exist once the dashboard is on screen; draw when they appear and
// whenever the numbers change.
watch([dashboardChartCanvas, todayCanvas, staffWeek, todaySplit], () => {
  if (dashboardChartCanvas.value) buildDashboardChart();
});

// Light / dark switch: redraw the charts so they pick up the new palette
const { theme: fgTheme } = useFgTheme();
watch(fgTheme, () => nextTick(() => {
  if (dashboardChartCanvas.value) buildDashboardChart();
  buildReportsCharts();
}));

async function loadAttendanceData() {
  dataLoading.value = true;
  try {
    const [logsRes, inGymRes] = await Promise.all([
      trainerAttendance.logs({ search: attendanceSearch.value || undefined }).catch(() => ({ data: { logs: [] } })),
      trainerAttendance.currentlyInGym().catch(() => ({ data: { members: [] } })),
    ]);
    attendanceLogs.value = Array.isArray((logsRes.data as { logs?: unknown[] })?.logs)
      ? (logsRes.data as { logs: Record<string, unknown>[] }).logs
      : [];
    currentlyInGym.value = Array.isArray((inGymRes.data as { members?: unknown[] })?.members)
      ? (inGymRes.data as { members: Record<string, unknown>[] }).members
      : [];
  } catch {
    attendanceLogs.value = [];
  } finally {
    dataLoading.value = false;
  }
}

async function loadMembersData() {
  dataLoading.value = true;
  try {
    const [memRes, planRes] = await Promise.all([
      members.list().catch(() => ({ data: [] })),
      membershipPlans.list().catch(() => ({ data: [] })),
    ]);
    membersList.value = Array.isArray(memRes.data) ? memRes.data : [];
    plansList.value = Array.isArray(planRes.data) ? planRes.data : [];
  } catch {
    membersList.value = [];
    plansList.value = [];
  } finally {
    dataLoading.value = false;
  }
}

async function refreshTrainerViewData(view = currentView.value) {
  if (!isLoggedIn.value) return;

  if (view === 'dashboard') {
    await loadDashboardData();
    return;
  }

  if (view === 'attendance') {
    await Promise.all([loadAttendanceData(), loadDashboardData()]);
    return;
  }

  if (view === 'members') {
    await loadMembersData();
    return;
  }

  if (view === 'memberships') {
    await loadMembershipsData();
  }

  // Profile is not refreshed in the background: it is a form, and reloading it
  // would replace what the staff member typed. It loads fresh each time it is opened.
}

function stopTrainerPortalAutoRefresh() {
  if (trainerPortalRefreshTimer != null) {
    window.clearInterval(trainerPortalRefreshTimer);
    trainerPortalRefreshTimer = null;
  }
}

function startTrainerPortalAutoRefresh() {
  stopTrainerPortalAutoRefresh();
  if (!isLoggedIn.value) return;

  trainerPortalRefreshTimer = window.setInterval(() => {
    // never refresh under someone who is typing or has a form open
    if (isUserEditing()) return;
    void refreshTrainerViewData();
  }, 15000);
}

const activeMembersForCheckin = ref<Record<string, unknown>[]>([]);
const {
  videoRef: trainerQrVideoRef,
  scannerActive: trainerQrScannerActive,
  scannerBusy: trainerQrScannerBusy,
  scannerError: trainerQrScannerError,
  scannerStatus: trainerQrScannerStatus,
  manualQrPayload: trainerQrManualQrPayload,
  startScanner: startTrainerQrScanner,
  stopScanner: stopTrainerQrScanner,
  submitManualPayload: submitTrainerQrPayload,
} = useQrCheckinScanner({
  onDetected: async ({ rawValue }) => {
    await processScannedAttendanceQr(rawValue);
  },
});

function openCheckinModal() {
  formError.value = '';
  checkinForm.value = { member_id: '' };
  showCheckinModal.value = true;
  void stopTrainerQrScanner();
  loadMembersForCheckin();
}

async function loadMembersForCheckin() {
  try {
    const { data } = await trainerMembers.active();
    activeMembersForCheckin.value = Array.isArray((data as { members?: unknown[] })?.members)
      ? (data as { members: Record<string, unknown>[] }).members
      : [];
    if (activeMembersForCheckin.value.length && !checkinForm.value.member_id) {
      const first = activeMembersForCheckin.value[0] as Record<string, unknown>;
      if (first.id != null) checkinForm.value.member_id = String(first.id);
    }
  } catch {
    activeMembersForCheckin.value = [];
  }
}

function openAddMemberModal() {
  selectedMemberForEdit.value = null;
  formError.value = '';
  memberForm.value = {
    full_name: '',
    email: '',
    phone: '',
    address: '',
    membership_plan_id: plansList.value[0] ? String((plansList.value[0] as Record<string, unknown>).id) : '',
  };
  showAddMemberModal.value = true;
  if (plansList.value.length === 0) {
    membershipPlans.list().then((r) => {
      plansList.value = Array.isArray(r.data) ? r.data : [];
      if (plansList.value.length && !memberForm.value.membership_plan_id) {
        memberForm.value.membership_plan_id = String((plansList.value[0] as Record<string, unknown>).id);
      }
    }).catch(() => {});
  }
}

async function submitCheckin(scannedMemberId?: number) {
  const memberId = scannedMemberId ?? Number(checkinForm.value.member_id);
  if (!memberId) {
    formError.value = 'Select a member.';
    return;
  }
  formLoading.value = true;
  formError.value = '';
  try {
    await trainerAttendance.checkIn({ member_id: memberId });
    await stopTrainerQrScanner();
    showCheckinModal.value = false;
    await loadDashboardData();
    if (currentView.value === 'attendance') await loadAttendanceData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Check-in failed.';
  } finally {
    formLoading.value = false;
  }
}

const checkingOutId = ref<number | null>(null);
const attendanceError = ref('');

/** Checks a member out by hand from the "Inside the Gym Now" list. */
async function checkOutVisit(visit: Record<string, unknown>) {
  const id = Number(visit.id);
  if (!id || checkingOutId.value) return;
  if (!confirm(`Check out ${visit.member_name}?`)) return;
  checkingOutId.value = id;
  attendanceError.value = '';
  try {
    await trainerAttendance.checkOut(id);
    await Promise.all([loadAttendanceData(), loadDashboardData()]);
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    attendanceError.value = ax.response?.data?.message || 'Check-out failed. Try again.';
    await loadAttendanceData();
  } finally {
    checkingOutId.value = null;
  }
}

async function processScannedAttendanceQr(rawValue: string) {
  formLoading.value = true;
  formError.value = '';

  try {
    await trainerAttendance.processQr(rawValue);
    await stopTrainerQrScanner();
    showCheckinModal.value = false;
    await loadDashboardData();
    if (currentView.value === 'attendance') await loadAttendanceData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to process attendance QR.';
    throw err;
  } finally {
    formLoading.value = false;
  }
}

async function submitAddMember() {
  if (!memberForm.value.full_name || !memberForm.value.email || !memberForm.value.membership_plan_id) {
    formError.value = 'Fill required fields.';
    return;
  }
  formLoading.value = true;
  formError.value = '';
  const planId = memberForm.value.membership_plan_id;
  const plan = plansList.value.find((p) => String((p as Record<string, unknown>).id) === String(planId)) as Record<string, unknown> | undefined;
  const planName = plan ? String(plan.name) : '';
  const payloadAdd = {
    full_name: memberForm.value.full_name,
    email: memberForm.value.email,
    phone: memberForm.value.phone || '',
    address: memberForm.value.address || '',
    membership_plan_id: Number(planId),
  };
  const payloadUpdate = {
    full_name: memberForm.value.full_name,
    email: memberForm.value.email,
    phone: memberForm.value.phone || '',
    // keeps a self-registered member's emergency contact and birthday when the address is edited
    address: mergeMemberStreetAddress(selectedMemberForEdit.value?.address, memberForm.value.address || ''),
    membership_plan: planName,
  };
  try {
    if (selectedMemberForEdit.value && (selectedMemberForEdit.value as Record<string, unknown>).id != null) {
      if (!planName) {
        formError.value = 'Select a membership plan.';
        formLoading.value = false;
        return;
      }
      await members.update(Number((selectedMemberForEdit.value as Record<string, unknown>).id), payloadUpdate);
      selectedMemberForEdit.value = null;
    } else {
      await trainerMembers.store(payloadAdd);
    }
    showAddMemberModal.value = false;
    await loadMembersData();
    await loadDashboardData();
  } catch (err: unknown) {
    const ax = err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } };
    // If CSRF mismatch happened, client.ts already reloads; avoid showing "CSF TOKEN MATCH" in UI.
    if (ax.response?.status === 419) {
      formError.value = '';
      return;
    }
    formError.value = ax.response?.data?.message || (ax.response?.data?.errors ? JSON.stringify(ax.response.data.errors) : 'Failed to save member.');
  } finally {
    formLoading.value = false;
  }
}

async function retryPendingTrainerAddMember() {
  try {
    const raw = sessionStorage.getItem('pendingApiRequest');
    if (!raw) return;

    const pending = JSON.parse(raw) as { url?: string; method?: string; data?: unknown };

    // Only retry this exact request.
    if (pending.url !== '/trainer/members' || String(pending.method ?? '').toLowerCase() !== 'post') return;

    sessionStorage.removeItem('pendingApiRequest');

    if (!isLoggedIn.value) return;

    const payload = pending.data ?? null;
    if (!payload) return;

    await trainerMembers.store(payload as Record<string, unknown>);
    showAddMemberModal.value = false;
    await loadMembersData();
    await loadDashboardData();
  } catch {
    // If retry fails, we just clear the pending request and let user try again.
    try {
      sessionStorage.removeItem('pendingApiRequest');
    } catch {
      // ignore
    }
  }
}

function filteredLogs() {
  const list = attendanceLogs.value;
  const q = (attendanceSearch.value || '').toLowerCase();
  if (!q) return list;
  return list.filter((l) => String((l as Record<string, unknown>).member_name || '').toLowerCase().includes(q));
}

function filteredMembers() {
  let list = membersList.value;
  const q = (memberSearch.value || '').toLowerCase();
  if (q) {
    list = list.filter(
      (m) =>
        String((m as Record<string, unknown>).full_name || '').toLowerCase().includes(q) ||
        String((m as Record<string, unknown>).email || '').toLowerCase().includes(q) ||
        String((m as Record<string, unknown>).member_id || '').toLowerCase().includes(q)
    );
  }
  const statusFilter = memberStatusFilter.value;
  const planFilter = memberPlanFilter.value;
  const today = new Date().toISOString().slice(0, 10);
  const in30 = new Date();
  in30.setDate(in30.getDate() + 30);
  const in30Str = in30.toISOString().slice(0, 10);
  if (statusFilter) {
    list = list.filter((m) => {
      const status = String((m as Record<string, unknown>).status || '').toLowerCase();
      const expiry = (m as Record<string, unknown>).expiry_date as string | undefined;
      if (statusFilter === 'active') return status === 'active' && (!expiry || expiry >= today);
      if (statusFilter === 'expired') return !!(expiry && expiry < today);
      if (statusFilter === 'ending soon') return !!(expiry && expiry >= today && expiry <= in30Str);
      return true;
    });
  }
  if (planFilter) {
    list = list.filter((m) => String((m as Record<string, unknown>).membership_plan || '').toLowerCase() === planFilter.toLowerCase());
  }
  return list;
}

function viewMember(m: Record<string, unknown>) {
  selectedMemberForView.value = m;
  showViewMemberModal.value = true;
}

async function editMember(m: Record<string, unknown>) {
  selectedMemberForView.value = null;
  showViewMemberModal.value = false;
  selectedMemberForEdit.value = m;
  const planFromName = plansList.value.find(
    (p) => String((p as Record<string, unknown>).name || '').toLowerCase() === String(m.membership_plan || '').toLowerCase()
  ) as Record<string, unknown> | undefined;
  memberForm.value = {
    full_name: String(m.full_name || ''),
    email: String(m.email || ''),
    phone: String(m.phone || ''),
    address: memberStreetAddress(m.address),
    membership_plan_id:
      m.membership_plan_id != null
        ? String(m.membership_plan_id)
        : planFromName
          ? String(planFromName.id)
          : (plansList.value[0] ? String((plansList.value[0] as Record<string, unknown>).id) : ''),
  };
  showAddMemberModal.value = true;

  // Ensure full detail fields (especially address) are loaded in edit modal.
  if (m.id != null) {
    try {
      const { data } = await members.get(Number(m.id));
      const full = (data as Record<string, unknown>) || {};
      if (full.address) memberForm.value.address = memberStreetAddress(full.address);
      if (full.membership_plan) {
        const plan = plansList.value.find(
          (p) => String((p as Record<string, unknown>).name || '').toLowerCase() === String(full.membership_plan || '').toLowerCase()
        ) as Record<string, unknown> | undefined;
        if (plan?.id != null) memberForm.value.membership_plan_id = String(plan.id);
      }
    } catch {
      // keep existing form values from list payload
    }
  }
}

async function deleteMember(m: Record<string, unknown>) {
  const id = m.id;
  if (id == null) return;
  if (!confirm(`Delete member "${String(m.full_name || '')}"?`)) return;
  formLoading.value = true;
  formError.value = '';
  try {
    await members.delete(Number(id));
    await loadMembersData();
    await loadDashboardData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to delete member.';
  } finally {
    formLoading.value = false;
  }
}

async function approveMember(m: Record<string, unknown>) {
  const id = m.id;
  if (id == null) return;
  if (!confirm(`Approve ${m.full_name}'s account? They will be able to log in once approved.`)) return;
  formLoading.value = true;
  formError.value = '';
  try {
    await members.approve(Number(id));
    await loadMembersData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to approve member.';
  } finally {
    formLoading.value = false;
  }
}

function memberStatusLabel(status: unknown, expiryDate?: string): string {
  const s = String(status || '').toLowerCase();
  if (s === 'pending') return 'Pending Approval';
  if (s === 'active' && expiryDate) {
    const today = new Date().toISOString().slice(0, 10);
    const in30 = new Date();
    in30.setDate(in30.getDate() + 30);
    const in30Str = in30.toISOString().slice(0, 10);
    if (expiryDate < today) return 'Expired';
    if (expiryDate <= in30Str) return 'Ending Soon';
  }
  if (s === 'expired' || (expiryDate && expiryDate < new Date().toISOString().slice(0, 10))) return 'Expired';
  if (s === 'ending soon') return 'Ending Soon';
  return s || 'Active';
}

function statusBadgeClass(status: unknown, expiryDate?: string): string {
  const label = memberStatusLabel(status, expiryDate).toLowerCase();
  if (label === 'active') return 'bg-green-500/20 text-green-300';
  if (label === 'expired') return 'bg-red-500/20 text-red-300';
  if (label === 'ending soon' || label === 'pending approval') return 'bg-yellow-500/20 text-yellow-300';
  return 'bg-gray-500/20 text-gray-300';
}

function paymentBadgeClass(paymentStatus: unknown): string {
  const p = String(paymentStatus || '').toLowerCase();
  if (p === 'paid') return 'bg-green-500/20 text-green-300';
  if (p === 'pending') return 'bg-yellow-500/20 text-yellow-300';
  if (p === 'overdue' || p === 'cancelled' || p === 'rejected') return 'bg-red-500/20 text-red-300';
  return 'bg-gray-500/20 text-gray-300';
}

function openAddPaymentModal() {
  selectedPaymentForEdit.value = null;
  formError.value = '';
  paymentForm.value = {
    member_id: '',
    amount: '',
    payment_date: new Date().toISOString().slice(0, 10),
    payment_method: 'Cash',
  };
  showAddPaymentModal.value = true;
  if (membersList.value.length === 0) {
    members.list().then((r) => {
      membersList.value = Array.isArray(r.data) ? r.data : [];
    }).catch(() => {});
  }
}

function viewPayment(pay: Record<string, unknown>) {
  selectedPaymentForView.value = pay;
  showViewPaymentModal.value = true;
}

function editPayment(pay: Record<string, unknown>) {
  selectedPaymentForEdit.value = pay;
  paymentForm.value = {
    member_id: String(pay.member_id ?? ''),
    amount: String(pay.amount ?? ''),
    payment_date: String(pay.payment_date ?? new Date().toISOString().slice(0, 10)),
    payment_method: String(pay.payment_method ?? 'Cash'),
  };
  formError.value = '';
  showAddPaymentModal.value = true;
}

async function deletePayment(pay: Record<string, unknown>) {
  const id = pay.id;
  if (id == null) return;
  if (!confirm(`Delete payment ${pay.invoice_number}?`)) return;
  formLoading.value = true;
  try {
    await payments.delete(Number(id));
    await loadPaymentsData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to delete payment.';
  } finally {
    formLoading.value = false;
  }
}

async function submitAddPayment() {
  if (!paymentForm.value.member_id || !paymentForm.value.amount || !paymentForm.value.payment_date) {
    formError.value = 'Fill required fields.';
    return;
  }
  formLoading.value = true;
  formError.value = '';
  const payload = {
    member_id: Number(paymentForm.value.member_id),
    amount: Number(paymentForm.value.amount),
    payment_date: paymentForm.value.payment_date,
    payment_method: paymentForm.value.payment_method,
  };
  try {
    if (selectedPaymentForEdit.value && selectedPaymentForEdit.value.id != null) {
      await payments.update(Number(selectedPaymentForEdit.value.id), payload);
      selectedPaymentForEdit.value = null;
    } else {
      await payments.create(payload);
    }
    showAddPaymentModal.value = false;
    await loadPaymentsData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to save payment.';
  } finally {
    formLoading.value = false;
  }
}

async function exportReports() {
  try {
    const { data } = await reports.exportCsv();
    const blob = data as Blob;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `FitLife_Report_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    formError.value = 'Export failed.';
  }
}


async function handleLogin() {
  if (loginLoading.value) return;
  loginError.value = '';
  loginLoading.value = true;
  try {
    const { data } = await trainerAuth.login({
      email: loginForm.value.email,
      password: loginForm.value.password,
      remember: loginForm.value.remember,
    });
    if (data.success && data.user) {
      user.value = data.user;
      isLoggedIn.value = true;
      await Promise.all([loadDashboardData(), loadGymInfo()]);
      startTrainerPortalAutoRefresh();
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    loginError.value = ax.response?.data?.message || 'Login failed.';
  } finally {
    loginLoading.value = false;
  }
}

async function handleLogout() {
  try {
    await trainerAuth.logout();
  } finally {
    user.value = null;
    isLoggedIn.value = false;
    stopTrainerPortalAutoRefresh();
    // Back to the single sign-in page; the reload also refreshes the CSRF token.
    goToEntry('trainer');
  }
}

// Same data as Blade trainer: GET /api/membership-plans + GET /api/reports/revenue (use same DB in .env so data matches)
async function loadMembershipsData() {
  dataLoading.value = true;
  try {
    const [planRes, revRes, memRes] = await Promise.all([
      membershipPlans.list().catch(() => ({ data: [] })),
      reports.revenue().catch(() => ({ data: {} })),
      reports.membership().catch(() => ({ data: {} })),
    ]);
    plansList.value = Array.isArray(planRes.data) ? planRes.data : [];
    reportRevenue.value = (revRes.data as Record<string, unknown>) || null;
    reportMembership.value = (memRes.data as Record<string, unknown>) || null;
  } catch {
    plansList.value = [];
  } finally {
    dataLoading.value = false;
  }
}

async function loadPaymentsData() {
  dataLoading.value = true;
  try {
    const res = await payments.list().catch(() => ({ data: { payments: [], summary: null } }));
    const data = res.data as { payments?: Record<string, unknown>[]; summary?: Record<string, unknown> };
    paymentsList.value = Array.isArray(data?.payments) ? data.payments : [];
    paymentSummary.value = data?.summary || null;
  } catch {
    paymentsList.value = [];
    paymentSummary.value = null;
  } finally {
    dataLoading.value = false;
  }
}

function pendingPayments(): Record<string, unknown>[] {
  return paymentsList.value.filter((p) => String((p as Record<string, unknown>).status || '').toLowerCase() === 'pending');
}

async function verifyPaymentStatus(pay: Record<string, unknown>, status: 'Paid' | 'Cancelled') {
  const id = Number(pay.id);
  if (!id) return;
  const actionLabel = status === 'Paid' ? 'approve' : 'reject';
  if (!confirm(`Are you sure you want to ${actionLabel} this payment?`)) return;

  processingPaymentId.value = id;
  formError.value = '';
  try {
    const existingNotes = String(pay.notes || '').trim();
    const verifyNote = status === 'Paid' ? 'Verified by staff.' : 'Rejected by staff.';
    const mergedNotes = existingNotes ? `${existingNotes} ${verifyNote}` : verifyNote;

    await payments.update(id, { status, notes: mergedNotes });
    await loadPaymentsData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to update payment status.';
  } finally {
    processingPaymentId.value = null;
  }
}

async function loadReportsData() {
  dataLoading.value = true;
  try {
    const [revRes, attRes, memRes] = await Promise.all([
      reports.revenue().catch(() => ({ data: {} })),
      reports.attendance().catch(() => ({ data: {} })),
      reports.membership().catch(() => ({ data: {} })),
    ]);
    reportRevenue.value = (revRes.data as Record<string, unknown>) || null;
    reportAttendance.value = (attRes.data as Record<string, unknown>) || null;
    reportMembership.value = (memRes.data as Record<string, unknown>) || null;
  } catch {
    //
  } finally {
    dataLoading.value = false;
  }
}

async function loadProfileData() {
  try {
    const { data } = await trainerProfile.show();
    const res = data as { trainer?: Record<string, unknown> };
    profileTrainer.value = res?.trainer || null;
    if (profileTrainer.value) {
      const s = profileTrainer.value as Record<string, unknown>;
      profileForm.value = {
        full_name: String(s.full_name || s.name || ''),
        email: String(s.email || ''),
        phone: String(s.phone || ''),
      };
    }
  } catch {
    profileTrainer.value = null;
  }
}

async function saveProfile() {
  formLoading.value = true;
  formError.value = '';
  profileNotice.value = '';
  try {
    await trainerProfile.update(profileForm.value);
    if (user.value) user.value.name = profileForm.value.full_name;
    await loadProfileData();
    profileNotice.value = 'Profile updated successfully.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Update failed.';
  } finally {
    formLoading.value = false;
  }
}

async function submitChangePassword() {
  if (passwordForm.value.new_password !== passwordForm.value.new_password_confirmation) {
    formError.value = 'Passwords do not match.';
    return;
  }
  formLoading.value = true;
  formError.value = '';
  try {
    await trainerProfile.changePassword({
      current_password: passwordForm.value.current_password,
      new_password: passwordForm.value.new_password,
      new_password_confirmation: passwordForm.value.new_password_confirmation,
    });
    showPasswordModal.value = false;
    passwordForm.value = { current_password: '', new_password: '', new_password_confirmation: '' };
    profileNotice.value = 'Password changed successfully. Use your new password the next time you sign in.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to change password.';
  } finally {
    formLoading.value = false;
  }
}

// Reports chart data (same structure as Blade trainer - use API data when available, else Blade defaults)
const weeklyActivityData = computed(() => {
  const daily = (reportAttendance.value?.daily_stats as Array<{ date?: string; count?: number }>) || [];
  const checkins = daily.length >= 7 ? daily.slice(-7).map((d) => Number(d.count ?? 0)) : [0, 0, 0, 0, 0, 0, 0];
  // Money actually received on each of those days (Paid payments), from the revenue report
  const dailyRevenue = (reportRevenue.value?.daily_revenue as Array<{ amount?: number }>) || [];
  const revenuePerDay = checkins.map((_, i) => Number(dailyRevenue.slice(-7)[i]?.amount ?? 0));
  return { checkins: checkins.length === 7 ? checkins : [0, 0, 0, 0, 0, 0, 0], revenue: revenuePerDay };
});

const membershipGrowthData = computed(() => {
  const total = Number(reportMembership.value?.total_members ?? reportRevenue.value?.total_members ?? 0);
  if (total > 0) {
    const t = total;
    return [Math.max(0, t - 22), Math.max(0, t - 19), Math.max(0, t - 12), Math.max(0, t - 5), Math.max(0, t - 7), t];
  }
  return [0, 0, 0, 0, 0, 0];
});

const planDistributionChartData = computed(() => {
  const plans = (reportRevenue.value?.plans as Array<{ name?: string; member_count?: number }>) || [];
  if (plans.length > 0) {
    return { labels: plans.map((p) => p.name || 'Plan'), data: plans.map((p) => p.member_count ?? 0) };
  }
  return { labels: ['No Data'], data: [0] };
});

const revenueByPlanChartData = computed(() => {
  const plans = (reportRevenue.value?.plans as Array<{ name?: string; member_count?: number; price?: number }>) || [];
  if (plans.length > 0) {
    return {
      labels: plans.map((p) => p.name || 'Plan'),
      data: plans.map((p) => (Number(p.price) || 0) * (p.member_count ?? 0)),
    };
  }
  return { labels: ['No Data'], data: [0] };
});

const reportTrendsPeakHour = computed(() => {
  const peak = (reportAttendance.value?.peak_hours as Array<{ hour?: string }>) || [];
  return peak.length > 0 ? peak[0].hour || '-' : '-';
});
const reportTrendsBusiestDay = computed(() => {
  const daily = (reportAttendance.value?.daily_stats as Array<{ date?: string; count?: number }>) || [];
  if (daily.length > 0) {
    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const byDay = daily.slice(-7);
    let maxIdx = 0;
    byDay.forEach((d, i) => {
      if ((d.count ?? 0) > (byDay[maxIdx]?.count ?? 0)) maxIdx = i;
    });
    return days[new Date(byDay[maxIdx]?.date || '').getDay()] || 'Saturday';
  }
  return '-';
});

function destroyReportCharts() {
  Object.values(reportCharts.value).forEach((c) => {
    if (c) c.destroy();
  });
  reportCharts.value = {};
}

function buildReportsCharts() {
  if (currentView.value !== 'reports') return;
  destroyReportCharts();
  nextTick(() => {
    const c = chartPalette();
    const commonOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: c.text } } }, scales: { y: { beginAtZero: true, grid: { color: c.grid }, ticks: { color: c.text } }, x: { grid: { display: false }, ticks: { color: c.text } } } };
    const doughnutPieOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: c.text } } } };
    if (reportsTab.value === 'overview' && weeklyChartCanvas.value) {
      const w = weeklyActivityData.value;
      reportCharts.value.weekly = new Chart(weeklyChartCanvas.value, {
        type: 'bar',
        data: {
          labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
          datasets: [
            { label: 'Check-ins', data: w.checkins, backgroundColor: `rgba(${c.accentRgb}, 0.6)`, borderColor: c.accent, borderWidth: 1, borderRadius: 4 },
            { label: 'Revenue', data: w.revenue, backgroundColor: `rgba(${c.neutralRgb}, 0.6)`, borderColor: c.neutral, borderWidth: 1, borderRadius: 4 },
          ],
        },
        options: commonOptions as ChartConfiguration<'bar'>['options'],
      });
    }
    if (reportsTab.value === 'trends' && growthChartCanvas.value) {
      reportCharts.value.growth = new Chart(growthChartCanvas.value, {
        type: 'line',
        data: {
          labels: ['Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan'],
          datasets: [{ label: 'Total Members', data: membershipGrowthData.value, fill: false, borderColor: c.accent, tension: 0.1 }],
        },
        options: commonOptions as ChartConfiguration<'line'>['options'],
      });
    }
    if (reportsTab.value === 'breakdown') {
      if (planDistCanvas.value) {
        const p = planDistributionChartData.value;
        reportCharts.value.planDist = new Chart(planDistCanvas.value, {
          type: 'doughnut',
          data: { labels: p.labels, datasets: [{ label: 'Members', data: p.data, backgroundColor: c.series, borderColor: c.surface, hoverOffset: 4 }] },
          options: doughnutPieOptions as ChartConfiguration<'doughnut'>['options'],
        });
      }
      if (revenueChartCanvas.value) {
        const r = revenueByPlanChartData.value;
        reportCharts.value.revenue = new Chart(revenueChartCanvas.value, {
          type: 'pie',
          data: { labels: r.labels, datasets: [{ label: 'Revenue (PHP)', data: r.data, backgroundColor: c.series, borderColor: c.surface, hoverOffset: 4 }] },
          options: doughnutPieOptions as ChartConfiguration<'pie'>['options'],
        });
      }
    }
  });
}

const weeklyChartCanvas = ref<HTMLCanvasElement | null>(null);
const growthChartCanvas = ref<HTMLCanvasElement | null>(null);
const planDistCanvas = ref<HTMLCanvasElement | null>(null);
const revenueChartCanvas = ref<HTMLCanvasElement | null>(null);

function plansWithStats(): Record<string, unknown>[] {
  const plans = plansList.value;
  const revPlans = (reportRevenue.value?.plans as Array<{ id: number; member_count: number; price?: number }>) || [];
  return plans.map((p) => {
    const rev = revPlans.find((r) => Number(r.id) === Number((p as Record<string, unknown>).id));
    const count = rev?.member_count ?? 0;
    // what was actually received for the plan this month, not price x members
    return { ...p, member_count: count, monthly_revenue: Number((rev as { monthly_revenue?: number } | undefined)?.monthly_revenue ?? 0) };
  });
}

function activePlansWithStats(): Record<string, unknown>[] {
  // Deactivated plans stay listed (last) so they can be reactivated, as in Admin.
  return plansWithStats().sort(
    (a, b) => Number(Boolean((a as Record<string, unknown>).is_deactivated)) - Number(Boolean((b as Record<string, unknown>).is_deactivated)),
  );
}

function formatCurrency(value: unknown): string {
  const amount = Number(value ?? 0);
  return `PHP ${(Number.isFinite(amount) ? amount : 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Admin-style badge class for a member's status label. */
function staffStatusBadge(status: unknown, expiryDate?: string): string {
  const label = memberStatusLabel(status, expiryDate).toLowerCase();
  if (label === 'active') return 'active';
  if (label === 'pending approval' || label === 'ending soon') return 'pending';
  return 'inactive';
}

function mostSubscribedPlanName(): string {
  const revPlans = (reportRevenue.value?.plans as Array<{ name?: string; member_count: number }>) || [];
  if (revPlans.length === 0) return '-';
  const top = revPlans.reduce((a, b) => (a.member_count >= b.member_count ? a : b), revPlans[0]);
  return top.member_count > 0 ? (top.name || '-') : '-';
}

// Same as Admin > Membership Plans: only the features saved on the plan, so both
// portals (and members) see the same list instead of one guessed from the name.
function planFeatures(plan: Record<string, unknown>): string[] {
  const saved = Array.isArray(plan.features) ? (plan.features as unknown[]).map(String).filter(Boolean) : [];
  return saved;
}

const SCHEDULE_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function firstApiError(err: unknown, fallback: string): string {
  const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : undefined;
  return first || data?.message || fallback;
}

async function loadScheduleData() {
  scheduleLoading.value = true;
  scheduleError.value = '';
  try {
    const [classesRes, staffRes] = await Promise.all([
      gymClasses.list({ date: scheduleDate.value }),
      trainer.list().catch(() => ({ data: { trainer: [] } })),
    ]);
    scheduleClasses.value = (classesRes.data as { classes?: ScheduleClass[] }).classes ?? [];
    const staffRaw = (staffRes.data as { trainer?: Array<{ id: number; full_name?: string }> }).trainer ?? [];
    scheduleStaff.value = staffRaw.map((s) => ({ id: Number(s.id), name: String(s.full_name || 'Staff') }));
  } catch (err: unknown) {
    scheduleClasses.value = [];
    scheduleError.value = firstApiError(err, 'Failed to load the class schedule.');
  } finally {
    scheduleLoading.value = false;
  }
}

// Calendar grid: one row per start time that has a class this week.
const scheduleCalendarRows = computed(() => {
  const times = [...new Set(scheduleClasses.value.map((c) => c.start_time))].sort();
  return times.map((time) => ({
    time,
    cells: SCHEDULE_DAYS.map((day) => ({
      day,
      classes: scheduleClasses.value.filter((c) => c.day_of_week === day && c.start_time === time),
    })),
  }));
});

function classesTaughtBy(staffId: number | null, day: string): ScheduleClass[] {
  return scheduleClasses.value.filter((c) => (c.trainer_id ?? null) === staffId && c.day_of_week === day);
}

const hasUnassignedClasses = computed(() => scheduleClasses.value.some((c) => !c.trainer_id));

function staffInitials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p.charAt(0).toUpperCase()).join('') || 'S';
}

function classIsFull(cls: ScheduleClass): boolean {
  return cls.booked_count >= cls.capacity;
}

function formatClassDate(date: string): string {
  const d = new Date(`${date}T00:00:00`);
  return Number.isNaN(d.getTime()) ? date : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function openAddClassModal() {
  selectedManagedClassForEdit.value = null;
  scheduleClassForm.value = { name: '', trainer_id: '', days: ['Monday'], start_time: '09:00', end_time: '10:00', capacity: '10' };
  formError.value = '';
  showScheduleModal.value = true;
}

function openEditClassModal(cls: ScheduleClass) {
  selectedManagedClassForEdit.value = cls.id;
  scheduleClassForm.value = {
    name: cls.name,
    trainer_id: cls.trainer_id ? String(cls.trainer_id) : '',
    days: [cls.day_of_week],
    start_time: cls.start_time,
    end_time: cls.end_time,
    capacity: String(cls.capacity),
  };
  formError.value = '';
  showScheduleModal.value = true;
}

async function submitManagedClass() {
  const form = scheduleClassForm.value;
  const name = form.name.trim();
  const capacity = Number(form.capacity);

  if (!name || form.days.length === 0 || !form.start_time || !form.end_time || !Number.isInteger(capacity) || capacity < 1) {
    formError.value = 'Please complete the class name, day, start and end time, and a valid capacity.';
    return;
  }
  if (form.end_time <= form.start_time) {
    formError.value = 'End time must be after the start time.';
    return;
  }

  const base = {
    name,
    trainer_id: form.trainer_id ? Number(form.trainer_id) : null,
    start_time: form.start_time,
    end_time: form.end_time,
    capacity,
  };

  formLoading.value = true;
  formError.value = '';
  try {
    if (selectedManagedClassForEdit.value !== null) {
      await gymClasses.update(selectedManagedClassForEdit.value, { ...base, day_of_week: form.days[0] });
    } else {
      // One weekly class per selected day.
      for (const day of form.days) {
        await gymClasses.create({ ...base, day_of_week: day });
      }
    }
    showScheduleModal.value = false;
    selectedManagedClassForEdit.value = null;
    await loadScheduleData();
  } catch (err: unknown) {
    formError.value = firstApiError(err, 'Failed to save the class.');
    await loadScheduleData();
  } finally {
    formLoading.value = false;
  }
}

async function deleteManagedClass(cls: ScheduleClass) {
  if (!confirm(`Delete "${cls.name}" on ${cls.day_of_week}s? Members will no longer be able to book it.`)) return;
  try {
    await gymClasses.delete(cls.id);
    await loadScheduleData();
  } catch (err: unknown) {
    scheduleError.value = firstApiError(err, 'Failed to delete the class.');
  }
}

function openAddPlan() {
  selectedPlanForEdit.value = null;
  planForm.value = { name: '', type: 'Monthly', price: '', period: 'per month', description: '', is_popular: false, features: [''] };
  showAddPlanModal.value = true;
  formError.value = '';
}

function openEditPlan(plan: Record<string, unknown>) {
  selectedPlanForEdit.value = plan;
  planForm.value = {
    name: String(plan.name || ''),
    type: String(plan.type || 'Monthly'),
    price: String(plan.price ?? ''),
    period: String(plan.period || 'per month'),
    description: String(plan.description || ''),
    is_popular: !!plan.is_popular,
    features: Array.isArray(plan.features) ? (plan.features as unknown[]).map(String) : [],
  };
  showAddPlanModal.value = true;
  formError.value = '';
}

async function deletePlan(plan: Record<string, unknown>) {
  const id = plan.id;
  if (id == null) return;
  if (!confirm(`Delete plan "${plan.name}"? This cannot be undone.`)) return;
  formLoading.value = true;
  formError.value = '';
  try {
    await membershipPlans.delete(Number(id));
    await loadMembershipsData();
    showAddPlanModal.value = false;
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to delete plan.';
  } finally {
    formLoading.value = false;
  }
}

async function deactivatePlan(plan: Record<string, unknown>) {
  const id = plan.id;
  if (id == null) return;
  const action = plan.is_deactivated ? 'Reactivate' : 'Deactivate';
  if (!confirm(`${action} plan "${plan.name}"?`)) return;
  formLoading.value = true;
  formError.value = '';
  try {
    await membershipPlans.update(Number(id), {
      name: plan.name,
      type: plan.type,
      price: Number(plan.price) || 0,
      period: plan.period,
      description: plan.description,
      is_deactivated: plan.is_deactivated ? 0 : 1,
    });
    await loadMembershipsData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || `Failed to ${action.toLowerCase()} plan.`;
    alert(formError.value);
  } finally {
    formLoading.value = false;
  }
}

async function submitAddPlan() {
  formLoading.value = true;
  formError.value = '';
  const payload = {
    name: planForm.value.name,
    type: planForm.value.type,
    price: Number(planForm.value.price) || 0,
    period: planForm.value.period || '',
    description: planForm.value.description || '',
    is_popular: planForm.value.is_popular ? 1 : 0,
    features: planForm.value.features.map((f) => f.trim()).filter(Boolean),
  };
  try {
    if (selectedPlanForEdit.value && selectedPlanForEdit.value.id != null) {
      await membershipPlans.update(Number(selectedPlanForEdit.value.id), payload);
      selectedPlanForEdit.value = null;
    } else {
      await membershipPlans.create(payload);
    }
    await loadMembershipsData();
    showAddPlanModal.value = false;
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to save plan.';
  } finally {
    formLoading.value = false;
  }
}

watch(currentView, (view) => {
  if (!isLoggedIn.value) return;
  if (view === 'attendance') loadAttendanceData();
  if (view === 'members') loadMembersData();
  if (view === 'dashboard') {
    loadDashboardData();
    nextTick(() => buildDashboardChart());
  } else {
    destroyDashboardChart();
  }
  if (view === 'memberships') loadMembershipsData();
  if (view === 'profile') loadProfileData();
  if (view === 'schedule') loadScheduleData();
  startTrainerPortalAutoRefresh();
});

watch(scheduleDate, () => {
  if (isLoggedIn.value && currentView.value === 'schedule') loadScheduleData();
});

watch(isLoggedIn, (loggedIn) => {
  if (loggedIn) {
    startTrainerPortalAutoRefresh();
  } else {
    stopTrainerPortalAutoRefresh();
  }
});

useSessionGuard({
  role: 'trainer',
  fetchUser: trainerAuth.user,
  currentEmail: () => user.value?.email,
  isActive: () => isLoggedIn.value,
});

onMounted(async () => {
  await checkAuth();
  await retryPendingTrainerAddMember();
  startTrainerPortalAutoRefresh();
  nextTick(() => {
    if (currentView.value === 'dashboard') buildDashboardChart();
  });
});

onUnmounted(() => {
  stopTrainerPortalAutoRefresh();
  destroyDashboardChart();
  destroyReportCharts();
});
</script>

<template>
  <Head :title="`${gymDisplayName} - Staff Portal`">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
  </Head>
  <div class="trainer-portal fg-marble-bg min-h-screen text-white">
    <div v-if="!isLoggedIn && !loading" class="trainer-auth-shell flex min-h-screen items-center justify-center p-4">
      <div class="trainer-auth-card w-full max-w-md rounded-2xl border p-6 sm:p-8">
        <div class="text-center">
          <p class="trainer-auth-eyebrow">Staff Workspace</p>
          <h1 class="trainer-auth-title">Staff Portal</h1>
          <p class="trainer-auth-subtitle">Manage attendance, members, schedules, and staff-side daily tasks.</p>
        </div>
        <div class="trainer-auth-role-badge">Assigned Staff Access</div>
        <form @submit.prevent="handleLogin" class="space-y-6">
          <div>
            <label class="mb-1 block text-sm font-medium text-white">Email Address</label>
            <input
              v-model="loginForm.email"
              type="email"
              required
              autocomplete="email"
              class="w-full rounded-lg border px-3 py-3 text-white placeholder-gray-500"
              placeholder="staff@example.com"
            />
          </div>
          <div>
            <div class="mb-1 flex flex-wrap items-center justify-between gap-x-3">
              <label class="block text-sm font-medium text-white">Password</label>
              <a href="/forgot-password" class="trainer-auth-link text-sm hover:underline">Forgot password?</a>
            </div>
            <div class="fg-password-field">
              <input
                v-model="loginForm.password"
                :type="pw.type('login')"
                required
                autocomplete="current-password"
                class="w-full rounded-lg border px-3 py-3 text-white placeholder-gray-500"
                placeholder="Enter your password"
              />
              <PasswordToggle :shown="pw.isShown('login')" @toggle="pw.toggle('login')" />
            </div>
          </div>
          <div class="flex items-center">
            <input v-model="loginForm.remember" type="checkbox" value="1" class="h-4 w-4 rounded border" />
            <span class="ml-2 block text-sm text-[var(--text-secondary)]">Remember me</span>
          </div>
          <p v-if="loginError" class="trainer-auth-error text-sm">{{ loginError }}</p>
          <button type="submit" :disabled="loginLoading" class="trainer-auth-primary w-full cursor-pointer rounded-lg py-3 font-semibold disabled:opacity-50">
            {{ loginLoading ? 'Signing in...' : 'Sign In' }}
          </button>
          <p class="text-center text-xs text-[var(--text-secondary)]">Use your assigned staff account credentials.</p>
        </form>
      </div>
    </div>

    <div v-else-if="loading" class="trainer-auth-shell flex min-h-screen items-center justify-center p-4">
      <div class="trainer-auth-card w-full max-w-md rounded-2xl border p-8 text-center">
        <p class="trainer-auth-eyebrow">Staff Workspace</p>
        <p class="trainer-auth-subtitle">Loading staff workspace...</p>
      </div>
    </div>

    <div v-else>
      <div class="trainer-shell fg-shell fg-marble-bg mx-auto flex min-h-[calc(100vh-2rem)] max-w-[1500px] overflow-hidden rounded-[1.75rem] border shadow-2xl">
        <div v-if="mobileNavOpen" class="fg-nav-backdrop" aria-hidden="true" @click="closeMobileNav" />
        <aside id="trainer-sidebar" class="trainer-sidebar fg-sidebar flex w-[17.5rem] shrink-0 flex-col px-5 py-8" :class="{ 'is-open': mobileNavOpen }">
          <div class="flex items-start justify-between gap-3 pb-6">
            <div class="min-w-0">
              <h1 class="text-2xl font-bold break-words text-white">{{ gymDisplayName }}</h1>
              <p class="mt-1 text-sm text-[var(--text-secondary)]">Staff Portal</p>
            </div>
            <button type="button" class="fg-menu-btn fg-sidebar-close" aria-label="Close navigation menu" @click="closeMobileNav">
              <X class="h-5 w-5" aria-hidden="true" />
            </button>
          </div>

          <div class="trainer-profile-card rounded-2xl border p-4">
            <div class="flex items-center gap-3">
              <div class="trainer-avatar flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-2xl text-lg font-bold">
                <img v-if="userPhotoUrl" :src="userPhotoUrl" alt="" class="h-full w-full object-cover" />
                <template v-else>{{ trainerInitials }}</template>
              </div>
              <div class="min-w-0">
                <p class="truncate font-semibold text-white">{{ trainerDisplayName }}</p>
                <p class="truncate text-xs text-[var(--text-secondary)]">{{ trainerRoleLabel }}</p>
              </div>
            </div>
          </div>

          <nav class="mt-6 flex flex-1 flex-col gap-2 overflow-y-auto">
            <button
              v-for="item in navItems"
              :key="item.id"
              type="button"
              class="trainer-nav-item flex w-full items-start rounded-xl px-4 py-3 text-left transition-colors"
              :class="{ active: currentView === item.id }"
              @click="currentView = item.id; closeMobileNav()"
            >
              <span class="min-w-0">
                <span class="block text-sm font-semibold">{{ item.label }}</span>
                <span class="mt-1 block text-xs opacity-70">{{ item.description }}</span>
              </span>
            </button>
          </nav>

          <div class="pt-6">
            <button type="button" class="trainer-logout-btn w-full rounded-xl px-4 py-3 text-sm font-semibold transition-opacity hover:opacity-90" @click="handleLogout">
              Logout
            </button>
          </div>
        </aside>

        <div class="trainer-content flex min-w-0 flex-1 flex-col overflow-hidden">
          <div class="fg-mobile-bar">
            <button
              type="button"
              class="fg-menu-btn"
              aria-label="Open navigation menu"
              aria-controls="trainer-sidebar"
              :aria-expanded="mobileNavOpen"
              @click="mobileNavOpen = true"
            >
              <Menu class="h-5 w-5" aria-hidden="true" />
            </button>
            <div class="min-w-0">
              <p class="truncate text-base font-bold text-white">{{ gymDisplayName }}</p>
              <p class="truncate text-xs text-[var(--text-secondary)]">Staff Portal</p>
            </div>
          </div>

          <header class="trainer-header flex flex-col gap-4 border-b px-4 py-5 sm:px-6 sm:py-6 lg:flex-row lg:items-center lg:justify-between lg:px-10">
            <div class="min-w-0">
              <h2 class="text-2xl font-bold text-[var(--fg-ink)]">{{ currentViewInfo.label }}</h2>
              <p class="mt-1 max-w-2xl text-sm text-[var(--fg-ink-soft)]">{{ currentViewInfo.description }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
              <FgThemeToggle class="trainer-header-card rounded-xl border px-4 py-3 text-sm text-[var(--text-primary)]" />
              <div class="trainer-header-card rounded-xl border px-4 py-3">
                <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-secondary)]">Staff Email</p>
                <p class="mt-1 text-sm font-semibold break-all text-white">{{ user?.email || 'No email' }}</p>
              </div>
              <div class="trainer-header-card rounded-xl border px-4 py-3">
                <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-secondary)]">Today Check-ins</p>
                <p class="mt-1 text-sm font-semibold text-white">{{ trainerCheckinsToday }}</p>
              </div>
              <div class="trainer-header-card rounded-xl border px-4 py-3">
                <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-secondary)]">Status</p>
                <p class="mt-1 text-sm font-semibold text-[var(--accent-green)]">Active</p>
              </div>
            </div>
          </header>

          <main class="trainer-main flex-1 overflow-x-hidden overflow-y-auto px-4 py-5 sm:px-6 sm:py-6 lg:px-10">
          <!-- Dashboard -->
          <div v-if="currentView === 'dashboard'" class="fg-dash">
            <h2 class="sr-only">Dashboard</h2>
            <div class="fg-dash-toolbar">
              <div class="fg-dash-greeting">
                <h3>Front desk</h3>
                <p>{{ dashboardToday }}</p>
              </div>
              <div class="fg-dash-actions">
                <button type="button" class="fg-dash-btn fg-dash-btn--primary" @click="openCheckinModal">
                  <QrCode aria-hidden="true" />
                  Record Check-in
                </button>
                <button type="button" class="fg-dash-btn" @click="openAddMemberModal">
                  <UserPlus aria-hidden="true" />
                  Add Member
                </button>
                <button type="button" class="fg-dash-btn" @click="currentView = 'attendance'">
                  <ClipboardList aria-hidden="true" />
                  Attendance Logs
                </button>
              </div>
            </div>

            <div class="fg-dash-kpis">
              <div class="fg-dash-kpi">
                <div class="fg-dash-kpi-head">
                  <span>Check-ins Today</span>
                  <span class="fg-dash-kpi-icon"><CheckCircle2 aria-hidden="true" /></span>
                </div>
                <div class="fg-dash-kpi-value">{{ dataLoading ? '-' : trainerCheckinsToday }}</div>
                <div class="fg-dash-kpi-sub">{{ staffWeekTotal }} this week</div>
              </div>
              <div class="fg-dash-kpi">
                <div class="fg-dash-kpi-head">
                  <span>Members Inside</span>
                  <span class="fg-dash-kpi-icon"><Users aria-hidden="true" /></span>
                </div>
                <div class="fg-dash-kpi-value">{{ dataLoading ? '-' : currentlyInGym.length }}</div>
                <div class="fg-dash-kpi-sub">In the gym right now</div>
              </div>
              <div class="fg-dash-kpi">
                <div class="fg-dash-kpi-head">
                  <span>Active Members</span>
                  <span class="fg-dash-kpi-icon"><UserCheck aria-hidden="true" /></span>
                </div>
                <div class="fg-dash-kpi-value">{{ reportAttendance?.active_members ?? 0 }}</div>
                <div class="fg-dash-kpi-sub">Approved member accounts</div>
              </div>
              <div class="fg-dash-kpi">
                <div class="fg-dash-kpi-head">
                  <span>Expiring Soon</span>
                  <span class="fg-dash-kpi-icon"><Clock aria-hidden="true" /></span>
                </div>
                <div class="fg-dash-kpi-value">{{ expiringMembers.length }}</div>
                <div :class="['fg-dash-kpi-sub', { 'is-alert': expiringRows[0]?.urgent }]">
                  {{ expiringRows[0] ? `Next: ${expiringRows[0].name}, ${expiringRows[0].date}` : 'None in the next 30 days' }}
                </div>
              </div>
            </div>

            <div class="fg-dash-row">
              <div class="fg-dash-card fg-dash-span-2">
                <div class="fg-dash-card-head">
                  <div>
                    <h3>Check-ins, Last 7 Days</h3>
                    <p>{{ staffWeekTotal }} visit{{ staffWeekTotal === 1 ? '' : 's' }} this week</p>
                  </div>
                </div>
                <div class="fg-dash-chart">
                  <canvas ref="dashboardChartCanvas" aria-label="Check-ins per day for the last 7 days" role="img"></canvas>
                  <p v-if="staffWeekTotal === 0 && !dataLoading" class="fg-dash-empty">
                    No check-ins in the last 7 days. Visits show here as members scan in at the front desk.
                  </p>
                </div>
              </div>

              <div class="fg-dash-card">
                <div class="fg-dash-card-head">
                  <div>
                    <h3>Today at the Gym</h3>
                    <p>Who is in and who has left</p>
                  </div>
                </div>
                <template v-if="todaySplit.total > 0">
                  <div class="fg-dash-donut">
                    <canvas ref="todayCanvas" aria-label="Members in the gym now and checked out today" role="img"></canvas>
                    <div class="fg-dash-donut-center">
                      <strong>{{ todaySplit.total }}</strong>
                      <span>visits today</span>
                    </div>
                  </div>
                  <ul class="fg-dash-legend">
                    <li>
                      <span class="fg-dash-swatch" style="background: var(--fg-accent)"></span>
                      <span class="fg-dash-legend-name">In the gym now</span>
                      <span class="fg-dash-legend-value">{{ todaySplit.inside }}</span>
                    </li>
                    <li>
                      <span class="fg-dash-swatch" style="background: rgba(var(--fg-neutral-rgb), 0.45)"></span>
                      <span class="fg-dash-legend-name">Checked out</span>
                      <span class="fg-dash-legend-value">{{ todaySplit.done }}</span>
                    </li>
                  </ul>
                </template>
                <p v-else-if="!dataLoading" class="fg-dash-placeholder">No visits yet today. Use Record Check-in when a member arrives.</p>
              </div>
            </div>

            <div class="fg-dash-row">
              <div class="fg-dash-card fg-dash-span-2">
                <div class="fg-dash-card-head">
                  <div>
                    <h3>Recent Check-ins</h3>
                    <p>Today, newest first</p>
                  </div>
                  <button type="button" class="fg-dash-link" @click="currentView = 'attendance'">View all</button>
                </div>
                <ul v-if="recentCheckins.length > 0" class="fg-dash-list">
                  <li v-for="c in recentCheckins.slice(0, 8)" :key="String((c as Record<string, unknown>).id)">
                    <span class="fg-dash-avatar">{{ initialsOf((c as Record<string, unknown>).member_name) }}</span>
                    <div class="fg-dash-person">
                      <p>{{ (c as Record<string, unknown>).member_name }}</p>
                      <p>Checked in at {{ to12Hour((c as Record<string, unknown>).check_in_time) }}</p>
                    </div>
                    <span :class="['fg-dash-tag', { 'fg-dash-tag--gold': (c as Record<string, unknown>).status === 'Inside' }]">
                      {{ (c as Record<string, unknown>).status === 'Inside' ? 'In gym' : 'Done' }}
                    </span>
                  </li>
                </ul>
                <p v-else-if="!dataLoading" class="fg-dash-placeholder">No check-ins yet today.</p>
              </div>

              <div class="fg-dash-card">
                <div class="fg-dash-card-head">
                  <div>
                    <h3>Expiring Memberships</h3>
                    <p>Ending in the next 30 days</p>
                  </div>
                  <button v-if="expiringMembers.length > 5" type="button" class="fg-dash-link" @click="currentView = 'members'">View all</button>
                </div>
                <ul v-if="expiringRows.length > 0" class="fg-dash-list">
                  <li v-for="m in expiringRows.slice(0, 5)" :key="m.id">
                    <span class="fg-dash-avatar">{{ m.initials }}</span>
                    <div class="fg-dash-person">
                      <p>{{ m.name }}</p>
                      <p>Ends {{ m.date }}</p>
                    </div>
                    <span :class="['fg-dash-tag', m.urgent ? 'fg-dash-tag--danger' : 'fg-dash-tag--gold']">{{ m.when }}</span>
                  </li>
                </ul>
                <p v-else-if="!dataLoading" class="fg-dash-placeholder">No memberships end in the next 30 days.</p>
              </div>
            </div>

            <div class="fg-dash-card">
              <div class="fg-dash-card-head">
                <div>
                  <h3>Pending Payments</h3>
                  <p>Check the receipt, then approve or reject</p>
                </div>
              </div>
              <ul v-if="pendingPayments().length > 0" class="fg-dash-list">
                <li v-for="p in pendingPayments()" :key="String((p as Record<string, unknown>).id)" class="fg-dash-pay">
                  <span class="fg-dash-avatar">{{ initialsOf((p as Record<string, unknown>).member_name) }}</span>
                  <div class="fg-dash-person">
                    <p>{{ (p as Record<string, unknown>).member_name }} · &#8369;{{ (p as Record<string, unknown>).amount }}</p>
                    <p>{{ (p as Record<string, unknown>).invoice_number }} · {{ (p as Record<string, unknown>).payment_method }} · {{ (p as Record<string, unknown>).payment_date }}</p>
                  </div>
                  <div class="fg-dash-pay-actions">
                    <button type="button" class="fg-dash-btn fg-dash-btn--primary fg-dash-btn--sm" :disabled="processingPaymentId === Number((p as Record<string, unknown>).id)" @click="verifyPaymentStatus(p as Record<string, unknown>, 'Paid')">Approve</button>
                    <button type="button" class="fg-dash-btn fg-dash-btn--danger fg-dash-btn--sm" :disabled="processingPaymentId === Number((p as Record<string, unknown>).id)" @click="verifyPaymentStatus(p as Record<string, unknown>, 'Cancelled')">Reject</button>
                  </div>
                </li>
              </ul>
              <p v-else-if="!dataLoading" class="fg-dash-placeholder">No pending payments.</p>
            </div>
          </div>

          <!-- Attendance -->
          <!-- Attendance: who is inside now (with Check Out), how recording works, and the full log -->
          <div v-if="currentView === 'attendance'" class="staff-admin-view staff-attendance">
            <div class="page-header">
              <h2>Attendance</h2>
              <div class="header-actions" style="display: flex; gap: 0.5rem; align-items: center;">
                <button type="button" class="btn-add-plan" @click="openCheckinModal">
                  <i class="fas fa-qrcode"></i>
                  <span>Record Check-in</span>
                </button>
              </div>
            </div>

            <ol class="attendance-steps">
              <li class="fg-tile">
                <span class="attendance-step-no">1</span>
                <div>
                  <p class="attendance-step-title">Member arrives</p>
                  <p class="attendance-step-text">They open <b>Attendance → Get Attendance QR</b> in their Member portal.</p>
                </div>
              </li>
              <li class="fg-tile">
                <span class="attendance-step-no">2</span>
                <div>
                  <p class="attendance-step-title">Record Check-in</p>
                  <p class="attendance-step-text">Scan their QR. No phone? Pick their name from the list instead.</p>
                </div>
              </li>
              <li class="fg-tile">
                <span class="attendance-step-no">3</span>
                <div>
                  <p class="attendance-step-title">Member leaves</p>
                  <p class="attendance-step-text">Scan the same QR again, or press <b>Check Out</b> below.</p>
                </div>
              </li>
            </ol>

            <div class="plans-summary">
              <div class="summary-card">
                <i class="fas fa-sign-in-alt"></i>
                <div>
                  <h3>{{ trainerCheckinsToday }}</h3>
                  <p>Check-ins today</p>
                </div>
              </div>
              <div class="summary-card">
                <i class="fas fa-dumbbell"></i>
                <div>
                  <h3>{{ currentlyInGym.length }}</h3>
                  <p>Inside the gym now</p>
                </div>
              </div>
              <div class="summary-card">
                <i class="fas fa-list"></i>
                <div>
                  <h3>{{ attendanceLogs.length }}</h3>
                  <p>Visits in the log</p>
                </div>
              </div>
            </div>

            <section class="table-container attendance-panel">
              <div class="attendance-panel-head">
                <h3>Inside the Gym Now <span class="attendance-count">{{ currentlyInGym.length }}</span></h3>
                <p>Checked in and not yet checked out.</p>
              </div>
              <div v-if="currentlyInGym.length" class="attendance-inside-grid">
                <div v-for="c in currentlyInGym" :key="String((c as Record<string, unknown>).id)" class="fg-tile attendance-inside-card">
                  <div class="member-avatar">{{ (c as Record<string, unknown>).initials }}</div>
                  <div class="attendance-inside-info">
                    <p>{{ (c as Record<string, unknown>).member_name }}</p>
                    <span>In since {{ (c as Record<string, unknown>).check_in_time }}</span>
                  </div>
                  <button type="button" class="attendance-checkout-btn" :disabled="checkingOutId === Number((c as Record<string, unknown>).id)" @click="checkOutVisit(c as Record<string, unknown>)">
                    {{ checkingOutId === Number((c as Record<string, unknown>).id) ? 'Saving…' : 'Check Out' }}
                  </button>
                </div>
              </div>
              <p v-else class="attendance-empty">{{ dataLoading ? 'Loading...' : 'Nobody is inside right now.' }}</p>
              <p v-if="attendanceError" class="attendance-error" role="alert">{{ attendanceError }}</p>
            </section>

            <section class="table-container attendance-panel">
              <div class="attendance-panel-head attendance-panel-head--row">
                <div>
                  <h3>Attendance Log</h3>
                  <p>Every visit, newest first.</p>
                </div>
                <div class="search-bar attendance-search">
                  <i class="fas fa-search"></i>
                  <input v-model="attendanceSearch" type="text" placeholder="Search by member name..." />
                </div>
              </div>
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Member</th>
                    <th>Date</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="l in filteredLogs()" :key="String((l as Record<string, unknown>).id)">
                    <td>
                      <div class="member-cell">
                        <div class="member-avatar">{{ (l as Record<string, unknown>).initials || String((l as Record<string, unknown>).member_name || '').slice(0, 2).toUpperCase() }}</div>
                        <div><p>{{ (l as Record<string, unknown>).member_name }}</p></div>
                      </div>
                    </td>
                    <td>{{ (l as Record<string, unknown>).date }}</td>
                    <td>{{ (l as Record<string, unknown>).check_in }}</td>
                    <td>{{ (l as Record<string, unknown>).check_out }}</td>
                    <td>
                      <span :class="['status-badge', (l as Record<string, unknown>).check_out === '-' ? 'pending' : 'active']">
                        {{ (l as Record<string, unknown>).check_out === '-' ? 'In gym' : 'Completed' }}
                      </span>
                    </td>
                  </tr>
                  <tr v-if="filteredLogs().length === 0">
                    <td colspan="5" class="empty-row">{{ dataLoading ? 'Loading...' : 'No visits yet.' }}</td>
                  </tr>
                </tbody>
              </table>
            </section>
          </div>
          <!-- Members -->
          <!-- Members: same layout as Admin > Members (Staff has no SMS action) -->
          <div v-if="currentView === 'members'" class="staff-admin-view">
            <div class="page-controls">
              <div class="search-bar">
                <i class="fas fa-search"></i>
                <input v-model="memberSearch" type="text" placeholder="SEARCH MEMBERS by name, email, or ID..." />
              </div>
              <select v-model="memberStatusFilter" class="filter-dropdown">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="pending">Pending Approval</option>
                <option value="expired">Expired</option>
                <option value="ending soon">Ending Soon</option>
              </select>
              <select v-model="memberPlanFilter" class="filter-dropdown">
                <option value="">All Plans</option>
                <option v-for="p in plansList" :key="`plan-filter-${String((p as Record<string, unknown>).id)}`" :value="String((p as Record<string, unknown>).name || '')">
                  {{ (p as Record<string, unknown>).name }}
                </option>
              </select>
              <button type="button" class="btn-add-member" @click="openAddMemberModal">
                <i class="fas fa-plus"></i>
                <span>Add Member</span>
              </button>
            </div>
            <div class="table-container">
              <table class="data-table members-table">
                <thead>
                  <tr>
                    <th>Member</th>
                    <th>Contact</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Expiry</th>
                    <th>Payment</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="m in filteredMembers()" :key="String((m as Record<string, unknown>).id)">
                    <td>
                      <div class="member-cell">
                        <div class="member-avatar">{{ String((m as Record<string, unknown>).full_name || '').slice(0, 2).toUpperCase() }}</div>
                        <div>
                          <p :title="String((m as Record<string, unknown>).full_name || '')">{{ (m as Record<string, unknown>).full_name }}</p>
                          <span :title="String((m as Record<string, unknown>).email || '')">ID {{ (m as Record<string, unknown>).member_id }} · {{ (m as Record<string, unknown>).email }}</span>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="members-table-stack">
                        <p>{{ (m as Record<string, unknown>).phone || '-' }}</p>
                        <span :title="memberStreetAddress((m as Record<string, unknown>).address) || '-'">{{ memberStreetAddress((m as Record<string, unknown>).address) || '-' }}</span>
                      </div>
                    </td>
                    <td>{{ (m as Record<string, unknown>).membership_plan }}</td>
                    <td>
                      <span :class="['status-badge', staffStatusBadge((m as Record<string, unknown>).status, (m as Record<string, unknown>).expiry_date as string)]">
                        {{ memberStatusLabel((m as Record<string, unknown>).status, (m as Record<string, unknown>).expiry_date as string) }}
                      </span>
                    </td>
                    <td>{{ (m as Record<string, unknown>).expiry_date }}</td>
                    <td>
                      <span :class="['status-badge', String((m as Record<string, unknown>).payment_status || '').toLowerCase() === 'paid' ? 'paid' : 'pending']">{{ (m as Record<string, unknown>).payment_status }}</span>
                    </td>
                    <td>
                      <div class="action-buttons">
                        <button v-if="String((m as Record<string, unknown>).status).toLowerCase() === 'pending'" type="button" class="action-btn green" title="Approve Account" :disabled="formLoading" @click="approveMember(m as Record<string, unknown>)"><i class="fas fa-user-check"></i></button>
                        <button type="button" class="action-btn blue" title="View" @click="viewMember(m as Record<string, unknown>)"><i class="fas fa-eye"></i></button>
                        <button type="button" class="action-btn purple" title="Edit" @click="editMember(m as Record<string, unknown>)"><i class="fas fa-edit"></i></button>
                        <button type="button" class="action-btn red" title="Delete" :disabled="formLoading" @click="deleteMember(m as Record<string, unknown>)"><i class="fas fa-trash"></i></button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="filteredMembers().length === 0">
                    <td colspan="7" class="empty-row">{{ dataLoading ? 'Loading...' : 'No members found.' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Membership Plans: same layout as Admin > Membership Plans -->
          <div v-if="currentView === 'memberships'" class="staff-admin-view">
            <div class="page-header">
              <h2>Membership Plans</h2>
              <div class="header-actions" style="display: flex; gap: 0.5rem; align-items: center;">
                <button type="button" class="btn-add-plan" @click="openAddPlan">
                  <i class="fas fa-plus"></i>
                  <span>Add New Plan</span>
                </button>
              </div>
            </div>
            <div class="plans-summary">
              <div class="summary-card">
                <i class="fas fa-file-alt"></i>
                <div>
                  <h3>{{ plansList.length }}</h3>
                  <p>Total membership plans</p>
                </div>
              </div>
              <div class="summary-card">
                <i class="fas fa-users"></i>
                <div>
                  <h3>{{ reportRevenue?.total_members ?? reportMembership?.total_members ?? membersList.length }}</h3>
                  <p>Active members</p>
                </div>
              </div>
              <div class="summary-card">
                <i class="fas fa-coins"></i>
                <div>
                  <h3>{{ formatCurrency(reportRevenue?.monthly_revenue) }}</h3>
                  <p>Total monthly revenue</p>
                </div>
              </div>
              <div class="summary-card">
                <i class="fas fa-star"></i>
                <div>
                  <h3>{{ mostSubscribedPlanName() }}</h3>
                  <p>Most subscribed plan</p>
                </div>
              </div>
            </div>
            <div v-if="plansList.length === 0 && !dataLoading" class="empty-state">
              <i class="fas fa-inbox"></i>
              <h3>No Plans Yet</h3>
              <p>Get started by creating your first membership plan</p>
              <button type="button" class="btn-add-plan" @click="openAddPlan">
                <i class="fas fa-plus"></i>
                <span>Add Your First Plan</span>
              </button>
            </div>
            <div v-else class="plans-grid">
              <div
                v-for="p in activePlansWithStats()"
                :key="String((p as Record<string, unknown>).id)"
                :class="['plan-card', (p as Record<string, unknown>).is_popular ? 'popular' : '', (p as Record<string, unknown>).is_deactivated ? 'deactivated' : '']"
              >
                <span v-if="(p as Record<string, unknown>).is_popular" class="popular-badge">POPULAR</span>
                <span v-if="(p as Record<string, unknown>).is_deactivated" class="popular-badge" style="right: auto; left: 1rem; background: var(--fg-danger); color: #fff;">DEACTIVATED</span>
                <h3>{{ (p as Record<string, unknown>).name }}</h3>
                <div class="plan-price">
                  <span class="price">{{ formatCurrency((p as Record<string, unknown>).price) }}</span>
                  <span class="period">{{ (p as Record<string, unknown>).period || 'per month' }}</span>
                </div>
                <p class="plan-description" :class="{ 'plan-empty-note': !(p as Record<string, unknown>).description }">{{ (p as Record<string, unknown>).description || 'No description added yet.' }}</p>
                <div class="plan-features">
                  <p>Features Included</p>
                  <ul>
                    <li v-for="(feature, fi) in planFeatures(p as Record<string, unknown>)" :key="fi"><i class="fas fa-check"></i> {{ feature }}</li>
                    <li v-if="planFeatures(p as Record<string, unknown>).length === 0" class="plan-empty-note">No features added yet. Use Edit Plan to list them.</li>
                  </ul>
                </div>
                <div class="plan-stats">
                  <p>{{ (p as Record<string, unknown>).member_count ?? 0 }} Members</p>
                  <p>Monthly Revenue <span class="revenue-badge">{{ formatCurrency((p as Record<string, unknown>).monthly_revenue) }}</span></p>
                </div>
                <div class="plan-actions">
                  <button type="button" class="btn-edit" @click="openEditPlan(p as Record<string, unknown>)">Edit Plan</button>
                  <button type="button" class="btn-deactivate" :disabled="formLoading" @click="deactivatePlan(p as Record<string, unknown>)">{{ (p as Record<string, unknown>).is_deactivated ? 'Reactivate' : 'Deactivate' }}</button>
                  <button type="button" class="btn-delete" :disabled="formLoading" @click="deletePlan(p as Record<string, unknown>)">Delete</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Schedule: the branch's real weekly classes (same as Admin > Classes; members book these) -->
          <div v-if="currentView === 'schedule'" class="space-y-6">
            <div class="flex flex-wrap justify-between items-center gap-4">
              <h2 class="sr-only">Schedule Calendar</h2>
              <button type="button" class="fg-btn rounded bg-purple-600 px-4 py-2 font-medium text-white hover:opacity-90" @click="openAddClassModal">
                Add Class
              </button>
            </div>
            <div class="flex flex-wrap items-center gap-2 mb-6 text-sm">
              <button type="button" :class="['px-4 py-2 font-medium rounded-md transition-colors', scheduleViewTab === 'Calendar' ? 'fg-tab-active' : 'bg-[#2A3042] text-gray-300 hover:bg-gray-700']" @click="scheduleViewTab = 'Calendar'">Calendar View</button>
              <button type="button" :class="['px-4 py-2 font-medium rounded-md transition-colors', scheduleViewTab === 'TrainerAvailability' ? 'fg-tab-active' : 'bg-[#2A3042] text-gray-300 hover:bg-gray-700']" @click="scheduleViewTab = 'TrainerAvailability'">Staff Availability</button>
              <button type="button" :class="['px-4 py-2 font-medium rounded-md transition-colors', scheduleViewTab === 'ClassManagement' ? 'fg-tab-active' : 'bg-[#2A3042] text-gray-300 hover:bg-gray-700']" @click="scheduleViewTab = 'ClassManagement'">Class Management</button>
              <label class="relative ml-auto flex items-center gap-2 text-gray-300">
                <span class="text-xs font-semibold text-[var(--fg-ink-soft)]">Week of</span>
                <input v-model="scheduleDate" type="date" class="rounded border border-gray-600 bg-[#2A3042] text-white px-4 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500" />
              </label>
            </div>
            <p v-if="scheduleError" class="rounded border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-300" role="alert">{{ scheduleError }}</p>
            <div class="rounded-lg bg-[#2A3042] p-6 shadow">
              <p v-if="scheduleLoading && scheduleClasses.length === 0" class="py-6 text-center text-gray-400">Loading classes...</p>
              <div v-else-if="scheduleClasses.length === 0" class="py-8 text-center text-gray-400">
                <p class="mb-3">No classes scheduled yet. Add one so members can book it.</p>
                <button type="button" class="fg-btn rounded bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:opacity-90" @click="openAddClassModal">Add Class</button>
              </div>
              <template v-else>
                <!-- Calendar View -->
                <div v-show="scheduleViewTab === 'Calendar'" class="overflow-x-auto">
                  <div class="grid text-white min-w-[800px]" style="grid-template-columns: auto repeat(7, 1fr);">
                    <div class="p-2 text-center font-semibold text-gray-400">Time</div>
                    <div v-for="d in SCHEDULE_DAYS" :key="d" class="p-2 text-center font-semibold">{{ d.slice(0, 3) }}</div>
                    <template v-for="row in scheduleCalendarRows" :key="row.time">
                      <div class="p-2 text-center font-semibold text-gray-400 border-t border-gray-700">{{ row.time }}</div>
                      <div v-for="cell in row.cells" :key="cell.day" class="border border-gray-700 p-1 min-h-[6rem] space-y-1">
                        <button
                          v-for="cls in cell.classes"
                          :key="cls.id"
                          type="button"
                          :class="['w-full text-left text-white rounded p-2 flex flex-col justify-between gap-1 text-xs hover:opacity-90', classIsFull(cls) ? 'bg-amber-600' : 'bg-blue-600']"
                          :title="'Edit ' + cls.name"
                          @click="openEditClassModal(cls)"
                        >
                          <span class="font-bold">{{ cls.name }}</span>
                          <span>{{ cls.trainer_name || 'TBA' }}</span>
                          <span class="flex justify-between"><span>{{ cls.start_time }}–{{ cls.end_time }}</span><span>{{ cls.booked_count }}/{{ cls.capacity }}</span></span>
                        </button>
                      </div>
                    </template>
                  </div>
                </div>
                <!-- Trainer Availability -->
                <div v-show="scheduleViewTab === 'TrainerAvailability'" class="overflow-x-auto">
                  <table class="w-full min-w-[800px] text-left text-gray-300">
                    <thead class="border-b border-gray-700">
                      <tr>
                        <th class="py-3 px-4">Staff</th>
                        <th v-for="d in SCHEDULE_DAYS" :key="d" class="py-3 px-4 text-center">{{ d.slice(0, 3) }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="staff in scheduleStaff" :key="staff.id" class="border-b border-gray-800">
                        <td class="py-4 px-4">
                          <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-purple-600 text-sm font-bold text-white">{{ staffInitials(staff.name) }}</span>
                            <span class="text-white font-medium">{{ staff.name }}</span>
                          </div>
                        </td>
                        <td v-for="day in SCHEDULE_DAYS" :key="day" class="py-4 px-2 align-top">
                          <div class="flex flex-col space-y-1">
                            <div v-for="cls in classesTaughtBy(staff.id, day)" :key="cls.id" class="availability-slot availability-slot--busy" :title="`Teaching ${cls.name} at ${cls.start_time}`">
                              {{ cls.start_time }} {{ cls.name }}
                            </div>
                            <div v-if="classesTaughtBy(staff.id, day).length === 0" class="availability-slot availability-slot--open" title="No class to teach this day">Available</div>
                          </div>
                        </td>
                      </tr>
                      <tr v-if="hasUnassignedClasses" class="border-b border-gray-800">
                        <td class="py-4 px-4 text-gray-400 italic">Unassigned (TBA)</td>
                        <td v-for="day in SCHEDULE_DAYS" :key="day" class="py-4 px-2 align-top">
                          <div class="flex flex-col space-y-1">
                            <div v-for="cls in classesTaughtBy(null, day)" :key="cls.id" class="px-2 py-1 text-xs rounded bg-yellow-500/20 text-yellow-300">
                              {{ cls.start_time }} {{ cls.name }}
                            </div>
                          </div>
                        </td>
                      </tr>
                      <tr v-if="scheduleStaff.length === 0">
                        <td :colspan="SCHEDULE_DAYS.length + 1" class="py-4 px-4 text-center text-gray-400">No staff accounts yet. The Admin can add them under Staff.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <!-- Class Management -->
                <div v-show="scheduleViewTab === 'ClassManagement'" class="overflow-x-auto">
                  <table class="w-full min-w-[720px] text-left text-gray-300">
                    <thead class="border-b border-gray-700">
                      <tr>
                        <th class="py-3 px-4">Class Name</th>
                        <th class="py-3 px-4">Staff</th>
                        <th class="py-3 px-4">Schedule</th>
                        <th class="py-3 px-4 text-center">Booked / Capacity</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-center">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="cls in scheduleClasses" :key="cls.id" class="border-b border-gray-800 hover:bg-gray-800/50">
                        <td class="py-4 px-4 font-medium text-white">{{ cls.name }}</td>
                        <td class="py-4 px-4">{{ cls.trainer_name || 'TBA' }}</td>
                        <td class="py-4 px-4">
                          <div class="text-sm">{{ cls.day_of_week }} <span class="text-xs text-gray-400">({{ formatClassDate(cls.class_date) }})</span></div>
                          <div class="text-xs text-gray-400">{{ cls.start_time }} - {{ cls.end_time }}</div>
                        </td>
                        <td class="py-4 px-4 text-center">{{ cls.booked_count }} / {{ cls.capacity }}</td>
                        <td class="py-4 px-4 text-center">
                          <span :class="['px-3 py-1 text-xs rounded-full', classIsFull(cls) ? 'bg-yellow-500/20 text-yellow-300' : 'bg-green-500/20 text-green-300']">{{ classIsFull(cls) ? 'Full' : 'Open' }}</span>
                        </td>
                        <td class="py-4 px-4">
                          <div class="flex justify-center gap-2">
                            <button type="button" class="fg-btn-sm px-3 py-1 text-xs font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700" @click="openEditClassModal(cls)">Edit</button>
                            <button type="button" class="fg-btn-sm px-3 py-1 text-xs font-medium text-white bg-red-600 rounded-md hover:bg-red-700" @click="deleteManagedClass(cls)">Delete</button>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </template>
            </div>
          </div>

          <!-- Profile -->
          <div v-if="currentView === 'profile'" class="space-y-6">
            <h2 class="sr-only">Staff Profile</h2>
            <div class="rounded-lg bg-[#2A3042] p-5 shadow sm:p-8">
              <div class="grid grid-cols-1 gap-8 md:grid-cols-3">
                <div class="flex flex-col items-center text-center md:col-span-1">
                  <ProfilePhotoPicker v-model:photo-url="userPhotoUrl" :initials="trainerInitials" class="mb-4" />
                  <h4 class="text-xl font-bold text-white">{{ profileForm.full_name || '-' }}</h4>
                  <p class="text-gray-400">{{ (profileTrainer as Record<string, unknown>)?.role || 'Staff' }}</p>
                </div>
                <div class="trainer-profile-form md:col-span-2">
                  <h4 class="mb-4 border-b border-gray-700 pb-2 text-lg font-semibold text-white">Personal Information</h4>
                  <div class="space-y-4">
                    <div>
                      <label class="block text-sm font-medium text-gray-400">Full Name</label>
                      <input v-model="profileForm.full_name" type="text" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" />
                    </div>
                    <div>
                      <label class="block text-sm font-medium text-gray-400">Email</label>
                      <input v-model="profileForm.email" type="email" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" readonly />
                    </div>
                    <div>
                      <label class="block text-sm font-medium text-gray-400">Phone</label>
                      <input v-model="profileForm.phone" type="tel" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" />
                    </div>
                  </div>
                  <p v-if="formError" class="mt-2 text-sm text-red-400">{{ formError }}</p>
                  <p v-else-if="profileNotice" class="mt-2 text-sm text-green-300" role="status">{{ profileNotice }}</p>
                  <div class="mt-6 flex flex-wrap gap-3 sm:gap-4">
                    <button type="button" class="fg-btn rounded bg-purple-600 px-4 py-2 font-medium text-white hover:opacity-90" :disabled="formLoading" @click="saveProfile">
                      {{ formLoading ? 'Saving...' : 'Save Profile' }}
                    </button>
                    <button type="button" class="fg-btn rounded bg-gray-600 px-4 py-2 font-medium text-white hover:bg-gray-500" @click="showPasswordModal = true">
                      Change Password
                    </button>
                    <button type="button" class="fg-btn rounded bg-red-600 px-4 py-2 font-medium text-white hover:bg-red-700" @click="handleLogout">
                      Logout
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </main>
      </div>
    </div>
    </div>

    <!-- Check-in Modal -->
    <div v-if="showCheckinModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="stopTrainerQrScanner(); showCheckinModal = false">
      <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg bg-[#2A3042] p-6 shadow-xl" @click.stop>
        <h3 class="mb-1 text-xl font-bold text-white">Record Attendance</h3>
        <p class="mb-4 text-sm text-gray-400">Scanning a member who is already inside checks them <b>out</b>.</p>
        <div class="mb-4 rounded-lg border border-gray-700 bg-[#1A2035] p-4">
          <div class="mb-3 flex items-center justify-between gap-3">
            <p class="text-sm font-semibold text-white">Option 1 · Scan the member's QR</p>
            <button
              type="button"
              class="fg-btn-sm rounded px-3 py-1 text-sm font-medium"
              :class="trainerQrScannerActive ? 'bg-red-400 text-white' : 'bg-[#A3E635] text-[var(--fg-accent-ink)]'"
              :disabled="formLoading || trainerQrScannerBusy"
              @click="trainerQrScannerActive ? stopTrainerQrScanner() : startTrainerQrScanner()"
            >
              {{ trainerQrScannerActive ? 'Stop Scan' : 'Start Scan' }}
            </button>
          </div>
          <div class="overflow-hidden rounded-lg bg-black">
            <div ref="trainerQrVideoRef" class="h-56 w-full" />
          </div>
          <p v-if="trainerQrScannerStatus" class="mt-3 text-sm text-[var(--fg-card-gold)]">{{ trainerQrScannerStatus }}</p>
          <p v-if="trainerQrScannerError" class="mt-2 text-sm text-red-400">{{ trainerQrScannerError }}</p>
          <textarea
            v-model="trainerQrManualQrPayload"
            class="mt-3 w-full rounded border border-gray-600 bg-[#12182A] px-3 py-2 text-sm text-white"
            rows="3"
            placeholder="Camera not working? Paste the member's backup code here (Member portal → Copy Backup Code)."
          />
          <button
            type="button"
            class="fg-btn mt-2 w-full rounded bg-blue-500 py-2 text-sm font-medium text-white hover:opacity-90"
            :disabled="formLoading || trainerQrScannerBusy"
            @click="submitTrainerQrPayload()"
          >
            Use Backup Code
          </button>
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-300">Option 2 · No phone? Choose the member</label>
          <select v-model="checkinForm.member_id" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white">
            <option value="">Select member</option>
            <option v-for="m in activeMembersForCheckin" :key="String((m as Record<string, unknown>).id)" :value="String((m as Record<string, unknown>).id)">
              {{ (m as Record<string, unknown>).full_name }} ({{ (m as Record<string, unknown>).member_id }})
            </option>
          </select>
        </div>
        <p v-if="formError" class="mb-2 text-sm text-red-400">{{ formError }}</p>
        <div class="flex gap-2">
          <button type="button" class="fg-btn flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="stopTrainerQrScanner(); showCheckinModal = false">Cancel</button>
          <button type="button" class="fg-btn flex-1 rounded bg-[#A3E635] py-2 font-semibold text-[var(--fg-accent-ink)] hover:opacity-90" :disabled="formLoading" @click="submitCheckin()">
            {{ formLoading ? 'Saving...' : 'Check-in' }}
          </button>
        </div>
      </div>
    </div>

    <!-- View Profile Modal -->
    <div v-if="showViewMemberModal && selectedMemberForView" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="showViewMemberModal = false">
      <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg bg-[#2A3042] p-6 shadow-xl" @click.stop>
        <h3 class="mb-4 text-xl font-bold text-white">Member Profile</h3>
        <div class="space-y-3 text-sm">
          <div class="flex items-center gap-4 mb-4">
            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-purple-500/40 text-xl font-bold text-white">
              {{ String(selectedMemberForView.full_name || '').slice(0, 2).toUpperCase() }}
            </div>
            <div class="min-w-0 [overflow-wrap:anywhere]">
              <p class="text-lg font-bold text-white">{{ selectedMemberForView.full_name }}</p>
              <p class="text-gray-400">{{ selectedMemberForView.email }}</p>
            </div>
          </div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Phone</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ selectedMemberForView.phone || '-' }}</span></div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Address</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ memberStreetAddress(selectedMemberForView.address) || '-' }}</span></div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Member ID</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ selectedMemberForView.member_id || '-' }}</span></div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Membership</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ selectedMemberForView.membership_plan || '-' }}</span></div>
          <div class="flex justify-between items-center">
            <span class="text-gray-400">Status</span>
            <span :class="['rounded px-2 py-1 text-xs font-medium', statusBadgeClass(selectedMemberForView.status, selectedMemberForView.expiry_date as string)]">
              {{ memberStatusLabel(selectedMemberForView.status, selectedMemberForView.expiry_date as string) }}
            </span>
          </div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Expires</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ selectedMemberForView.expiry_date || '-' }}</span></div>
          <div class="flex justify-between items-center">
            <span class="text-gray-400">Payment</span>
            <span :class="['rounded px-2 py-1 text-xs font-medium', paymentBadgeClass(selectedMemberForView.payment_status)]">
              {{ selectedMemberForView.payment_status || '-' }}
            </span>
          </div>
        </div>
        <div class="mt-6 flex gap-2">
          <button type="button" class="fg-btn flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showViewMemberModal = false">Close</button>
          <button type="button" class="fg-btn flex-1 rounded bg-gray-300 py-2 font-medium text-gray-800 hover:bg-gray-400" @click="editMember(selectedMemberForView); showViewMemberModal = false">Edit</button>
        </div>
      </div>
    </div>

    <!-- Add Member Modal -->
    <div v-if="showAddMemberModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="showAddMemberModal = false">
      <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg bg-[#2A3042] p-6 shadow-xl" @click.stop>
        <h3 class="mb-4 text-xl font-bold text-white">{{ selectedMemberForEdit ? 'Edit Member' : 'Add New Member' }}</h3>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-300">Full Name</label>
            <input v-model="memberForm.full_name" type="text" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="Full name" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Email</label>
            <input v-model="memberForm.email" type="email" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="Email" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Phone</label>
            <input v-model="memberForm.phone" type="text" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="Phone" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Address</label>
            <input v-model="memberForm.address" type="text" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="Address" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Membership Plan</label>
            <select v-model="memberForm.membership_plan_id" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white">
              <option value="">Select plan</option>
              <option v-for="p in plansList" :key="String((p as Record<string, unknown>).id)" :value="String((p as Record<string, unknown>).id)">
                {{ (p as Record<string, unknown>).name }}
              </option>
            </select>
          </div>
        </div>
        <p v-if="formError" class="mt-2 text-sm text-red-400">{{ formError }}</p>
        <div class="mt-4 flex gap-2">
          <button type="button" class="fg-btn flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showAddMemberModal = false">Cancel</button>
          <button type="button" class="fg-btn flex-1 rounded bg-[#A3E635] py-2 font-semibold text-[var(--fg-accent-ink)] hover:opacity-90" :disabled="formLoading" @click="submitAddMember">
            {{ formLoading ? 'Saving...' : (selectedMemberForEdit ? 'Update' : 'Add Member') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Add / Edit Plan Modal -->
    <div v-if="showAddPlanModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="showAddPlanModal = false">
      <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg bg-[#2A3042] p-6 shadow-xl" @click.stop>
        <h3 class="mb-4 text-xl font-bold text-white">{{ selectedPlanForEdit ? 'Edit Plan' : 'Add New Plan' }}</h3>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-300">Plan Name</label>
            <input v-model="planForm.name" type="text" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="Plan name" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Type</label>
            <select v-model="planForm.type" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white">
              <option>Monthly</option>
              <option>Quarterly</option>
              <option>Annual</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Price</label>
            <input v-model="planForm.price" type="number" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="0" step="0.01" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Period</label>
            <input v-model="planForm.period" type="text" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="e.g. per month" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Description</label>
            <textarea v-model="planForm.description" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" rows="2" placeholder="Description"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Features Included</label>
            <div class="mt-1 space-y-2">
              <div v-for="(_, fi) in planForm.features" :key="fi" class="flex gap-2">
                <input
                  v-model="planForm.features[fi]"
                  type="text"
                  maxlength="100"
                  class="w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white"
                  :placeholder="fi === 0 ? 'e.g. Gym Access' : 'Another feature'"
                  :aria-label="`Feature ${fi + 1}`"
                />
                <button type="button" class="shrink-0 rounded border border-gray-600 px-3 text-gray-300 hover:text-red-400" :aria-label="`Remove feature ${fi + 1}`" title="Remove" @click="removePlanFeature(fi)">&times;</button>
              </div>
            </div>
            <button v-if="planForm.features.length < MAX_PLAN_FEATURES" type="button" class="mt-2 text-sm font-semibold text-[var(--fg-accent-dark)] hover:underline" @click="addPlanFeature">+ Add feature</button>
          </div>
          <div class="flex items-center gap-2">
            <input v-model="planForm.is_popular" type="checkbox" id="trainer-plan-popular" class="rounded border-gray-600" />
            <label for="trainer-plan-popular" class="text-sm text-gray-300">Mark as popular</label>
          </div>
        </div>
        <p v-if="formError" class="mt-2 text-sm text-red-400">{{ formError }}</p>
        <div class="mt-4 flex gap-2">
          <button type="button" class="fg-btn flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showAddPlanModal = false">Cancel</button>
          <button type="button" class="fg-btn flex-1 rounded bg-[#A3E635] py-2 font-semibold text-[var(--fg-accent-ink)] hover:opacity-90" :disabled="formLoading" @click="submitAddPlan">
            {{ formLoading ? (selectedPlanForEdit ? 'Updating...' : 'Creating...') : (selectedPlanForEdit ? 'Update' : 'Create Plan') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Add / Edit Payment Modal -->
    <div v-if="showAddPaymentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="showAddPaymentModal = false">
      <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg bg-[#2A3042] p-6 shadow-xl" @click.stop>
        <h3 class="mb-4 text-xl font-bold text-white">{{ selectedPaymentForEdit ? 'Edit Payment' : 'Add Payment' }}</h3>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-300">Member *</label>
            <select v-model="paymentForm.member_id" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" required>
              <option value="">Select member</option>
              <option v-for="m in membersList" :key="String((m as Record<string, unknown>).id)" :value="String((m as Record<string, unknown>).id)">
                {{ (m as Record<string, unknown>).full_name }} ({{ (m as Record<string, unknown>).member_id }})
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Amount (PHP) *</label>
            <input v-model="paymentForm.amount" type="number" step="0.01" min="0" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="0.00" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Payment Date *</label>
            <input v-model="paymentForm.payment_date" type="date" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Payment Method *</label>
            <select v-model="paymentForm.payment_method" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white">
              <option value="Cash">Cash</option>
              <option value="GCash">GCash</option>
              <option value="Credit Card">Credit Card</option>
              <option value="Bank Transfer">Bank Transfer</option>
              <option value="Other">Other</option>
            </select>
          </div>
        </div>
        <p class="mt-2 text-xs text-gray-400">{{ selectedPaymentForEdit ? 'Status changes only through Approve/Reject on the payments list.' : 'New payments start as Pending — approve or reject them from the payments list once recorded.' }}</p>
        <p v-if="formError" class="mt-2 text-sm text-red-400">{{ formError }}</p>
        <div class="mt-4 flex gap-2">
          <button type="button" class="fg-btn flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showAddPaymentModal = false; selectedPaymentForEdit = null">Cancel</button>
          <button type="button" class="fg-btn flex-1 rounded bg-[#A3E635] py-2 font-semibold text-[var(--fg-accent-ink)] hover:opacity-90" :disabled="formLoading" @click="submitAddPayment">
            {{ formLoading ? 'Saving...' : (selectedPaymentForEdit ? 'Update' : 'Add Payment') }}
          </button>
        </div>
      </div>
    </div>

    <!-- View Payment Modal -->
    <div v-if="showViewPaymentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="showViewPaymentModal = false">
      <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg bg-[#2A3042] p-6 shadow-xl" @click.stop>
        <h3 class="mb-4 text-xl font-bold text-white">Payment Details</h3>
        <div v-if="selectedPaymentForView" class="space-y-3 text-gray-300">
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Invoice #</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ selectedPaymentForView.invoice_number }}</span></div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Member</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ selectedPaymentForView.member_name }}</span></div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Plan</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ selectedPaymentForView.plan_name || '-' }}</span></div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Amount</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">&#8369;{{ selectedPaymentForView.amount }}</span></div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Method</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ selectedPaymentForView.payment_method }}</span></div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Date</span><span class="min-w-0 text-right text-white [overflow-wrap:anywhere]">{{ selectedPaymentForView.payment_date }}</span></div>
          <div class="flex justify-between gap-4"><span class="shrink-0 text-gray-400">Status</span><span :class="['rounded px-2 py-1 text-xs font-medium', String(selectedPaymentForView.status).toLowerCase() === 'paid' ? 'bg-green-500/20 text-green-300' : 'bg-yellow-500/20 text-yellow-300']">{{ selectedPaymentForView.status }}</span></div>
        </div>
        <div class="mt-4 flex gap-2">
          <button type="button" class="fg-btn flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showViewPaymentModal = false">Close</button>
          <button type="button" class="fg-btn flex-1 rounded bg-green-600 py-2 text-white hover:bg-green-700" @click="showViewPaymentModal = false; selectedPaymentForView && editPayment(selectedPaymentForView)">Edit</button>
        </div>
      </div>
    </div>

    <!-- Schedule (Add/Edit Class) modal -->
    <div v-if="showScheduleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="showScheduleModal = false">
      <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg bg-[#2A3042] p-6 shadow-xl" @click.stop>
        <h3 class="mb-4 text-xl font-bold text-white">{{ selectedManagedClassForEdit !== null ? 'Edit Class' : 'Add Class' }}</h3>
        <div class="space-y-4">
          <div>
            <label for="class-name" class="block text-sm font-medium text-gray-300">Class Name</label>
            <input id="class-name" v-model="scheduleClassForm.name" type="text" maxlength="100" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="e.g. Morning Yoga" />
          </div>
          <div>
            <label for="class-trainer" class="block text-sm font-medium text-gray-300">Staff</label>
            <select id="class-trainer" v-model="scheduleClassForm.trainer_id" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white">
              <option value="">TBA / Unassigned</option>
              <option v-for="s in scheduleStaff" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
            </select>
          </div>
          <div v-if="selectedManagedClassForEdit !== null">
            <label for="class-day" class="block text-sm font-medium text-gray-300">Day</label>
            <select id="class-day" v-model="scheduleClassForm.days[0]" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white">
              <option v-for="d in SCHEDULE_DAYS" :key="d" :value="d">{{ d }}</option>
            </select>
          </div>
          <div v-else>
            <span class="block text-sm font-medium text-gray-300">Days</span>
            <p class="text-xs text-gray-400">A weekly class is added for each day you pick.</p>
            <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
              <label v-for="d in SCHEDULE_DAYS" :key="d" class="flex items-center gap-2 text-gray-300">
                <input :value="d" type="checkbox" class="rounded border-gray-600 bg-[#1A2035]" v-model="scheduleClassForm.days" />
                {{ d }}
              </label>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label for="class-start" class="block text-sm font-medium text-gray-300">Start Time</label>
              <input id="class-start" v-model="scheduleClassForm.start_time" type="time" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" />
            </div>
            <div>
              <label for="class-end" class="block text-sm font-medium text-gray-300">End Time</label>
              <input id="class-end" v-model="scheduleClassForm.end_time" type="time" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" />
            </div>
          </div>
          <div>
            <label for="class-capacity" class="block text-sm font-medium text-gray-300">Capacity</label>
            <input id="class-capacity" v-model="scheduleClassForm.capacity" type="number" min="1" max="200" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="10" />
          </div>
        </div>
        <p v-if="formError" class="mt-2 text-sm text-red-400" role="alert">{{ formError }}</p>
        <div class="mt-4 flex gap-2">
          <button type="button" class="fg-btn flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showScheduleModal = false">Cancel</button>
          <button type="button" class="fg-btn flex-1 rounded bg-[#A3E635] py-2 font-semibold text-[var(--fg-accent-ink)] hover:opacity-90 disabled:opacity-50" :disabled="formLoading" @click="submitManagedClass">
            {{ formLoading ? 'Saving...' : selectedManagedClassForEdit !== null ? 'Update Class' : 'Add Class' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Change Password Modal -->
    <div v-if="showPasswordModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="showPasswordModal = false">
      <div class="max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-lg bg-[#2A3042] p-6 shadow-xl" @click.stop>
        <h3 class="mb-4 text-xl font-bold text-white">Change Password</h3>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-300">Current Password</label>
            <div class="fg-password-field mt-1">
              <input v-model="passwordForm.current_password" :type="pw.type('current')" class="w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="Current password" autocomplete="current-password" />
              <PasswordToggle :shown="pw.isShown('current')" @toggle="pw.toggle('current')" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">New Password</label>
            <div class="fg-password-field mt-1">
              <input v-model="passwordForm.new_password" :type="pw.type('new')" class="w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="New password" autocomplete="new-password" />
              <PasswordToggle :shown="pw.isShown('new')" @toggle="pw.toggle('new')" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300">Confirm New Password</label>
            <div class="fg-password-field mt-1">
              <input v-model="passwordForm.new_password_confirmation" :type="pw.type('confirm')" class="w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white" placeholder="Confirm" autocomplete="new-password" />
              <PasswordToggle :shown="pw.isShown('confirm')" @toggle="pw.toggle('confirm')" />
            </div>
          </div>
        </div>
        <p v-if="formError" class="mt-2 text-sm text-red-400">{{ formError }}</p>
        <div class="mt-4 flex gap-2">
          <button type="button" class="fg-btn flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showPasswordModal = false">Cancel</button>
          <button type="button" class="fg-btn flex-1 rounded bg-[#A3E635] py-2 font-semibold text-[var(--fg-accent-ink)] hover:opacity-90" :disabled="formLoading" @click="submitChangePassword">
            {{ formLoading ? 'Changing...' : 'Change Password' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
.trainer-portal {
  /* Box colors come from the shared --fg-card-* tokens in app.css */
  --bg-primary: var(--fg-card);
  --bg-secondary: var(--fg-card-sidebar);
  --bg-card: var(--fg-card);
  --bg-input: var(--fg-card-2);
  --text-primary: var(--fg-card-ink);
  --text-secondary: var(--fg-card-ink-soft);
  --border-color: var(--fg-card-border);
  --accent-green: var(--fg-accent);
  --accent-blue: var(--fg-secondary);
  --accent-purple: var(--fg-accent);
  --accent-purple-light: var(--fg-accent);
  --accent-pink: var(--fg-danger);
  --accent-yellow: var(--fg-accent);
  /* the page background is the shared .fg-marble-bg */
}

.trainer-auth-shell {
  background:
    radial-gradient(circle at top, rgba(var(--fg-accent-rgb), 0.18), transparent 28%),
    radial-gradient(circle at bottom right, rgba(var(--fg-accent-dark-rgb), 0.1), transparent 26%),
    transparent;
}

.trainer-auth-card {
  background: var(--bg-card);
  border-color: var(--border-color);
  box-shadow: none;
}

.trainer-auth-eyebrow {
  color: var(--fg-card-gold);
  font-size: 0.85rem;
  font-weight: 700;
  letter-spacing: 0.24em;
  margin-bottom: 0.75rem;
  text-transform: uppercase;
}

.trainer-auth-title {
  color: var(--text-primary);
  font-size: clamp(2rem, 5vw, 2.5rem);
  font-weight: 700;
}

.trainer-auth-subtitle {
  color: var(--text-secondary);
  margin-top: 0.85rem;
}

.trainer-auth-role-badge {
  background: rgba(var(--fg-accent-rgb), 0.16);
  border: 1px solid rgba(var(--fg-accent-rgb), 0.28);
  border-radius: 999px;
  color: var(--fg-card-gold);
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  margin: 1.75rem auto 1.5rem;
  padding: 0.65rem 1rem;
  text-align: center;
  text-transform: uppercase;
  width: fit-content;
}

.trainer-auth-link {
  color: var(--fg-card-gold);
}

.trainer-auth-error {
  background: rgba(var(--fg-danger-rgb), 0.12);
  border: 1px solid rgba(var(--fg-danger-rgb), 0.45);
  border-radius: 0.75rem;
  color: var(--fg-danger-text);
  padding: 0.75rem 0.9rem;
}

.trainer-auth-primary {
  background: var(--accent-purple);
  color: var(--fg-accent-ink);
}

.trainer-auth-primary:hover:not(:disabled) {
  filter: brightness(1.05);
}

.trainer-shell {
  background: var(--bg-primary);
  border-color: rgba(var(--fg-accent-deep-rgb), 0.35);
}

.trainer-sidebar {
  background:
    linear-gradient(180deg, rgba(var(--fg-accent-rgb), 0.07) 0%, rgba(var(--fg-accent-rgb), 0) 45%),
    var(--bg-secondary) !important;
  border-right: 1px solid var(--border-color);
}

.trainer-profile-card,
.trainer-header-card {
  background: var(--bg-card);
  box-shadow: 0 0 0 1px var(--border-color);
  border-color: var(--border-color);
}

.trainer-avatar {
  background: var(--accent-purple);
  color: var(--fg-accent-ink);
}

.trainer-nav-item {
  background: transparent;
  border: 1px solid transparent;
  color: var(--text-primary);
}

.trainer-nav-item:hover {
  background: rgba(var(--fg-accent-rgb), 0.1);
  border-color: rgba(var(--fg-accent-rgb), 0.3);
}

.trainer-nav-item.active {
  background: rgba(var(--fg-accent-rgb), 0.92);
  box-shadow: none;
  color: var(--fg-accent-ink);
}

.trainer-logout-btn {
  background: var(--accent-pink);
  color: #fff;
}

.trainer-header {
  background: transparent;
  border-color: rgba(var(--fg-accent-deep-rgb), 0.3);
}

.trainer-main {
  background: transparent !important;
}

.trainer-portal .bg-\[\#1A2035\] {
  background: var(--bg-primary) !important;
}

/* the content column shows the marble shell behind it */
.trainer-portal .flex.flex-1.flex-col.overflow-hidden {
  background: transparent !important;
}

.trainer-portal .bg-\[\#1e293b\] {
  background: var(--bg-secondary) !important;
}

.trainer-portal .bg-\[\#2A3042\] {
  background: var(--bg-card) !important;
}

/* light cards get a hairline ring and soft shadow so they stand off the marble */
.trainer-portal .bg-\[\#2A3042\].rounded-lg,
.trainer-portal .bg-\[\#2A3042\].rounded-xl {
  box-shadow: 0 0 0 1px var(--border-color);
}

.trainer-portal .text-gray-300,
.trainer-portal .text-gray-400,
.trainer-portal .text-gray-500 {
  color: var(--text-secondary) !important;
}

.trainer-portal .border-gray-600,
.trainer-portal .border-gray-700,
.trainer-portal .border-gray-800\/70,
.trainer-portal .divide-gray-700 > * + * {
  border-color: var(--border-color) !important;
}

.trainer-portal .bg-purple-600,
.trainer-portal .bg-purple-500 {
  background: var(--accent-purple) !important;
  color: var(--fg-accent-ink) !important;
}

.trainer-portal .hover\:bg-purple-700:hover {
  background: var(--accent-purple-light) !important;
  color: var(--fg-accent-ink) !important;
}

.trainer-portal .bg-blue-500,
.trainer-portal .bg-blue-600 {
  background: var(--accent-blue) !important;
  color: #fff !important;
}

.trainer-portal .bg-blue-500\/20 {
  background: color-mix(in srgb, var(--accent-blue) 20%, transparent) !important;
}

.trainer-portal .hover\:bg-blue-700:hover {
  filter: brightness(0.92);
}

.trainer-portal .bg-green-600,
.trainer-portal .bg-green-700 {
  background: var(--accent-green) !important;
  color: var(--fg-accent-ink) !important;
}

.trainer-portal .bg-green-500 {
  background: var(--accent-green) !important;
  color: var(--fg-accent-ink) !important;
}

.trainer-portal .bg-green-500\/20 {
  background: rgba(var(--fg-success-rgb), 0.18) !important;
}

.trainer-portal .bg-red-400,
.trainer-portal .bg-red-500,
.trainer-portal .bg-red-600,
.trainer-portal .bg-red-700 {
  background: var(--accent-pink) !important;
  color: #fff !important;
}

.trainer-portal .bg-red-500\/20 {
  background: rgba(var(--fg-danger-rgb), 0.2) !important;
}

.trainer-portal .bg-red-500\/10 {
  background: rgba(var(--fg-danger-rgb), 0.12) !important;
}

.trainer-portal .border-red-500\/40 {
  border-color: rgba(var(--fg-danger-rgb), 0.45) !important;
}

/* amber / yellow fills mark "full" or "ending soon": the warning color */
.trainer-portal .bg-amber-400,
.trainer-portal .bg-amber-600,
.trainer-portal .bg-amber-700,
.trainer-portal .bg-yellow-500 {
  background: var(--fg-warning) !important;
  color: var(--fg-accent-ink) !important;
}

.trainer-portal .bg-yellow-500\/20 {
  background: rgba(var(--fg-warning-rgb), 0.2) !important;
}

.trainer-portal .bg-purple-500\/40 {
  background: color-mix(in srgb, var(--accent-purple) 40%, transparent) !important;
}

.trainer-portal .text-amber-900 {
  color: #000 !important;
}

.trainer-portal .text-green-300 {
  color: var(--fg-success-text) !important;
}

.trainer-portal .text-blue-300 {
  color: var(--fg-card-gold) !important;
}

.trainer-portal .text-red-300,
.trainer-portal .text-red-400,
.trainer-portal .hover\:text-red-400:hover {
  color: var(--fg-danger-text) !important;
}

.trainer-portal .text-yellow-300 {
  color: var(--fg-warning-text) !important;
}

.trainer-portal .bg-gray-300 {
  background: var(--accent-purple-light) !important;
  color: var(--fg-accent-ink) !important;
}

.trainer-portal .bg-gray-600,
.trainer-portal .bg-gray-700 {
  background: var(--bg-input) !important;
}

.trainer-portal .bg-gray-500\/20 {
  background: rgba(var(--fg-neutral-rgb), 0.18) !important;
}

.trainer-portal .ring-purple-500,
.trainer-portal .focus\:ring-purple-500:focus,
.trainer-portal .focus\:ring-\[\#A3E635\]:focus,
.trainer-portal .focus\:ring-1:focus {
  --tw-ring-color: var(--accent-purple) !important;
}

.trainer-portal .focus\:border-\[\#A3E635\]:focus {
  border-color: var(--accent-purple) !important;
}

.trainer-portal .bg-gray-900\/50 {
  background: color-mix(in srgb, var(--bg-input) 70%, transparent) !important;
}

.trainer-portal .bg-\[\#A3E635\] {
  background: var(--accent-green) !important;
  color: var(--fg-accent-ink) !important;
}

.trainer-portal .hover\:bg-gray-700:hover {
  background: color-mix(in srgb, var(--bg-input) 82%, #fff 18%) !important;
}

.trainer-portal .hover\:bg-gray-500:hover {
  background: color-mix(in srgb, var(--bg-input) 78%, #fff 22%) !important;
}

.trainer-portal .hover\:bg-red-700:hover {
  filter: brightness(0.95);
}

.trainer-portal .bg-white {
  background: var(--bg-card) !important;
}

.trainer-portal .text-gray-800 {
  color: #fff !important;
}

.trainer-portal .border-gray-200 {
  border-color: var(--border-color) !important;
}

.trainer-portal aside {
  background: var(--bg-secondary) !important;
  border-right: 1px solid var(--border-color);
}

.trainer-portal header span {
  color: var(--text-primary) !important;
}

.trainer-portal .text-white {
  color: var(--text-primary) !important;
}

/* text on the gold fills is dark; on the bronze and red fills it stays white */
.trainer-portal :is(.bg-purple-500, .bg-purple-600, .bg-green-500, .bg-green-600, .bg-yellow-500, .bg-gray-300, .trainer-nav-item.active, .nav-item.bg-purple-600).text-white,
.trainer-portal :is(.bg-purple-500, .bg-purple-600, .bg-green-500, .bg-green-600, .bg-yellow-500, .trainer-nav-item.active) .text-white {
  color: var(--fg-accent-ink) !important;
}

.trainer-portal :is(.bg-blue-500, .bg-blue-600, .bg-red-400, .bg-red-500, .bg-red-600, .bg-red-700).text-white,
.trainer-portal :is(.bg-blue-500, .bg-blue-600, .bg-red-600, .bg-red-700) .text-white {
  color: #fff !important;
}

.trainer-portal input,
.trainer-portal select,
.trainer-portal textarea {
  border-color: var(--border-color) !important;
  background: var(--bg-input);
  color: var(--text-primary);
}

.trainer-portal .nav-item {
  margin-bottom: 0.4rem;
  padding: 0.75rem 0.9375rem !important;
  font-size: clamp(0.75rem, 1vw, 0.875rem) !important;
  border-radius: 0.5rem !important;
}

.trainer-portal .nav-item.bg-purple-600 {
  background: var(--accent-green) !important;
  color: var(--fg-accent-ink);
  font-weight: 600 !important;
}

.trainer-portal .trainer-page-title {
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: 700;
}

/* Sizes come from the shared UI standard in app.css (--ui-* tokens) */
.trainer-portal table:not(.data-table) th {
  height: var(--ui-th-h) !important;
  font-size: var(--ui-fs-table-head) !important;
  font-weight: 600 !important;
  padding: var(--ui-space-sm) var(--ui-cell-px) !important;
}

.trainer-portal table:not(.data-table) td {
  height: var(--ui-tr-h) !important;
  font-size: var(--ui-fs-table) !important;
  padding: var(--ui-space-sm) var(--ui-cell-px) !important;
}

.trainer-portal .bg-\[\#2A3042\].p-6 {
  padding: var(--ui-card-pad) !important;
  border-radius: var(--ui-card-radius) !important;
}

.trainer-portal .text-lg.font-semibold.text-white {
  font-size: var(--ui-fs-card-title) !important;
}

.trainer-portal .w-full.rounded-lg.bg-blue-500,
.trainer-portal .w-full.rounded-lg.bg-purple-600,
.trainer-portal .w-full.rounded-lg.bg-gray-600 {
  font-size: var(--ui-fs-button) !important;
}

@media (max-width: 1023.98px) {
  /* clip (not hidden) so the sticky mobile bar still sticks to the viewport */
  .trainer-content {
    overflow: clip;
  }
}

/* 16px inputs stop iOS Safari from zooming the page on focus */
@media (max-width: 767.98px) {
  .trainer-portal input:not([type='checkbox']),
  .trainer-portal select,
  .trainer-portal textarea {
    font-size: 1rem;
  }
}


/* Same typeface as the Admin portal for the shared Members / Plans views */
.trainer-portal .staff-admin-view {
  font-family: var(--fg-font);
}

/* ===== Members / Membership Plans: copied from Admin.vue so both portals look the same.
   Scoped to .staff-admin-view; regenerate from Admin.vue rather than editing by hand. ===== */
.trainer-portal .staff-admin-view .page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.875rem;
}

.trainer-portal .staff-admin-view .page-header h2 {
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: bold;
  color: var(--fg-ink);
}

.trainer-portal .staff-admin-view .page-controls {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.9375rem;
  margin-bottom: 1.5625rem;
}

.trainer-portal .staff-admin-view .page-controls .search-bar {
  flex: 1;
  min-width: 0;
  position: relative;
  display: flex;
  align-items: center;
}

.trainer-portal .staff-admin-view .page-controls .search-bar i {
  position: absolute;
  left: 0.9375rem;
  color: var(--text-secondary);
  pointer-events: none;
}

.trainer-portal .staff-admin-view .page-controls .search-bar input {
  width: 100%;
  padding: 0.75rem 0.9375rem 0.75rem 2.5rem;
  background: var(--bg-input);
  border: 1px solid var(--border-color);
  border-radius: 0.5rem;
  color: var(--text-primary);
  font-size: clamp(0.75rem, 1vw, 0.875rem);
}

.trainer-portal .staff-admin-view .page-controls .filter-dropdown {
  padding: 0.75rem 0.9375rem;
  background: var(--bg-input);
  border: 1px solid var(--border-color);
  border-radius: 0.5rem;
  color: var(--text-primary);
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  cursor: pointer;
  min-width: 9.375rem;
  flex-shrink: 0;
}

.trainer-portal .staff-admin-view .page-controls .btn-add-member {
  flex-shrink: 0;
}

.trainer-portal .staff-admin-view .table-container {
  background: var(--bg-card);
  border-radius: 0.75rem;
  overflow-x: auto;
  width: 100%;
  box-shadow: 0 0 0 1px var(--border-color);
}

.trainer-portal .staff-admin-view .data-table {
  width: 100%;
  min-width: 42rem;
  border-collapse: collapse;
}

.trainer-portal .staff-admin-view .data-table thead {
  background: var(--bg-input);
}

.trainer-portal .staff-admin-view .data-table th {
  padding: clamp(0.5rem, 1vw, 0.625rem) clamp(0.625rem, 1.2vw, 0.75rem);
  text-align: left;
  font-size: 0.88rem;
  font-weight: 600;
  color: var(--text-primary);
  border-bottom: 1px solid var(--border-color);
  white-space: nowrap;
}

.trainer-portal .staff-admin-view .data-table td {
  padding: clamp(0.4rem, 0.8vw, 0.5625rem) clamp(0.625rem, 1.2vw, 0.75rem);
  border-bottom: 1px solid var(--border-color);
  font-size: 0.88rem;
  color: var(--text-primary);
}

.trainer-portal .staff-admin-view .member-cell {
  display: flex;
  align-items: center;
  gap: clamp(0.4rem, 0.8vw, 0.625rem);
}

.trainer-portal .staff-admin-view .member-avatar {
  width: clamp(1.65rem, 2.4vw, 2rem);
  height: clamp(1.65rem, 2.4vw, 2rem);
  min-width: clamp(1.65rem, 2.4vw, 2rem);
  border-radius: 50%;
  background: var(--accent-purple);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: 0.75rem;
  color: var(--fg-accent-ink);
  flex-shrink: 0;
}

.trainer-portal .staff-admin-view .member-cell p {
  font-size: 0.88rem;
  color: var(--text-primary);
  margin-bottom: 0.0625rem;
  line-height: 1.25;
}

.trainer-portal .staff-admin-view .member-cell span {
  font-size: 0.75rem;
  color: var(--text-secondary);
  line-height: 1.25;
}

.trainer-portal .staff-admin-view .status-badge {
  padding: clamp(0.2rem, 0.5vw, 0.25rem) clamp(0.5rem, 1vw, 0.75rem);
  border-radius: 0.75rem;
  font-size: 0.75rem;
  font-weight: 600;
  display: inline-block;
}

.trainer-portal .staff-admin-view .status-badge.active {
  background: var(--accent-green);
  color: var(--fg-accent-ink);
}

.trainer-portal .staff-admin-view .status-badge.inactive {
  background: var(--accent-pink);
  color: #fff;
}

.trainer-portal .staff-admin-view .empty-row {
  text-align: center;
  padding: 2rem;
  color: var(--text-secondary);
}

.trainer-portal .staff-admin-view .plans-summary {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.25rem;
  margin-bottom: 1.875rem;
}

.trainer-portal .staff-admin-view .summary-card {
  background: var(--bg-card);
  padding: 1.25rem;
  border-radius: 0.75rem;
  display: flex;
  align-items: center;
  gap: 0.9375rem;
  box-shadow: 0 0 0 1px var(--border-color);
}

.trainer-portal .staff-admin-view .summary-card i {
  font-size: clamp(1.5rem, 3vw, 2rem);
  color: var(--fg-card-gold);
}

.trainer-portal .staff-admin-view .summary-card h3 {
  font-size: clamp(1.125rem, 2.5vw, 1.5rem);
  font-weight: bold;
  margin-bottom: 0.3125rem;
  color: var(--text-primary);
}

.trainer-portal .staff-admin-view .summary-card p {
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
  color: var(--text-secondary);
}

.trainer-portal .staff-admin-view .btn-add-plan {
  padding: 0.75rem 1.25rem;
  background: var(--accent-green);
  color: var(--fg-accent-ink);
  border: none;
  border-radius: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.trainer-portal .staff-admin-view .plans-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(18rem, 100%), 1fr));
  gap: 1.25rem;
}

.trainer-portal .staff-admin-view .plan-card {
  background: var(--bg-card);
  padding: 1.5625rem;
  border-radius: 0.75rem;
  position: relative;
  box-shadow: 0 0 0 1px var(--border-color);
}

.trainer-portal .staff-admin-view .plan-card.deactivated {
  opacity: 0.85;
  border: 1px dashed var(--fg-danger);
}

.trainer-portal .staff-admin-view .plan-card.popular .popular-badge {
  position: absolute;
  top: 0.9375rem;
  right: 0.9375rem;
  background: var(--accent-yellow);
  color: var(--fg-accent-ink);
  padding: 0.25rem 0.75rem;
  border-radius: 0.75rem;
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
  font-weight: bold;
}

.trainer-portal .staff-admin-view .plan-card h3 {
  font-size: clamp(1.125rem, 2.5vw, 1.5rem);
  margin-bottom: 0.9375rem;
  color: var(--text-primary);
}

.trainer-portal .staff-admin-view .plan-price {
  margin-bottom: 0.9375rem;
}

.trainer-portal .staff-admin-view .plan-price .price {
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: bold;
  display: block;
  color: var(--text-primary);
}

.trainer-portal .staff-admin-view .plan-price .period {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-secondary);
}

.trainer-portal .staff-admin-view .plan-description {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-secondary);
  margin-bottom: 1.25rem;
  line-height: 1.5;
}

.trainer-portal .staff-admin-view .plan-features {
  margin-bottom: 1.25rem;
}

.trainer-portal .staff-admin-view .plan-features > p {
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
  color: var(--text-secondary);
  margin-bottom: 0.75rem;
  text-transform: uppercase;
}

.trainer-portal .staff-admin-view .plan-features ul {
  list-style: none;
  padding: 0;
  margin: 0;
}

.trainer-portal .staff-admin-view .plan-empty-note {
  color: var(--text-secondary);
  font-style: italic;
}

.trainer-portal .staff-admin-view .plan-features li {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  margin-bottom: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-primary);
}

.trainer-portal .staff-admin-view .plan-features li i {
  color: var(--fg-card-gold);
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
}

.trainer-portal .staff-admin-view .plan-stats {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.25rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--border-color);
}

.trainer-portal .staff-admin-view .plan-stats p {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-primary);
}

.trainer-portal .staff-admin-view .plan-stats .revenue-badge {
  background: var(--accent-yellow);
  color: var(--fg-accent-ink);
  padding: 0.25rem 0.625rem;
  border-radius: 0.5rem;
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
  font-weight: 600;
  margin-left: 0.5rem;
}

.trainer-portal .staff-admin-view .plan-actions {
  display: flex;
  gap: 0.625rem;
}

/* Edit Plan, Deactivate and Delete share one gold, same as in the Admin portal */
.trainer-portal .staff-admin-view .plan-actions :is(.btn-edit, .btn-deactivate, .btn-delete) {
  flex: 1;
  padding: 0.625rem;
  background: var(--fg-accent);
  color: var(--fg-accent-ink);
  border: 1px solid var(--fg-accent-light);
  border-radius: 0.375rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  font-weight: 700;
  cursor: pointer;
}

.trainer-portal .staff-admin-view .plan-actions :is(.btn-edit, .btn-deactivate, .btn-delete):hover {
  filter: brightness(1.06);
}

.trainer-portal .staff-admin-view .plan-actions :is(.btn-edit, .btn-deactivate, .btn-delete):disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.trainer-portal .staff-admin-view .btn-add-member {
  padding: 0.75rem 1.25rem;
  background: var(--accent-green);
  color: var(--fg-accent-ink);
  border: none;
  border-radius: 0.75rem;
  font-size: clamp(0.8125rem, 1vw, 0.9375rem);
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  box-shadow: none;
}

.trainer-portal .staff-admin-view .btn-add-member:hover {
  filter: brightness(1.1);
}

.trainer-portal .staff-admin-view .attendance-filters .search-bar,
.trainer-portal .staff-admin-view .attendance-filters .search-bar input {
  color: var(--fg-ink);
}

.trainer-portal .staff-admin-view .attendance-filters .search-bar input::placeholder {
  color: var(--fg-ink-soft);
}

.trainer-portal .staff-admin-view .action-buttons {
  /* two icons per row keeps the Actions column narrow, so the table fits without scrolling */
  display: grid;
  grid-template-columns: repeat(2, 1.625rem);
  gap: 0.375rem;
}

.trainer-portal .staff-admin-view .action-btn {
  width: 1.625rem;
  height: 1.625rem;
  min-width: 1.625rem;
  border: none;
  border-radius: 0.375rem;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 0.7rem;
  flex-shrink: 0;
}

.trainer-portal .staff-admin-view .action-btn.blue {
  background: var(--accent-blue);
  color: #fff;
}

.trainer-portal .staff-admin-view .action-btn.purple {
  background: var(--accent-purple);
  color: var(--fg-accent-ink);
}

.trainer-portal .staff-admin-view .action-btn.red {
  background: var(--accent-pink);
  color: #fff;
}

.trainer-portal .staff-admin-view .action-btn.green {
  background: var(--accent-green);
  color: var(--fg-accent-ink);
}

.trainer-portal .staff-admin-view .action-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.trainer-portal .staff-admin-view .status-badge.paid {
  background: var(--accent-green);
  color: var(--fg-accent-ink);
}

.trainer-portal .staff-admin-view .status-badge.pending {
  background: var(--accent-yellow);
  color: var(--fg-accent-ink);
}

.trainer-portal .staff-admin-view .status-badge.cancelled {
  background: var(--accent-pink);
  color: #fff;
}

.trainer-portal .staff-admin-view .status-badge.overdue {
  background: var(--accent-blue);
  color: #fff;
}

.trainer-portal .staff-admin-view .header-actions {
  display: flex;
  gap: 0.75rem;
  align-items: center;
}

.trainer-portal .staff-admin-view .empty-state {
  text-align: center;
  padding: 4rem 2rem;
  color: var(--fg-ink-soft);
}

.trainer-portal .staff-admin-view .empty-state i {
  font-size: 4rem;
  color: var(--fg-ink-soft);
  margin-bottom: 1rem;
  opacity: 0.5;
}

.trainer-portal .staff-admin-view .empty-state h3 {
  font-size: clamp(1.25rem, 2.5vw, 1.5rem);
  margin-bottom: 0.5rem;
  color: var(--fg-ink);
}

.trainer-portal .staff-admin-view .empty-state p {
  font-size: clamp(0.875rem, 1.2vw, 1rem);
  margin-bottom: 1.5rem;
  color: var(--fg-ink-soft);
}

.trainer-portal .staff-admin-view .empty-state .btn-add-plan {
  margin-top: 0.5rem;
}

@media (max-width: 1279.98px) {
  .trainer-portal .staff-admin-view .plans-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 767.98px) {
  .trainer-portal .staff-admin-view .page-header {
    flex-wrap: wrap;
    gap: 0.75rem;
  }

  .trainer-portal .staff-admin-view .page-header .header-actions,
  .trainer-portal .staff-admin-view .plan-actions {
    flex-wrap: wrap;
  }

  .trainer-portal .staff-admin-view .page-controls {
    flex-wrap: wrap;
    gap: 0.75rem;
  }

  .trainer-portal .staff-admin-view .page-controls .search-bar {
    flex: 1 1 100%;
  }

  .trainer-portal .staff-admin-view .page-controls .filter-dropdown {
    flex: 1 1 9rem;
    min-width: 0;
  }

  .trainer-portal .staff-admin-view .page-controls .btn-add-member {
    flex: 1 1 100%;
    justify-content: center;
  }

  .trainer-portal .staff-admin-view .attendance-filters .search-bar {
    flex: 1 1 14rem;
    min-width: 0;
  }

  .trainer-portal .staff-admin-view .attendance-filters .search-bar input {
    width: 100%;
  }

  .trainer-portal .staff-admin-view .plan-stats {
    flex-wrap: wrap;
    gap: 0.5rem;
  }

  .trainer-portal .staff-admin-view .plan-card {
    padding: 1.25rem;
  }

  .trainer-portal .staff-admin-view .empty-state {
    padding: 2.5rem 1rem;
  }

  .trainer-portal .staff-admin-view .data-table th,
  .trainer-portal .staff-admin-view .data-table td,
  .trainer-portal .staff-admin-view .member-cell p {
    font-size: 0.8125rem;
  }

  .trainer-portal .staff-admin-view .member-cell span,
  .trainer-portal .staff-admin-view .status-badge {
    font-size: 0.75rem;
  }

  .trainer-portal .staff-admin-view .page-controls .search-bar input,
  .trainer-portal .staff-admin-view .page-controls .filter-dropdown {
    font-size: 1rem;
  }
}

@media (max-width: 479.98px) {
  .trainer-portal .staff-admin-view .plans-summary {
    grid-template-columns: 1fr;
  }
}

@media (pointer: coarse) {
  .trainer-portal .staff-admin-view .action-btn {
    width: auto;
    min-width: 2.5rem;
    height: 2.5rem;
    padding: 0 0.5rem;
    font-size: 0.875rem;
  }
}

/* Staff > Attendance */
.trainer-portal .staff-attendance .attendance-steps {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
  gap: 1rem;
  margin: 0 0 1.5rem;
  padding: 0;
  list-style: none;
}

.trainer-portal .staff-attendance .attendance-steps li {
  display: flex;
  gap: 0.85rem;
  align-items: flex-start;
  border-radius: 0.85rem;
  padding: 1rem;
}

.trainer-portal .staff-attendance .attendance-step-no {
  display: grid;
  flex-shrink: 0;
  width: 2rem;
  height: 2rem;
  place-items: center;
  border-radius: 9999px;
  background: var(--fg-accent);
  color: var(--fg-accent-ink);
  font-weight: 700;
}

.trainer-portal .staff-attendance .attendance-step-title {
  margin: 0;
  font-weight: 700;
  color: var(--fg-card-ink);
}

.trainer-portal .staff-attendance .attendance-step-text {
  margin: 0.2rem 0 0;
  font-size: 0.85rem;
  line-height: 1.45;
  color: var(--fg-card-ink-soft);
}

.trainer-portal .staff-attendance .attendance-panel {
  margin-bottom: 1.5rem;
  padding: 1.25rem;
}

.trainer-portal .staff-attendance .attendance-panel-head {
  margin-bottom: 1rem;
}

.trainer-portal .staff-attendance .attendance-panel-head--row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
}

.trainer-portal .staff-attendance .attendance-panel-head h3 {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0;
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--text-primary);
}

.trainer-portal .staff-attendance .attendance-panel-head p {
  margin: 0.25rem 0 0;
  font-size: 0.85rem;
  color: var(--text-secondary);
}

.trainer-portal .staff-attendance .attendance-count {
  border-radius: 9999px;
  background: var(--fg-accent);
  padding: 0.1rem 0.6rem;
  font-size: 0.8rem;
  color: var(--fg-accent-ink);
}

.trainer-portal .staff-attendance .attendance-search {
  position: relative;
  display: flex;
  align-items: center;
  width: min(100%, 20rem);
}

.trainer-portal .staff-attendance .attendance-search i {
  position: absolute;
  left: 0.85rem;
  color: var(--text-secondary);
  pointer-events: none;
}

.trainer-portal .staff-attendance .attendance-search input {
  width: 100%;
  border: 1px solid var(--border-color);
  border-radius: 0.5rem;
  background: var(--bg-input);
  padding: 0.6rem 0.85rem 0.6rem 2.4rem;
  color: var(--text-primary);
}

.trainer-portal .staff-attendance .attendance-inside-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));
  gap: 0.85rem;
}

.trainer-portal .staff-attendance .attendance-inside-card {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border-radius: 0.75rem;
  padding: 0.85rem;
}

.trainer-portal .staff-attendance .attendance-inside-info {
  min-width: 0;
  flex: 1;
}

.trainer-portal .staff-attendance .attendance-inside-info p {
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 600;
  color: var(--fg-card-ink);
}

.trainer-portal .staff-attendance .attendance-inside-info span {
  font-size: 0.8rem;
  color: var(--fg-card-ink-soft);
}

.trainer-portal .staff-attendance .attendance-checkout-btn {
  flex-shrink: 0;
  border: 0;
  border-radius: 0.5rem;
  background: var(--fg-danger);
  padding: 0.45rem 0.8rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--fg-status-ink);
  cursor: pointer;
}

.trainer-portal .staff-attendance .attendance-checkout-btn:disabled {
  opacity: 0.6;
  cursor: wait;
}

.trainer-portal .staff-attendance .attendance-empty {
  margin: 0;
  padding: 1.25rem 0;
  text-align: center;
  color: var(--text-secondary);
}

.trainer-portal .staff-attendance .attendance-error {
  margin: 0.75rem 0 0;
  color: var(--fg-danger-text);
  font-size: 0.85rem;
}

/* Staff > Schedule > Trainer Availability: open days are black-and-gold, teaching days solid gold */
.trainer-portal .availability-slot {
  border-radius: 0.375rem;
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 600;
}

.trainer-portal .availability-slot--open {
  border: 1px solid rgba(var(--fg-accent-rgb), 0.45);
  background: rgba(20, 17, 12, 0.72);
  box-shadow: none;
  color: var(--fg-accent-light);
}

.trainer-portal .availability-slot--busy {
  border: 1px solid var(--fg-accent-light);
  background: var(--fg-accent);
  color: var(--fg-accent-ink);
}
</style>
