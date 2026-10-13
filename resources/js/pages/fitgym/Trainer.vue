<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Menu, X } from 'lucide-vue-next';
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
import PasswordToggle from '@/components/PasswordToggle.vue';
import ProfilePhotoPicker from '@/components/ProfilePhotoPicker.vue';
import { useMobileNav } from '@/composables/useMobileNav';
import { usePasswordVisibility } from '@/composables/usePasswordVisibility';
import { useQrCheckinScanner } from '@/composables/useQrCheckinScanner';
import { useSessionGuard } from '@/composables/useSessionGuard';
import { mergeMemberStreetAddress, memberStreetAddress } from '@/lib/memberAddress';
import { goToEntry } from '@/lib/portal';

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
const planForm = ref({ name: '', type: 'Monthly', price: '', period: 'per month', description: '', is_popular: false });
const selectedPlanForEdit = ref<Record<string, unknown> | null>(null);
const navItems = [
  { id: 'dashboard', label: 'Dashboard', description: 'Overview and quick actions' },
  { id: 'attendance', label: 'Attendance', description: 'Check-ins, logs, and gym activity' },
  { id: 'members', label: 'Members', description: 'Accounts, status, and updates' },
  { id: 'memberships', label: 'Membership Plans', description: 'Plans, pricing, and summaries' },
  { id: 'schedule', label: 'Schedule', description: 'Classes, trainers, and availability' },
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
  if (dashboardChart) {
    dashboardChart.destroy();
    dashboardChart = null;
  }
}

function buildDashboardChart() {
  if (currentView.value !== 'dashboard' || !dashboardChartCanvas.value) return;
  destroyDashboardChart();
  dashboardChart = new Chart(dashboardChartCanvas.value, {
    type: 'doughnut',
    data: {
      labels: ['Check-ins Today', 'Members Inside', 'Active Members', 'Expiring Soon'],
      datasets: [
        {
          data: [
            Number(recentCheckins.value.length || 0),
            Number(currentlyInGym.value.length || 0),
            Number(reportAttendance.value?.active_members ?? 0),
            Number(expiringMembers.value.length || 0),
          ],
          backgroundColor: ['#00BFFF', '#8DFF00', '#8A2BE2', '#FF6B6B'],
          borderColor: '#151515',
          borderWidth: 2,
          hoverOffset: 8,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { labels: { color: '#A8A196' } } },
    } as ChartConfiguration<'doughnut'>['options'],
  });
}

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
    return;
  }

  if (view === 'profile') {
    await loadProfileData();
  }
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
  if (p === 'overdue') return 'bg-red-500/20 text-red-300';
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

const checkinsToday = ref(0);
const membersInsideCount = ref(0);

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

function revenueMonthly(): string {
  const r = reportRevenue.value;
  if (!r || typeof (r as Record<string, unknown>).monthly_revenue !== 'number') return '0.00';
  return Number((r as Record<string, unknown>).monthly_revenue).toLocaleString('en-PH', { minimumFractionDigits: 2 });
}

// Reports chart data (same structure as Blade trainer - use API data when available, else Blade defaults)
const weeklyActivityData = computed(() => {
  const daily = (reportAttendance.value?.daily_stats as Array<{ date?: string; count?: number }>) || [];
  const checkins = daily.length >= 7 ? daily.slice(-7).map((d) => Number(d.count ?? 0)) : [0, 0, 0, 0, 0, 0, 0];
  const rev = reportRevenue.value;
  const monthly = typeof (rev as Record<string, unknown>)?.monthly_revenue === 'number' ? Number((rev as Record<string, unknown>).monthly_revenue) : 0;
  const totalCheckins = checkins.reduce((sum, n) => sum + n, 0);
  const revenuePerDay = checkins.map((c) => {
    if (monthly <= 0 || totalCheckins <= 0) return 0;
    return Math.round((monthly / totalCheckins) * c);
  });
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
    const commonOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#A8A196' } } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(168,161,150,0.15)' }, ticks: { color: '#A8A196' } }, x: { grid: { display: false }, ticks: { color: '#A8A196' } } } };
    const doughnutPieOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#A8A196' } } } };
    if (reportsTab.value === 'overview' && weeklyChartCanvas.value) {
      const w = weeklyActivityData.value;
      reportCharts.value.weekly = new Chart(weeklyChartCanvas.value, {
        type: 'bar',
        data: {
          labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
          datasets: [
            { label: 'Check-ins', data: w.checkins, backgroundColor: 'rgba(79, 70, 229, 0.6)', borderColor: 'rgba(79, 70, 229, 1)', borderWidth: 1, borderRadius: 4 },
            { label: 'Revenue', data: w.revenue, backgroundColor: 'rgba(168, 85, 247, 0.6)', borderColor: 'rgba(168, 85, 247, 1)', borderWidth: 1, borderRadius: 4 },
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
          datasets: [{ label: 'Total Members', data: membershipGrowthData.value, fill: false, borderColor: '#A3E635', tension: 0.1 }],
        },
        options: commonOptions as ChartConfiguration<'line'>['options'],
      });
    }
    if (reportsTab.value === 'breakdown') {
      if (planDistCanvas.value) {
        const p = planDistributionChartData.value;
        reportCharts.value.planDist = new Chart(planDistCanvas.value, {
          type: 'doughnut',
          data: { labels: p.labels, datasets: [{ label: 'Members', data: p.data, backgroundColor: ['#A855F7', '#EC4899', '#4F46E5'], hoverOffset: 4 }] },
          options: doughnutPieOptions as ChartConfiguration<'doughnut'>['options'],
        });
      }
      if (revenueChartCanvas.value) {
        const r = revenueByPlanChartData.value;
        reportCharts.value.revenue = new Chart(revenueChartCanvas.value, {
          type: 'pie',
          data: { labels: r.labels, datasets: [{ label: 'Revenue (PHP)', data: r.data, backgroundColor: ['#A855F7', '#EC4899', '#4F46E5'], hoverOffset: 4 }] },
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
    const price = Number((p as Record<string, unknown>).price) || 0;
    return { ...p, member_count: count, monthly_revenue: price * count };
  });
}

function activePlansWithStats(): Record<string, unknown>[] {
  return plansWithStats().filter((p) => !(p as Record<string, unknown>).is_deactivated);
}

function mostSubscribedPlanName(): string {
  const revPlans = (reportRevenue.value?.plans as Array<{ name?: string; member_count: number }>) || [];
  if (revPlans.length === 0) return '-';
  const top = revPlans.reduce((a, b) => (a.member_count >= b.member_count ? a : b), revPlans[0]);
  return top.member_count > 0 ? (top.name || '-') : '-';
}

function planFeatures(plan: Record<string, unknown>): string[] {
  const saved = Array.isArray(plan.features) ? (plan.features as unknown[]).map(String).filter(Boolean) : [];
  if (saved.length) return saved;
  const name = String(plan.name || '').toLowerCase();
  if (name.includes('basic')) return ['Gym Access', 'Locker Room', 'Free Wifi'];
  if (name.includes('vip')) return ['All Equipment', 'Group Classes', 'Locker Room', 'Towel Service'];
  if (name.includes('premium')) return ['Personal Training', 'Group Classes', 'Sauna/Steam', 'Towel Service', 'Free Wifi', 'Guest Access'];
  return ['Gym Access'];
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
  planForm.value = { name: '', type: 'Monthly', price: '', period: 'per month', description: '', is_popular: false };
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
  <Head :title="`${gymDisplayName} - Staff Portal`" />
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
              placeholder="trainer@example.com"
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

    <div v-else class="sm:p-4 lg:p-6">
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
              <p class="text-sm font-semibold uppercase tracking-[0.24em] text-[var(--fg-gold)]">Staff Workspace</p>
              <h2 class="mt-2 text-2xl font-bold text-[var(--fg-ink)] sm:text-3xl">{{ currentViewInfo.label }}</h2>
              <p class="mt-2 max-w-2xl text-sm text-[var(--fg-ink-soft)]">{{ currentViewInfo.description }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
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
          <div v-if="currentView === 'dashboard'" class="space-y-6">
            <h2 class="sr-only">Dashboard</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div class="flex items-center justify-between rounded-lg bg-[#2A3042] p-6 shadow">
                <div>
                  <p class="text-sm text-gray-400">Check-ins Today</p>
                  <p class="text-xl font-bold text-white">{{ dataLoading ? '-' : recentCheckins.length }}</p>
                </div>
                <div class="rounded-full bg-blue-500 p-3 text-white">&#10003;</div>
              </div>
              <div class="flex items-center justify-between rounded-lg bg-[#2A3042] p-6 shadow">
                <div>
                  <p class="text-sm text-gray-400">Members Inside</p>
                  <p class="text-xl font-bold text-white">{{ dataLoading ? '-' : currentlyInGym.length }}</p>
                </div>
                <div class="rounded-full bg-green-500 p-3 text-white">&#128101;</div>
              </div>
              <div class="flex items-center justify-between rounded-lg bg-[#2A3042] p-6 shadow">
                <div>
                  <p class="text-sm text-gray-400">Active Members</p>
                  <p class="text-xl font-bold text-white">{{ reportAttendance?.active_members ?? 0 }}</p>
                </div>
                <div class="rounded-full bg-yellow-500 p-3 text-white">+</div>
              </div>
              <div class="flex items-center justify-between rounded-lg bg-[#2A3042] p-6 shadow">
                <div>
                  <p class="text-sm text-gray-400">Expiring Soon</p>
                  <p class="text-xl font-bold text-white">{{ expiringMembers.length }}</p>
                </div>
                <div class="rounded-full bg-purple-500 p-3 text-white">&#9201;</div>
              </div>
            </div>

            <div class="rounded-lg bg-[#2A3042] p-6 shadow">
              <h4 class="mb-4 text-lg font-semibold text-white">Dashboard Analytics</h4>
              <div class="relative h-60 sm:h-72">
                <canvas ref="dashboardChartCanvas"></canvas>
              </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
              <div class="rounded-lg bg-[#2A3042] p-6 shadow">
                <h4 class="mb-4 text-lg font-semibold text-white">Quick Actions</h4>
                <div class="space-y-3">
                  <button type="button" class="w-full rounded-lg bg-blue-500 px-4 py-3 text-left font-medium text-white hover:opacity-90" @click="openCheckinModal">
                    Record Check-in
                  </button>
                  <button type="button" class="w-full rounded-lg bg-purple-600 px-4 py-3 text-left font-medium text-white hover:opacity-90" @click="openAddMemberModal">
                    Add New Member
                  </button>
                  <button type="button" class="w-full rounded-lg bg-gray-600 px-4 py-3 text-left font-medium text-white hover:opacity-90" @click="currentView = 'attendance'">
                    View Attendance Logs
                  </button>
                </div>
              </div>
              <div class="lg:col-span-2 rounded-lg bg-[#2A3042] p-6 shadow">
                <h4 class="mb-4 text-lg font-semibold text-white">Recent Check-ins</h4>
                <div class="divide-y divide-gray-700">
                  <div v-for="c in recentCheckins.slice(0, 8)" :key="String((c as Record<string, unknown>).id)" class="flex items-center justify-between py-3">
                    <div class="flex items-center gap-3">
                      <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-600 text-sm font-bold text-white">
                        {{ (c as Record<string, unknown>).initials || '-' }}
                      </div>
                      <div>
                        <p class="font-medium text-white">{{ (c as Record<string, unknown>).member_name }}</p>
                        <p class="text-xs text-gray-400">Check-in: {{ (c as Record<string, unknown>).check_in_time }}</p>
                      </div>
                    </div>
                    <span :class="['rounded px-2 py-1 text-xs font-medium', (c as Record<string, unknown>).status === 'Inside' ? 'bg-green-500/20 text-green-300' : 'bg-gray-500/20 text-gray-300']">
                      {{ (c as Record<string, unknown>).status }}
                    </span>
                  </div>
                  <p v-if="recentCheckins.length === 0 && !dataLoading" class="py-4 text-center text-gray-400">No recent check-ins.</p>
                </div>
              </div>
            </div>

            <div class="rounded-lg bg-[#2A3042] p-6 shadow">
              <h4 class="mb-4 text-lg font-semibold text-white">Upcoming Membership Expirations</h4>
              <div class="space-y-3">
                <div v-for="m in expiringMembers.slice(0, 5)" :key="String((m as Record<string, unknown>).id)" class="flex items-center justify-between rounded-lg bg-gray-900/50 p-4">
                  <div class="flex items-center gap-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-yellow-500 text-lg font-bold text-white">
                      {{ String((m as Record<string, unknown>).full_name || '').slice(0, 2).toUpperCase() }}
                    </div>
                    <div>
                      <p class="font-medium text-white">{{ (m as Record<string, unknown>).full_name }}</p>
                      <p class="text-sm text-gray-400">Expires: {{ (m as Record<string, unknown>).expiry_date }}</p>
                    </div>
                  </div>
                </div>
                <p v-if="expiringMembers.length === 0 && !dataLoading" class="py-2 text-gray-400">No members expiring soon.</p>
              </div>
            </div>

            <div class="rounded-lg bg-[#2A3042] p-6 shadow">
              <h4 class="mb-4 text-lg font-semibold text-white">Pending Payments</h4>
              <div class="space-y-3">
                <div v-for="p in pendingPayments()" :key="String((p as Record<string, unknown>).id)" class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-900/50 p-4">
                  <div>
                    <p class="font-medium text-white">{{ (p as Record<string, unknown>).member_name }} — &#8369;{{ (p as Record<string, unknown>).amount }}</p>
                    <p class="text-sm text-gray-400">{{ (p as Record<string, unknown>).invoice_number }} · {{ (p as Record<string, unknown>).payment_method }} · {{ (p as Record<string, unknown>).payment_date }}</p>
                  </div>
                  <div class="flex gap-2">
                    <button type="button" class="rounded bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700" :disabled="processingPaymentId === Number((p as Record<string, unknown>).id)" @click="verifyPaymentStatus(p as Record<string, unknown>, 'Paid')">Approve</button>
                    <button type="button" class="rounded bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700" :disabled="processingPaymentId === Number((p as Record<string, unknown>).id)" @click="verifyPaymentStatus(p as Record<string, unknown>, 'Cancelled')">Reject</button>
                  </div>
                </div>
                <p v-if="pendingPayments().length === 0 && !dataLoading" class="py-2 text-gray-400">No pending payments.</p>
              </div>
            </div>
          </div>

          <!-- Attendance -->
          <div v-if="currentView === 'attendance'" class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
              <h2 class="sr-only">Attendance Management</h2>
              <button type="button" class="rounded bg-blue-500 px-4 py-2 font-medium text-white hover:opacity-90" @click="openCheckinModal">
                Record Check-in
              </button>
            </div>
            <div class="rounded-lg bg-[#2A3042] p-6 shadow">
              <input v-model="attendanceSearch" type="text" placeholder="Search by member name..." class="mb-4 w-full max-w-md rounded border border-gray-600 bg-gray-900/50 px-4 py-2 text-white placeholder-gray-500 focus:border-[#A3E635] focus:outline-none focus:ring-1 focus:ring-[#A3E635]" />
              <div class="overflow-x-auto">
                <table class="w-full text-left text-gray-300">
                  <thead class="border-b border-gray-700">
                    <tr>
                      <th class="py-3 px-4">Member</th>
                      <th class="py-3 px-4">Date</th>
                      <th class="py-3 px-4">Check-in</th>
                      <th class="py-3 px-4">Check-out</th>
                      <th class="py-3 px-4">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="l in filteredLogs()" :key="String((l as Record<string, unknown>).id)">
                      <td class="py-3 px-4">{{ (l as Record<string, unknown>).member_name }}</td>
                      <td class="py-3 px-4">{{ (l as Record<string, unknown>).date }}</td>
                      <td class="py-3 px-4">{{ (l as Record<string, unknown>).check_in }}</td>
                      <td class="py-3 px-4">{{ (l as Record<string, unknown>).check_out }}</td>
                      <td class="py-3 px-4">{{ (l as Record<string, unknown>).status }}</td>
                    </tr>
                    <tr v-if="filteredLogs().length === 0">
                      <td colspan="5" class="py-4 px-4 text-center text-gray-400">{{ dataLoading ? 'Loading...' : 'No logs.' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="rounded-lg bg-[#2A3042] p-6 shadow">
              <h4 class="mb-4 text-lg font-semibold text-white">Members Currently Inside ({{ currentlyInGym.length }})</h4>
              <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                <div v-for="c in currentlyInGym" :key="String((c as Record<string, unknown>).id)" class="rounded-lg bg-gray-900/50 p-4">
                  <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-500/30 text-sm font-bold text-white">
                      {{ (c as Record<string, unknown>).initials }}
                    </div>
                    <div>
                      <p class="font-medium text-white">{{ (c as Record<string, unknown>).member_name }}</p>
                      <p class="text-xs text-gray-400">{{ (c as Record<string, unknown>).check_in_time }}</p>
                    </div>
                  </div>
                </div>
                <p v-if="currentlyInGym.length === 0 && !dataLoading" class="col-span-full py-4 text-center text-gray-400">No members currently inside.</p>
              </div>
            </div>
          </div>          <!-- Members -->
          <div v-if="currentView === 'members'" class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
              <h2 class="sr-only">Member Management</h2>
              <button type="button" class="rounded bg-[#A3E635] px-4 py-2 font-semibold text-black hover:opacity-90" @click="openAddMemberModal">
                + Add Member
              </button>
            </div>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_160px_160px]">
              <input
                v-model="memberSearch"
                type="text"
                placeholder="SEARCH MEMBERS by name, email, or ID..."
                class="w-full rounded border border-gray-600 bg-[#2A3042] px-4 py-3 text-white placeholder-gray-500 focus:border-[#A3E635] focus:outline-none focus:ring-1 focus:ring-[#A3E635]"
              />
              <select v-model="memberStatusFilter" class="rounded border border-gray-600 bg-[#2A3042] px-4 py-3 text-white focus:border-[#A3E635] focus:outline-none focus:ring-1 focus:ring-[#A3E635]">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="expired">Expired</option>
                <option value="ending soon">Ending Soon</option>
              </select>
              <select v-model="memberPlanFilter" class="rounded border border-gray-600 bg-[#2A3042] px-4 py-3 text-white focus:border-[#A3E635] focus:outline-none focus:ring-1 focus:ring-[#A3E635]">
                <option value="">All Plans</option>
                <option v-for="p in plansList" :key="`plan-filter-${String((p as Record<string, unknown>).id)}`" :value="String((p as Record<string, unknown>).name || '')">
                  {{ (p as Record<string, unknown>).name }}
                </option>
              </select>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-700 bg-[#2A3042]">
              <table class="w-full min-w-[1150px] text-left text-gray-300">
                <thead class="border-b border-gray-700">
                  <tr>
                    <th class="px-4 py-3">Member</th>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">Contact</th>
                    <th class="px-4 py-3">Address</th>
                    <th class="px-4 py-3">Plan</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Expiry</th>
                    <th class="px-4 py-3">Payment</th>
                    <th class="px-4 py-3">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="m in filteredMembers()" :key="String((m as Record<string, unknown>).id)" class="border-b border-gray-800/70">
                    <td class="px-4 py-4">
                      <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-purple-500/40 text-sm font-bold text-white">
                          {{ String((m as Record<string, unknown>).full_name || '').slice(0, 2).toUpperCase() }}
                        </div>
                        <div>
                          <p class="font-semibold text-white">{{ (m as Record<string, unknown>).full_name }}</p>
                          <p class="text-sm text-gray-400">{{ (m as Record<string, unknown>).email || '—' }}</p>
                        </div>
                      </div>
                    </td>
                    <td class="px-4 py-4">{{ (m as Record<string, unknown>).member_id || '—' }}</td>
                    <td class="px-4 py-4">{{ (m as Record<string, unknown>).phone || '—' }}</td>
                    <td class="px-4 py-4">
                      <span class="block max-w-[240px] truncate" :title="memberStreetAddress((m as Record<string, unknown>).address)">
                        {{ memberStreetAddress((m as Record<string, unknown>).address) || '—' }}
                      </span>
                    </td>
                    <td class="px-4 py-4">{{ (m as Record<string, unknown>).membership_plan || '—' }}</td>
                    <td class="px-4 py-4">
                      <span :class="['rounded-full px-3 py-1 text-xs font-semibold', statusBadgeClass((m as Record<string, unknown>).status, (m as Record<string, unknown>).expiry_date as string)]">
                        {{ memberStatusLabel((m as Record<string, unknown>).status, (m as Record<string, unknown>).expiry_date as string) }}
                      </span>
                    </td>
                    <td class="px-4 py-4">{{ (m as Record<string, unknown>).expiry_date || '—' }}</td>
                    <td class="px-4 py-4">
                      <span :class="['rounded-full px-3 py-1 text-xs font-semibold', paymentBadgeClass((m as Record<string, unknown>).payment_status)]">
                        {{ String((m as Record<string, unknown>).payment_status || '—').toLowerCase() }}
                      </span>
                    </td>
                    <td class="px-4 py-4">
                      <div class="flex gap-2">
                        <button v-if="String((m as Record<string, unknown>).status).toLowerCase() === 'pending'" type="button" class="rounded bg-green-600 px-3 py-1 text-xs font-medium text-white hover:bg-green-700" :disabled="formLoading" @click="approveMember(m as Record<string, unknown>)">Approve</button>
                        <button type="button" class="rounded bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700" @click="viewMember(m as Record<string, unknown>)">View</button>
                        <button type="button" class="rounded bg-purple-600 px-3 py-1 text-xs font-medium text-white hover:bg-purple-700" @click="editMember(m as Record<string, unknown>)">Edit</button>
                        <button type="button" class="rounded bg-red-600 px-3 py-1 text-xs font-medium text-white hover:bg-red-700" :disabled="formLoading" @click="deleteMember(m as Record<string, unknown>)">Delete</button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="filteredMembers().length === 0">
                    <td colspan="9" class="px-4 py-6 text-center text-gray-400">{{ dataLoading ? 'Loading...' : 'No members.' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Memberships (same layout & data as Blade trainer: summary + Available Plans cards + Add/Edit/Delete) -->
          <div v-if="currentView === 'memberships'" class="space-y-6">
            <div class="flex flex-wrap justify-between items-center gap-4">
              <h2 class="sr-only">Memberships</h2>
              <div class="flex gap-2">
                <button type="button" class="rounded bg-[#A3E635] px-4 py-2 text-sm font-semibold text-black hover:opacity-90" @click="openAddPlan">
                  + Add New Plan
                </button>
                <button type="button" class="rounded bg-[#2A3042] border border-gray-600 px-3 py-2 text-sm text-white hover:bg-gray-700" title="Refresh (same data as Blade when same DB)" @click="loadMembershipsData">
                  <span class="mr-1">&#8635;</span> Refresh
                </button>
              </div>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
              <div class="rounded-lg bg-[#2A3042] p-4 shadow">
                <p class="text-sm text-gray-400">Total Plans</p>
                <p class="text-2xl font-bold text-white">{{ plansList.length }}</p>
              </div>
              <div class="rounded-lg bg-[#2A3042] p-4 shadow">
                <p class="text-sm text-gray-400">Total Members</p>
                <p class="text-2xl font-bold text-white">{{ reportRevenue?.total_members ?? reportMembership?.total_members ?? 0 }}</p>
              </div>
              <div class="rounded-lg bg-[#2A3042] p-4 shadow">
                <p class="text-sm text-gray-400">Monthly Revenue</p>
                <p class="text-2xl font-bold text-white">&#8369;{{ revenueMonthly() }}</p>
              </div>
              <div class="rounded-lg bg-[#2A3042] p-4 shadow">
                <p class="text-sm text-gray-400">Most subscribed plan</p>
                <p class="text-2xl font-bold text-white">{{ mostSubscribedPlanName() }}</p>
              </div>
            </div>
            <h4 class="text-xl font-semibold text-[var(--fg-ink)] mb-4">Available Plans</h4>
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
              <div v-for="p in activePlansWithStats()" :key="String((p as Record<string, unknown>).id)" :class="['trainer-plan-card relative flex h-full flex-col rounded-lg border-t-4 bg-[#2A3042] p-6 shadow', (p as Record<string, unknown>).is_popular ? 'border-amber-400' : 'border-purple-500']">
                <span v-if="(p as Record<string, unknown>).is_popular" class="absolute top-4 right-4 bg-amber-400 text-amber-900 text-xs font-bold px-3 py-1 rounded-full">Popular</span>
                <div class="mb-4">
                  <h4 class="text-2xl font-bold text-white">{{ (p as Record<string, unknown>).name }}</h4>
                  <p class="mt-2 text-2xl font-bold text-white">&#8369;{{ Number((p as Record<string, unknown>).price || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 }) }} <span class="text-base font-normal text-gray-400">/ {{ (p as Record<string, unknown>).period || 'month' }}</span></p>
                  <p class="mt-2 text-sm text-gray-400">{{ (p as Record<string, unknown>).description || 'A great membership plan for our valued members.' }}</p>
                </div>
                <div class="my-6 flex-1">
                  <h5 class="font-semibold text-white mb-3">Features Included:</h5>
                  <ul class="space-y-2 text-gray-300 text-sm">
                    <li v-for="(f, i) in planFeatures(p as Record<string, unknown>)" :key="i" class="flex items-center">
                      <i class="fas fa-check text-green-400 mr-2 w-4"></i>{{ f }}
                    </li>
                  </ul>
                </div>
                <div class="mt-4 rounded-lg bg-purple-500/20 p-4">
                  <div class="flex justify-between items-center text-white">
                    <div><p class="text-sm">Members</p><p class="text-xl font-bold">{{ (p as Record<string, unknown>).member_count ?? 0 }}</p></div>
                    <div><p class="text-sm">Monthly Revenue</p><p class="text-xl font-bold"><span class="trainer-revenue-badge rounded px-2 py-0.5 bg-amber-400 text-amber-900 font-semibold">&#8369;{{ Number((p as Record<string, unknown>).monthly_revenue ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</span></p></div>
                  </div>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                  <button type="button" class="rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700" @click="openEditPlan(p as Record<string, unknown>)">Edit Plan</button>
                  <button type="button" class="rounded bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700" @click="deletePlan(p as Record<string, unknown>)">Delete</button>
                </div>
              </div>
              <p v-if="activePlansWithStats().length === 0 && !dataLoading" class="col-span-full py-4 text-center text-gray-400">No plans.</p>
            </div>
          </div>

          <!-- Schedule: the branch's real weekly classes (same as Admin > Classes; members book these) -->
          <div v-if="currentView === 'schedule'" class="space-y-6">
            <div class="flex flex-wrap justify-between items-center gap-4">
              <h2 class="sr-only">Schedule Calendar</h2>
              <button type="button" class="rounded bg-purple-600 px-4 py-2 font-medium text-white hover:opacity-90" @click="openAddClassModal">
                Add Class
              </button>
            </div>
            <div class="flex flex-wrap items-center gap-2 mb-6 text-sm">
              <button type="button" :class="['px-4 py-2 font-medium rounded-md transition-colors', scheduleViewTab === 'Calendar' ? 'bg-blue-600 text-white' : 'bg-[#2A3042] text-gray-300 hover:bg-gray-700']" @click="scheduleViewTab = 'Calendar'">Calendar View</button>
              <button type="button" :class="['px-4 py-2 font-medium rounded-md transition-colors', scheduleViewTab === 'TrainerAvailability' ? 'bg-blue-600 text-white' : 'bg-[#2A3042] text-gray-300 hover:bg-gray-700']" @click="scheduleViewTab = 'TrainerAvailability'">Trainer Availability</button>
              <button type="button" :class="['px-4 py-2 font-medium rounded-md transition-colors', scheduleViewTab === 'ClassManagement' ? 'bg-blue-600 text-white' : 'bg-[#2A3042] text-gray-300 hover:bg-gray-700']" @click="scheduleViewTab = 'ClassManagement'">Class Management</button>
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
                <button type="button" class="rounded bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:opacity-90" @click="openAddClassModal">Add Class</button>
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
                        <th class="py-3 px-4">Trainer</th>
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
                            <div v-for="cls in classesTaughtBy(staff.id, day)" :key="cls.id" class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-300">
                              {{ cls.start_time }} {{ cls.name }}
                            </div>
                            <div v-if="classesTaughtBy(staff.id, day).length === 0" class="px-2 py-1 text-xs rounded bg-green-500/20 text-green-300">Free</div>
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
                        <th class="py-3 px-4">Trainer</th>
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
                            <button type="button" class="px-3 py-1 text-xs font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700" @click="openEditClassModal(cls)">Edit</button>
                            <button type="button" class="px-3 py-1 text-xs font-medium text-white bg-red-600 rounded-md hover:bg-red-700" @click="deleteManagedClass(cls)">Delete</button>
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
                <div class="md:col-span-2">
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
                    <button type="button" class="rounded bg-purple-600 px-4 py-2 font-medium text-white hover:opacity-90" :disabled="formLoading" @click="saveProfile">
                      {{ formLoading ? 'Saving...' : 'Save Profile' }}
                    </button>
                    <button type="button" class="rounded bg-gray-600 px-4 py-2 font-medium text-white hover:bg-gray-500" @click="showPasswordModal = true">
                      Change Password
                    </button>
                    <button type="button" class="rounded bg-red-600 px-4 py-2 font-medium text-white hover:bg-red-700" @click="handleLogout">
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
        <h3 class="mb-4 text-xl font-bold text-white">Record Attendance</h3>
        <div class="mb-4 rounded-lg border border-gray-700 bg-[#1A2035] p-4">
          <div class="mb-3 flex items-center justify-between gap-3">
            <p class="text-sm font-semibold text-white">Scan Member QR</p>
            <button
              type="button"
              class="rounded px-3 py-1 text-sm font-medium text-black"
              :class="trainerQrScannerActive ? 'bg-red-400' : 'bg-[#A3E635]'"
              :disabled="formLoading || trainerQrScannerBusy"
              @click="trainerQrScannerActive ? stopTrainerQrScanner() : startTrainerQrScanner()"
            >
              {{ trainerQrScannerActive ? 'Stop Scan' : 'Start Scan' }}
            </button>
          </div>
          <div class="overflow-hidden rounded-lg bg-black">
            <div ref="trainerQrVideoRef" class="h-56 w-full" />
          </div>
          <p v-if="trainerQrScannerStatus" class="mt-3 text-sm text-cyan-300">{{ trainerQrScannerStatus }}</p>
          <p v-if="trainerQrScannerError" class="mt-2 text-sm text-red-400">{{ trainerQrScannerError }}</p>
          <textarea
            v-model="trainerQrManualQrPayload"
            class="mt-3 w-full rounded border border-gray-600 bg-[#12182A] px-3 py-2 text-sm text-white"
            rows="3"
            placeholder="Paste the member attendance QR payload here if camera scan is unavailable."
          />
          <button
            type="button"
            class="mt-2 w-full rounded bg-blue-500 py-2 text-sm font-medium text-white hover:opacity-90"
            :disabled="formLoading || trainerQrScannerBusy"
            @click="submitTrainerQrPayload()"
          >
            Process QR Payload
          </button>
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-300">Member</label>
          <select v-model="checkinForm.member_id" class="mt-1 w-full rounded border border-gray-600 bg-[#1A2035] px-3 py-2 text-white">
            <option value="">Select member</option>
            <option v-for="m in activeMembersForCheckin" :key="String((m as Record<string, unknown>).id)" :value="String((m as Record<string, unknown>).id)">
              {{ (m as Record<string, unknown>).full_name }} ({{ (m as Record<string, unknown>).member_id }})
            </option>
          </select>
        </div>
        <p v-if="formError" class="mb-2 text-sm text-red-400">{{ formError }}</p>
        <div class="flex gap-2">
          <button type="button" class="flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="stopTrainerQrScanner(); showCheckinModal = false">Cancel</button>
          <button type="button" class="flex-1 rounded bg-[#A3E635] py-2 font-semibold text-black hover:opacity-90" :disabled="formLoading" @click="submitCheckin()">
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
          <button type="button" class="flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showViewMemberModal = false">Close</button>
          <button type="button" class="flex-1 rounded bg-gray-300 py-2 font-medium text-gray-800 hover:bg-gray-400" @click="editMember(selectedMemberForView); showViewMemberModal = false">Edit</button>
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
          <button type="button" class="flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showAddMemberModal = false">Cancel</button>
          <button type="button" class="flex-1 rounded bg-[#A3E635] py-2 font-semibold text-black hover:opacity-90" :disabled="formLoading" @click="submitAddMember">
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
          <div class="flex items-center gap-2">
            <input v-model="planForm.is_popular" type="checkbox" id="trainer-plan-popular" class="rounded border-gray-600" />
            <label for="trainer-plan-popular" class="text-sm text-gray-300">Mark as popular</label>
          </div>
        </div>
        <p v-if="formError" class="mt-2 text-sm text-red-400">{{ formError }}</p>
        <div class="mt-4 flex gap-2">
          <button type="button" class="flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showAddPlanModal = false">Cancel</button>
          <button type="button" class="flex-1 rounded bg-[#A3E635] py-2 font-semibold text-black hover:opacity-90" :disabled="formLoading" @click="submitAddPlan">
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
          <button type="button" class="flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showAddPaymentModal = false; selectedPaymentForEdit = null">Cancel</button>
          <button type="button" class="flex-1 rounded bg-[#A3E635] py-2 font-semibold text-black hover:opacity-90" :disabled="formLoading" @click="submitAddPayment">
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
          <button type="button" class="flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showViewPaymentModal = false">Close</button>
          <button type="button" class="flex-1 rounded bg-green-600 py-2 text-white hover:bg-green-700" @click="showViewPaymentModal = false; selectedPaymentForView && editPayment(selectedPaymentForView)">Edit</button>
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
            <label for="class-trainer" class="block text-sm font-medium text-gray-300">Trainer</label>
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
          <button type="button" class="flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showScheduleModal = false">Cancel</button>
          <button type="button" class="flex-1 rounded bg-[#A3E635] py-2 font-semibold text-black hover:opacity-90 disabled:opacity-50" :disabled="formLoading" @click="submitManagedClass">
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
          <button type="button" class="flex-1 rounded bg-gray-600 py-2 text-white hover:bg-gray-500" @click="showPasswordModal = false">Cancel</button>
          <button type="button" class="flex-1 rounded bg-[#A3E635] py-2 font-semibold text-black hover:opacity-90" :disabled="formLoading" @click="submitChangePassword">
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
  --accent-green: #5FA82E;
  --accent-blue: #1E88C8;
  --accent-purple: #8A2BE2;
  --accent-purple-light: #6A4BC4;
  --accent-pink: #E0565B;
  --accent-yellow: #E2B54A;
  /* the page background is the shared .fg-marble-bg */
}

.trainer-auth-shell {
  background:
    radial-gradient(circle at top, rgba(106, 90, 205, 0.18), transparent 28%),
    radial-gradient(circle at bottom right, rgba(255, 107, 107, 0.1), transparent 26%),
    transparent;
}

.trainer-auth-card {
  background: var(--bg-card);
  border-color: var(--border-color);
  box-shadow: 0 28px 80px rgba(2, 6, 23, 0.45);
}

.trainer-auth-eyebrow {
  color: var(--accent-purple-light);
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
  background: rgba(138, 43, 226, 0.16);
  border: 1px solid rgba(106, 90, 205, 0.28);
  border-radius: 999px;
  color: var(--accent-purple-light);
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
  color: var(--accent-purple-light);
}

.trainer-auth-error {
  background: rgba(255, 107, 107, 0.12);
  border: 1px solid rgba(255, 107, 107, 0.3);
  border-radius: 0.75rem;
  color: #b42318;
  padding: 0.75rem 0.9rem;
}

.trainer-auth-primary {
  background: linear-gradient(135deg, var(--accent-purple) 0%, #9b36f3 100%);
  color: #fff;
}

.trainer-auth-primary:hover:not(:disabled) {
  filter: brightness(1.05);
}

.trainer-shell {
  background: var(--bg-primary);
  border-color: rgba(160, 122, 31, 0.35);
}

/* backdrop-filter would trap the fixed-position mobile drawer inside the shell */
@media (min-width: 1024px) {
  .trainer-shell {
    backdrop-filter: blur(12px);
  }
}

.trainer-sidebar {
  background:
    linear-gradient(180deg, rgba(217, 180, 90, 0.07) 0%, rgba(217, 180, 90, 0) 45%),
    var(--bg-secondary) !important;
  border-right: 1px solid var(--border-color);
}

.trainer-profile-card,
.trainer-header-card {
  background: var(--bg-card);
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
  border-color: var(--border-color);
}

.trainer-avatar {
  background: var(--accent-purple);
  color: #fff;
}

.trainer-nav-item {
  background: transparent;
  border: 1px solid transparent;
  color: var(--text-primary);
}

.trainer-nav-item:hover {
  background: rgba(217, 180, 90, 0.1);
  border-color: rgba(106, 90, 205, 0.3);
}

.trainer-nav-item.active {
  background: linear-gradient(135deg, rgba(138, 43, 226, 0.92) 0%, rgba(155, 54, 243, 0.92) 100%);
  box-shadow: 0 14px 28px rgba(138, 43, 226, 0.28);
  color: #fff;
}

.trainer-logout-btn {
  background: var(--accent-pink);
  color: #fff;
}

.trainer-header {
  background: transparent;
  border-color: rgba(160, 122, 31, 0.3);
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
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
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

.trainer-portal .bg-purple-600 {
  background: var(--accent-purple) !important;
}

.trainer-portal .hover\:bg-purple-700:hover {
  background: var(--accent-purple-light) !important;
}

.trainer-portal .bg-blue-500,
.trainer-portal .bg-blue-600 {
  background: var(--accent-blue) !important;
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
  color: #111827 !important;
}

.trainer-portal .bg-green-500 {
  background: var(--accent-green) !important;
  color: #111827 !important;
}

.trainer-portal .bg-green-500\/20 {
  background: color-mix(in srgb, var(--accent-green) 20%, transparent) !important;
}

.trainer-portal .bg-red-600,
.trainer-portal .bg-red-700 {
  background: var(--accent-pink) !important;
}

.trainer-portal .bg-red-500\/20 {
  background: color-mix(in srgb, var(--accent-pink) 20%, transparent) !important;
}

.trainer-portal .bg-amber-400,
.trainer-portal .bg-amber-600,
.trainer-portal .bg-amber-700,
.trainer-portal .bg-yellow-500 {
  background: var(--accent-yellow) !important;
}

.trainer-portal .bg-yellow-500\/20 {
  background: color-mix(in srgb, var(--accent-yellow) 20%, transparent) !important;
}

.trainer-portal .bg-purple-500\/40 {
  background: color-mix(in srgb, var(--accent-purple) 40%, transparent) !important;
}

.trainer-portal .text-amber-900 {
  color: #000 !important;
}

.trainer-portal .text-green-300 {
  color: var(--accent-green) !important;
}

.trainer-portal .text-blue-300 {
  color: var(--accent-blue) !important;
}

.trainer-portal .text-red-300,
.trainer-portal .text-red-400 {
  color: var(--accent-pink) !important;
}

.trainer-portal .text-yellow-300 {
  color: var(--accent-yellow) !important;
}

.trainer-portal .bg-gray-300 {
  background: var(--accent-purple-light) !important;
  color: #fff !important;
}

.trainer-portal .bg-gray-600,
.trainer-portal .bg-gray-700 {
  background: var(--bg-input) !important;
}

.trainer-portal .bg-gray-500\/20 {
  background: color-mix(in srgb, var(--text-secondary) 20%, transparent) !important;
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
  color: #000 !important;
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

/* white text stays white on colored buttons, badges and avatars */
.trainer-portal :is(.bg-purple-600, .bg-blue-500, .bg-blue-600, .bg-red-400, .bg-red-500, .bg-red-600, .bg-red-700, .bg-green-600, .bg-gray-300, .trainer-nav-item.active, .nav-item.bg-purple-600).text-white,
.trainer-portal :is(.bg-purple-600, .bg-blue-500, .bg-blue-600, .bg-red-600, .bg-red-700, .trainer-nav-item.active) .text-white {
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
  color: #000 !important;
  font-weight: 600 !important;
}

.trainer-portal .trainer-page-title {
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: 700;
}

.trainer-portal table th {
  font-size: clamp(0.7rem, 1.2vw, 0.875rem) !important;
  font-weight: 600 !important;
  padding: clamp(0.75rem, 1.5vw, 0.9375rem) !important;
}

.trainer-portal table td {
  font-size: clamp(0.7rem, 1.2vw, 0.875rem) !important;
  padding: clamp(0.75rem, 1.5vw, 0.9375rem) !important;
}

.trainer-portal .bg-\[\#2A3042\].p-6 {
  padding: 0.875rem !important;
  border-radius: 0.5rem !important;
}

.trainer-portal .text-lg.font-semibold.text-white {
  font-size: clamp(0.9375rem, 1.5vw, 1.125rem) !important;
}

.trainer-portal .w-full.rounded-lg.bg-blue-500,
.trainer-portal .w-full.rounded-lg.bg-purple-600,
.trainer-portal .w-full.rounded-lg.bg-gray-600 {
  font-size: clamp(0.75rem, 1vw, 0.875rem) !important;
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
</style>
