<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Menu, X } from 'lucide-vue-next';
import { ref, shallowRef, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { Chart, type ChartConfiguration } from 'chart.js/auto';
import {
  adminAuth,
  members,
  membershipPlans,
  gymInfo,
  payments,
  reports,
  trainer,
  trainerAttendance,
  adminTrainerAttendance,
  adminNotifications,
  gymClasses,
} from '@/api/fitgym';
import PasswordToggle from '@/components/PasswordToggle.vue';
import ProfilePhotoPicker from '@/components/ProfilePhotoPicker.vue';
import { useMobileNav } from '@/composables/useMobileNav';
import { usePasswordVisibility } from '@/composables/usePasswordVisibility';
import { useQrCheckinScanner } from '@/composables/useQrCheckinScanner';
import { useSessionGuard } from '@/composables/useSessionGuard';
import { mergeMemberStreetAddress, memberStreetAddress as editableStreetAddress, parseMemberAddressMeta } from '@/lib/memberAddress';
import { goToEntry } from '@/lib/portal';

const currentView = ref('dashboard');
const { mobileNavOpen, closeMobileNav } = useMobileNav();
const pw = usePasswordVisibility();
let adminPortalRefreshTimer: number | null = null;
const user = ref<{ id: number; name: string; email: string; role: string; photo_url?: string | null } | null>(null);
/** The signed-in admin's profile photo; the Settings picker writes it back so the sidebar updates too. */
const userPhotoUrl = computed<string | null>({
  get: () => user.value?.photo_url ?? null,
  set: (url) => {
    if (user.value) user.value = { ...user.value, photo_url: url };
  },
});
const loading = ref(true);
const loginLoading = ref(false);
const loginError = ref('');
const isLoggedIn = ref(false);

const showPassword = ref(false);
const loginForm = ref({
  email: '',
  password: '',
  remember: false,
});

const membersList = ref<Record<string, unknown>[]>([]);
const plansList = ref<Record<string, unknown>[]>([]);
const paymentsList = ref<Record<string, unknown>[]>([]);
const trainerList = ref<Record<string, unknown>[]>([]);
const reportRevenue = ref<Record<string, unknown> | null>(null);
const reportMembership = ref<Record<string, unknown> | null>(null);
const reportAttendance = ref<Record<string, unknown> | null>(null);
const attendanceLogs = ref<Record<string, unknown>[]>([]);
const recentCheckins = ref<Record<string, unknown>[]>([]);
const currentlyInGymList = ref<Record<string, unknown>[]>([]);
const trainerAttendanceList = ref<Record<string, unknown>[]>([]);
const classesList = ref<Record<string, unknown>[]>([]);
const gymInfoData = ref<Record<string, unknown> | null>(null);
const gymDisplayName = computed(() => String(gymInfoData.value?.name || 'FitGym'));
const attendanceTab = ref('log');
const reportsTab = ref('overview');
const notificationTab = ref('active');
const processingPaymentId = ref<number | null>(null);
const memberSearch = ref('');
const memberStatusFilter = ref('');
const memberPlanFilter = ref('');
const attendanceSearch = ref('');
const settingsTab = ref('profile');
const showMemberQrScannerModal = ref(false);
const showNotificationsPanel = ref(false);

const notificationList = ref<Record<string, unknown>[]>([]);
const notificationHistoryList = ref<Record<string, unknown>[]>([]);
const notificationActionLoading = ref<number | 'all' | null>(null);
const notificationError = ref('');
const showNotificationDetailModal = ref(false);
const selectedNotification = ref<Record<string, unknown> | null>(null);

const showAddMemberModal = ref(false);
const showAddPlanModal = ref(false);
const showAddPaymentModal = ref(false);
const showAddTrainerModal = ref(false);
const showChangePasswordModal = ref(false);
const showViewMemberModal = ref(false);
const showSendSmsModal = ref(false);
const smsMessage = ref('');
const showReceiptModal = ref(false);
const selectedReceiptUrl = ref('');
const showViewTrainerModal = ref(false);
const selectedTrainer = ref<Record<string, unknown> | null>(null);
const showAddClassModal = ref(false);
const editingClassId = ref<number | null>(null);
const classForm = ref({ name: '', trainer_id: '', day_of_week: 'Monday', start_time: '', end_time: '', capacity: '10' });
const memberForm = ref({ full_name: '', email: '', phone: '', address: '', membership_plan_id: '' });
const planForm = ref({ name: '', type: 'Monthly', price: '', period: '', description: '', is_popular: false, features: [] as string[] });
const MAX_PLAN_FEATURES = 20;

function planFeatureList(plan: Record<string, unknown>): string[] {
  const features = Array.isArray(plan.features) ? (plan.features as unknown[]).map(String).filter(Boolean) : [];
  return features.length ? features : ['Gym Access'];
}

function addPlanFeature() {
  if (planForm.value.features.length < MAX_PLAN_FEATURES) planForm.value.features.push('');
}

function removePlanFeature(index: number) {
  planForm.value.features.splice(index, 1);
}
const paymentForm = ref({ member_id: '', membership_plan_id: '', amount: '', payment_date: '', payment_method: 'Cash' });
const trainerForm = ref({ full_name: '', email: '', phone: '', position: '', department: '', hire_date: '', password: '', status: 'Active' });
const passwordForm = ref({ current_password: '', new_password: '', confirm_password: '' });
const adminProfileForm = ref({ name: '', address: '', phone: '' });
const adminProfileNotice = ref('');
const gymForm = ref({
  name: 'FitLife Gym',
  email: '',
  phone: '',
  gcash_number: '',
  gcash_account_name: '',
  gcash_qr_url: '',
  address: '',
  textbee_device_id: '',
  textbee_sim_subscription_id: '',
  operating_hours: {
    Monday: '9AM-5PM', Tuesday: '9AM-5PM', Wednesday: '9AM-5PM', Thursday: '9AM-5PM',
    Friday: '9AM-5PM', Saturday: '9AM-5PM', Sunday: '9AM-5PM',
  },
});
const gcashQrFile = ref<File | null>(null);
const selectedMember = ref<Record<string, unknown> | null>(null);
const selectedPlanForEdit = ref<Record<string, unknown> | null>(null);
const editingTrainerId = ref<number | null>(null);
const formLoading = ref(false);
const formError = ref('');
const overviewChartCanvas = ref<HTMLCanvasElement | null>(null);
const attendanceChartCanvas = ref<HTMLCanvasElement | null>(null);
const membershipChartCanvas = ref<HTMLCanvasElement | null>(null);
const dashboardChartCanvas = ref<HTMLCanvasElement | null>(null);
const adminReportCharts = shallowRef<{
  overview?: InstanceType<typeof Chart>;
  attendance?: InstanceType<typeof Chart>;
  membership?: InstanceType<typeof Chart>;
}>({});
let adminDashboardChart: InstanceType<typeof Chart> | null = null;
const {
  videoRef: adminQrVideoRef,
  scannerActive: adminQrScannerActive,
  scannerBusy: adminQrScannerBusy,
  scannerError: adminQrScannerError,
  scannerStatus: adminQrScannerStatus,
  manualQrPayload: adminQrManualQrPayload,
  startScanner: startAdminQrScanner,
  stopScanner: stopAdminQrScanner,
  submitManualPayload: submitAdminQrPayload,
} = useQrCheckinScanner({
  onDetected: async ({ rawValue }) => {
    await trainerAttendance.processQr(rawValue);
    await loadData();
    showMemberQrScannerModal.value = false;
  },
});

async function checkAuth() {
  // Stay on the loading screen while navigating away, so the old portal-local
  // login form never flashes on the way to the unified sign-in page.
  let leaving = false;

  try {
    const { data } = await adminAuth.user();
    if (data.success && data.user && (data.user.role === 'Admin' || !data.user.role)) {
      user.value = data.user;
      isLoggedIn.value = true;
      await Promise.all([loadData(), loadGymInfo()]);
      startAdminPortalAutoRefresh();
      nextTick(() => buildAdminDashboardChart());
    } else {
      isLoggedIn.value = false;
      stopAdminPortalAutoRefresh();
      leaving = true;
      goToEntry('admin', { reason: 'session' });
    }
  } catch {
    isLoggedIn.value = false;
    stopAdminPortalAutoRefresh();
    leaving = true;
    goToEntry('admin', { reason: 'session' });
  } finally {
    if (!leaving) loading.value = false;
  }
}

function syncNotificationLists(notifData?: { active?: unknown[]; history?: unknown[] } | null) {
  notificationList.value = Array.isArray(notifData?.active) ? notifData.active as Record<string, unknown>[] : [];
  notificationHistoryList.value = Array.isArray(notifData?.history) ? notifData.history as Record<string, unknown>[] : [];
}

async function loadData() {
  try {
    const [memRes, planRes, payRes, trainerRes, revRes, memReportRes, attRes, logsRes, recentRes, currentInGymRes, notifRes] = await Promise.all([
      members.list(),
      membershipPlans.list(),
      payments.list().catch(() => ({ data: { payments: [] } })),
      trainer.list().catch(() => ({ data: { trainer: [] } })),
      reports.revenue().catch(() => ({ data: {} })),
      reports.membership().catch(() => ({ data: {} })),
      reports.attendance().catch(() => ({ data: {} })),
      trainerAttendance.logs().catch(() => ({ data: { logs: [] } })),
      trainerAttendance.recent().catch(() => ({ data: { checkins: [] } })),
      trainerAttendance.currentlyInGym().catch(() => ({ data: { members: [] } })),
      adminNotifications.list().catch(() => ({ data: { active: [], history: [] } })),
    ]);
    membersList.value = Array.isArray(memRes.data) ? memRes.data : [];
    plansList.value = Array.isArray(planRes.data) ? planRes.data : [];
    paymentsList.value = Array.isArray((payRes.data as { payments?: unknown[] })?.payments)
      ? (payRes.data as { payments: Record<string, unknown>[] }).payments
      : [];
    trainerList.value = Array.isArray((trainerRes.data as { trainer?: unknown[] })?.trainer)
      ? (trainerRes.data as { trainer: Record<string, unknown>[] }).trainer
      : [];
    reportRevenue.value = (revRes.data as Record<string, unknown>) || null;
    reportMembership.value = (memReportRes.data as Record<string, unknown>) || null;
    reportAttendance.value = (attRes.data as Record<string, unknown>) || null;
    attendanceLogs.value = Array.isArray((logsRes.data as { logs?: unknown[] })?.logs)
      ? (logsRes.data as { logs: Record<string, unknown>[] }).logs
      : [];
    recentCheckins.value = Array.isArray((recentRes.data as { checkins?: unknown[] })?.checkins)
      ? (recentRes.data as { checkins: Record<string, unknown>[] }).checkins
      : [];
    currentlyInGymList.value = Array.isArray((currentInGymRes.data as { members?: unknown[] })?.members)
      ? (currentInGymRes.data as { members: Record<string, unknown>[] }).members
      : [];

    const notifData = (notifRes.data as { active?: unknown[]; history?: unknown[] }) || {};
    syncNotificationLists(notifData);
  } catch {
    membersList.value = [];
    plansList.value = [];
    paymentsList.value = [];
    trainerList.value = [];
    attendanceLogs.value = [];
    recentCheckins.value = [];
    currentlyInGymList.value = [];
    syncNotificationLists();
  } finally {
    if (currentView.value === 'dashboard') {
      buildAdminDashboardChart();
    }
  }
}

function notificationId(notification: Record<string, unknown>) {
  const id = Number(notification.id ?? 0);
  return Number.isFinite(id) ? id : 0;
}

function isNotificationBusy(notification: Record<string, unknown>) {
  return notificationActionLoading.value === notificationId(notification);
}

function openNotificationDetail(notification: Record<string, unknown>) {
  selectedNotification.value = notification;
  showNotificationDetailModal.value = true;
}

async function markNotificationAsRead(notification: Record<string, unknown>) {
  const id = notificationId(notification);
  if (!id) return;

  notificationActionLoading.value = id;
  notificationError.value = '';

  try {
    const { data } = await adminNotifications.markRead(id);
    syncNotificationLists(data as { active?: unknown[]; history?: unknown[] });
    if (notificationList.value.length === 0 && notificationHistoryList.value.length > 0) {
      notificationTab.value = 'history';
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    notificationError.value = ax.response?.data?.message || 'Failed to mark notification as read.';
  } finally {
    notificationActionLoading.value = null;
  }
}

async function dismissNotification(notification: Record<string, unknown>) {
  const id = notificationId(notification);
  if (!id) return;

  notificationActionLoading.value = id;
  notificationError.value = '';

  try {
    const { data } = await adminNotifications.dismiss(id);
    syncNotificationLists(data as { active?: unknown[]; history?: unknown[] });
    if (notificationList.value.length === 0 && notificationHistoryList.value.length > 0) {
      notificationTab.value = 'history';
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    notificationError.value = ax.response?.data?.message || 'Failed to dismiss notification.';
  } finally {
    notificationActionLoading.value = null;
  }
}

async function markAllNotificationsAsRead() {
  if (notificationList.value.length === 0) return;

  notificationActionLoading.value = 'all';
  notificationError.value = '';

  try {
    const { data } = await adminNotifications.markAllRead();
    syncNotificationLists(data as { active?: unknown[]; history?: unknown[] });
    if (notificationHistoryList.value.length > 0) {
      notificationTab.value = 'history';
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    notificationError.value = ax.response?.data?.message || 'Failed to mark all notifications as read.';
  } finally {
    notificationActionLoading.value = null;
  }
}

async function loadTrainerAttendance() {
  if (currentView.value !== 'attendance') return;
  try {
    const { data } = await adminTrainerAttendance.list({ date: new Date().toISOString().slice(0, 10) });
    trainerAttendanceList.value = Array.isArray((data as { attendance?: unknown[] })?.attendance)
      ? (data as { attendance: Record<string, unknown>[] }).attendance
      : [];
  } catch {
    trainerAttendanceList.value = [];
  }
}

async function refreshAdminViewData(view = currentView.value) {
  if (!isLoggedIn.value) return;

  if (view === 'dashboard') {
    await loadData();
    return;
  }

  if (view === 'attendance') {
    await Promise.all([loadData(), loadTrainerAttendance()]);
    return;
  }

  if (view === 'members' || view === 'payments' || view === 'trainer' || view === 'notifications') {
    await loadData();
    return;
  }

  if (view === 'classes') {
    await Promise.all([loadData(), loadClassesData()]);
    return;
  }

  if (view === 'membership-plans') {
    await loadMembershipPlansData();
    return;
  }

  if (view === 'reports') {
    await loadReportsData();
    return;
  }

  if (view === 'settings') {
    await Promise.all([loadGymInfo(), loadAdminProfile()]);
  }
}

function stopAdminPortalAutoRefresh() {
  if (adminPortalRefreshTimer != null) {
    window.clearInterval(adminPortalRefreshTimer);
    adminPortalRefreshTimer = null;
  }
}

function startAdminPortalAutoRefresh() {
  stopAdminPortalAutoRefresh();
  if (!isLoggedIn.value) return;

  adminPortalRefreshTimer = window.setInterval(() => {
    void refreshAdminViewData();
  }, 15000);
}

async function loadGymInfo() {
  try {
    const { data } = await gymInfo.get();
    const res = data as { gym?: Record<string, unknown> };
    gymInfoData.value = res?.gym || (data as Record<string, unknown>) || null;
    const gym = gymInfoData.value as Record<string, unknown> | null;
    if (gym) {
      gymForm.value.name = String(gym.name || 'FitLife Gym');
      gymForm.value.email = String(gym.email || '');
      gymForm.value.phone = String(gym.phone || '');
      gymForm.value.gcash_number = String(gym.gcash_number || '');
      gymForm.value.gcash_account_name = String(gym.gcash_account_name || '');
      gymForm.value.gcash_qr_url = String(gym.gcash_qr_url || '');
      gymForm.value.address = String(gym.address || '');
      gymForm.value.textbee_device_id = String(gym.textbee_device_id || '');
      gymForm.value.textbee_sim_subscription_id = String(gym.textbee_sim_subscription_id || '');
      gymForm.value.operating_hours = (gym.operating_hours as typeof gymForm.value.operating_hours) || gymForm.value.operating_hours;
    }
  } catch {
    gymInfoData.value = null;
  }
}

async function loadAdminProfile() {
  try {
    const { data } = await adminAuth.profile();
    const res = data as { success?: boolean; admin?: Record<string, unknown> };
    if (res?.admin) {
      const a = res.admin;
      adminProfileForm.value.name = String(a.name || a.full_name || '');
      adminProfileForm.value.address = String(a.address || '');
      adminProfileForm.value.phone = String(a.phone || '');
    }
  } catch {
    // ignore
  }
}

async function loadMembershipPlansData() {
  try {
    const [planRes, revRes] = await Promise.all([
      membershipPlans.list({ t: Date.now() }),
      reports.revenue().catch(() => ({ data: {} })),
    ]);
    plansList.value = Array.isArray(planRes.data) ? planRes.data : [];
    reportRevenue.value = (revRes.data as Record<string, unknown>) || null;
  } catch {
    plansList.value = [];
  }
}

async function refreshMembershipPlansData() {
  formLoading.value = true;
  formError.value = '';
  try {
    await Promise.all([
      loadMembershipPlansData(),
      loadReportsData(),
      loadData(),
    ]);
  } catch {
    formError.value = 'Refresh failed. Please try again.';
  } finally {
    formLoading.value = false;
  }
}

// Load reports from same API as Blade (Overview/Revenue, Attendance, Membership) - use same DB so data matches
async function loadReportsData() {
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
    reportRevenue.value = null;
    reportAttendance.value = null;
    reportMembership.value = null;
  } finally {
    if (currentView.value === 'reports') {
      buildAdminReportCharts();
    }
  }
}

watch(currentView, (view) => {
  if (view === 'attendance') loadTrainerAttendance();
  if (view === 'membership-plans') loadMembershipPlansData();
  if (view === 'reports') {
    loadReportsData();
  } else {
    destroyAdminReportCharts();
  }
  if (view === 'dashboard') nextTick(() => buildAdminDashboardChart());
  else destroyAdminDashboardChart();
  if (view === 'notifications') loadData();
  if (view === 'classes') {
    loadData();
    loadClassesData();
  }
  if (view === 'settings') {
    formError.value = '';
    loadGymInfo();
    loadAdminProfile();
  }
  startAdminPortalAutoRefresh();
});

watch(isLoggedIn, (loggedIn) => {
  if (loggedIn) {
    startAdminPortalAutoRefresh();
  } else {
    stopAdminPortalAutoRefresh();
  }
});

watch(reportsTab, () => {
  if (currentView.value === 'reports') {
    buildAdminReportCharts();
  }
});

// Refetch Membership Plans when window regains focus (e.g. after editing in Blade)
onMounted(() => {
  window.addEventListener('focus', () => {
    if (currentView.value === 'membership-plans') loadMembershipPlansData();
  });
});

function closeModals() {
  showAddMemberModal.value = false;
  showAddPlanModal.value = false;
  showAddPaymentModal.value = false;
  showAddTrainerModal.value = false;
  showChangePasswordModal.value = false;
  showViewMemberModal.value = false;
  showSendSmsModal.value = false;
  smsMessage.value = '';
  showMemberQrScannerModal.value = false;
  showNotificationDetailModal.value = false;
  showReceiptModal.value = false;
  showViewTrainerModal.value = false;
  showAddClassModal.value = false;
  editingClassId.value = null;
  selectedMember.value = null;
  selectedNotification.value = null;
  selectedPlanForEdit.value = null;
  selectedReceiptUrl.value = '';
  selectedTrainer.value = null;
  formError.value = '';
  void stopAdminQrScanner();
}

function viewReceipt(url: string) {
  selectedReceiptUrl.value = url;
  showReceiptModal.value = true;
}

function viewTrainer(s: Record<string, unknown>) {
  selectedTrainer.value = s;
  showViewTrainerModal.value = true;
}

function openMemberQrScannerModal() {
  formError.value = '';
  showMemberQrScannerModal.value = true;
  adminQrManualQrPayload.value = '';
  adminQrScannerError.value = '';
  adminQrScannerStatus.value = '';
}

function openAddMember() {
  selectedMember.value = null;
  memberForm.value = { full_name: '', email: '', phone: '', address: '', membership_plan_id: plansList.value[0] ? String((plansList.value[0] as Record<string, unknown>).id) : '' };
  showAddMemberModal.value = true;
  formError.value = '';
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
  if (planForm.value.features.length === 0) planForm.value.features.push('');
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
    await loadMembershipPlansData();
    closeModals();
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
    await loadMembershipPlansData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to update plan.';
  } finally {
    formLoading.value = false;
  }
}

/** The member's current plan, so a renewal payment defaults to it. */
function planIdForMember(memberId: string): string {
  const member = membersList.value.find((m) => String((m as Record<string, unknown>).id) === memberId) as Record<string, unknown> | undefined;
  const plan = plansList.value.find((p) => String((p as Record<string, unknown>).name) === String(member?.membership_plan ?? '')) as Record<string, unknown> | undefined;
  return plan ? String(plan.id) : '';
}

function planPrice(planId: string): string {
  const plan = plansList.value.find((p) => String((p as Record<string, unknown>).id) === planId) as Record<string, unknown> | undefined;
  return plan ? String(Number(plan.price) || 0) : '';
}

function openAddPayment() {
  const memberId = membersList.value[0] ? String((membersList.value[0] as Record<string, unknown>).id) : '';
  const planId = planIdForMember(memberId);
  paymentForm.value = {
    member_id: memberId,
    membership_plan_id: planId,
    amount: planPrice(planId),
    payment_date: new Date().toISOString().slice(0, 10),
    payment_method: 'Cash',
  };
  showAddPaymentModal.value = true;
  formError.value = '';
}

function onPaymentMemberChange() {
  paymentForm.value.membership_plan_id = planIdForMember(paymentForm.value.member_id);
  onPaymentPlanChange();
}

function onPaymentPlanChange() {
  if (paymentForm.value.membership_plan_id) paymentForm.value.amount = planPrice(paymentForm.value.membership_plan_id);
}

function openAddTrainer() {
  trainerForm.value = {
    full_name: '',
    email: '',
    phone: '',
    position: '',
    department: '',
    hire_date: new Date().toISOString().slice(0, 10),
    password: '',
    status: 'Active',
  };
  editingTrainerId.value = null;
  showAddTrainerModal.value = true;
  formError.value = '';
}

function openEditTrainer(s: Record<string, unknown>) {
  editingTrainerId.value = Number(s.id ?? 0) || null;
  trainerForm.value = {
    full_name: String(s.full_name || ''),
    email: String(s.email || ''),
    phone: String(s.phone || ''),
    position: String(s.position || ''),
    department: String(s.department || ''),
    hire_date: String(s.hire_date || '').slice(0, 10) || new Date().toISOString().slice(0, 10),
    password: '',
    status: String(s.status || 'Active') as 'Active' | 'Inactive' | 'On Leave',
  };
  showAddTrainerModal.value = true;
  formError.value = '';
}

function openChangePassword() {
  passwordForm.value = { current_password: '', new_password: '', confirm_password: '' };
  showChangePasswordModal.value = true;
  formError.value = '';
}

async function viewMember(m: Record<string, unknown>) {
  selectedMember.value = m;
  showViewMemberModal.value = true;
  if (m.id == null) return;
  try {
    const { data } = await members.get(Number(m.id));
    const full = (data as Record<string, unknown>) || {};
    selectedMember.value = {
      ...(selectedMember.value || {}),
      ...full,
    };
  } catch {
    // keep list payload if full fetch fails
  }
}

function editMember(m: Record<string, unknown>) {
  selectedMember.value = m;
  const planName = String(m.membership_plan || '');
  const plan = plansList.value.find((p) => String((p as Record<string, unknown>).name) === planName) as Record<string, unknown> | undefined;
  memberForm.value = {
    full_name: String(m.full_name || ''),
    email: String(m.email || ''),
    phone: String(m.phone || ''),
    address: editableStreetAddress(m.address),
    membership_plan_id: plan ? String(plan.id) : '',
  };
  showAddMemberModal.value = true;
  formError.value = '';
}

async function deleteMember(m: Record<string, unknown>) {
  if (!confirm('Delete this member?')) return;
  const id = m.id;
  if (id == null) return;
  formLoading.value = true;
  formError.value = '';
  try {
    await members.delete(Number(id));
    await loadData();
    closeModals();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Delete failed.';
  } finally {
    formLoading.value = false;
  }
}

function openSendSmsModal(m: Record<string, unknown>) {
  selectedMember.value = m;
  smsMessage.value = '';
  formError.value = '';
  showSendSmsModal.value = true;
}

async function submitSendSms() {
  const id = selectedMember.value?.id;
  if (id == null) return;
  if (!smsMessage.value.trim()) {
    formError.value = 'Please enter a message.';
    return;
  }
  formLoading.value = true;
  formError.value = '';
  try {
    const { data } = await members.sendSms(Number(id), { message: smsMessage.value.trim() });
    alert((data as { message?: string }).message || 'SMS sent.');
    closeModals();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to send SMS.';
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
    await loadData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to approve member.';
  } finally {
    formLoading.value = false;
  }
}

async function submitAddMember() {
  formLoading.value = true;
  formError.value = '';
  try {
    const plan = plansList.value.find((p) => String((p as Record<string, unknown>).id) === String(memberForm.value.membership_plan_id)) as Record<string, unknown> | undefined;
    const planName = plan ? String(plan.name) : '';
    if (!planName) {
      formError.value = 'Select a membership plan.';
      formLoading.value = false;
      return;
    }
    const payload = {
      full_name: memberForm.value.full_name,
      email: memberForm.value.email,
      phone: memberForm.value.phone || '',
      // keeps a self-registered member's emergency contact and birthday when the address is edited
      address: mergeMemberStreetAddress(selectedMember.value?.address, memberForm.value.address || ''),
      membership_plan: planName,
    };
    if (selectedMember.value && (selectedMember.value as Record<string, unknown>).id != null) {
      await members.update(Number((selectedMember.value as Record<string, unknown>).id), payload);
    } else {
      await members.create(payload);
    }
    await loadData();
    closeModals();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
    formError.value = ax.response?.data?.message || (ax.response?.data?.errors ? JSON.stringify(ax.response.data.errors) : 'Failed to save member.');
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
    await loadMembershipPlansData();
    await loadData();
    closeModals();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to save plan.';
  } finally {
    formLoading.value = false;
  }
}

async function submitAddPayment() {
  formLoading.value = true;
  formError.value = '';
  try {
    await payments.create({
      member_id: Number(paymentForm.value.member_id),
      membership_plan_id: paymentForm.value.membership_plan_id ? Number(paymentForm.value.membership_plan_id) : null,
      amount: Number(paymentForm.value.amount) || 0,
      payment_date: paymentForm.value.payment_date,
      payment_method: paymentForm.value.payment_method || 'Cash',
    });
    await loadData();
    closeModals();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to add payment.';
  } finally {
    formLoading.value = false;
  }
}

function paymentStatusClass(status: unknown) {
  const s = String(status || '').toLowerCase();
  if (s === 'paid') return 'paid';
  if (s === 'cancelled') return 'cancelled';
  if (s === 'overdue') return 'overdue';
  return 'pending';
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
    const verifyNote = status === 'Paid'
      ? 'Verified by admin.'
      : 'Rejected by admin.';
    const mergedNotes = existingNotes ? `${existingNotes} ${verifyNote}` : verifyNote;

    await payments.update(id, {
      status,
      notes: mergedNotes,
    });

    await loadData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to update payment status.';
  } finally {
    processingPaymentId.value = null;
  }
}

async function submitAddTrainer() {
  formLoading.value = true;
  formError.value = '';
  try {
    const payload = {
      full_name: trainerForm.value.full_name,
      email: trainerForm.value.email,
      phone: trainerForm.value.phone || '',
      position: trainerForm.value.position || '',
      department: trainerForm.value.department || '',
      hire_date: trainerForm.value.hire_date,
      status: trainerForm.value.status || 'Active',
      password: trainerForm.value.password,
    };
    if (editingTrainerId.value) {
      const updateData = trainerForm.value.password ? payload : { ...payload, password: undefined };
      await trainer.update(editingTrainerId.value, updateData);
    } else {
      await trainer.create(payload);
    }
    await loadData();
    closeModals();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to save staff account.';
  } finally {
    formLoading.value = false;
  }
}

async function deleteTrainer(s: Record<string, unknown>) {
  if (!confirm('Delete this staff account?')) return;
  const id = s.id;
  if (id == null) return;
  formLoading.value = true;
  formError.value = '';
  try {
    await trainer.delete(Number(id));
    await loadData();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to delete staff account.';
  } finally {
    formLoading.value = false;
  }
}

async function loadClassesData() {
  try {
    const { data } = await gymClasses.list();
    classesList.value = Array.isArray((data as { classes?: unknown[] })?.classes)
      ? ((data as { classes: Record<string, unknown>[] }).classes)
      : [];
  } catch {
    classesList.value = [];
  }
}

function openAddClass() {
  editingClassId.value = null;
  classForm.value = { name: '', trainer_id: '', day_of_week: 'Monday', start_time: '', end_time: '', capacity: '10' };
  showAddClassModal.value = true;
  formError.value = '';
}

function openEditClass(c: Record<string, unknown>) {
  editingClassId.value = Number(c.id ?? 0) || null;
  classForm.value = {
    name: String(c.name || ''),
    trainer_id: c.trainer_id != null ? String(c.trainer_id) : '',
    day_of_week: String(c.day_of_week || 'Monday'),
    start_time: String(c.start_time || ''),
    end_time: String(c.end_time || ''),
    capacity: String(c.capacity ?? '10'),
  };
  showAddClassModal.value = true;
  formError.value = '';
}

async function submitClass() {
  formLoading.value = true;
  formError.value = '';
  try {
    const payload = {
      name: classForm.value.name,
      trainer_id: classForm.value.trainer_id || null,
      day_of_week: classForm.value.day_of_week,
      start_time: classForm.value.start_time,
      end_time: classForm.value.end_time,
      capacity: Number(classForm.value.capacity) || 1,
    };
    if (editingClassId.value) {
      await gymClasses.update(editingClassId.value, payload);
    } else {
      await gymClasses.create(payload);
    }
    await loadClassesData();
    closeModals();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to save class.';
  } finally {
    formLoading.value = false;
  }
}

async function deleteClass(c: Record<string, unknown>) {
  if (!confirm('Delete this class?')) return;
  const id = c.id;
  if (id == null) return;
  try {
    await gymClasses.delete(Number(id));
    await loadClassesData();
  } catch {
    // Non-critical — the list simply won't reflect the deletion this time.
  }
}

async function submitChangePassword() {
  if (passwordForm.value.new_password !== passwordForm.value.confirm_password) {
    formError.value = 'Passwords do not match.';
    return;
  }
  formLoading.value = true;
  formError.value = '';
  try {
    await adminAuth.changePassword({
      current_password: passwordForm.value.current_password,
      new_password: passwordForm.value.new_password,
      confirm_password: passwordForm.value.confirm_password,
    });
    closeModals();
    passwordForm.value = { current_password: '', new_password: '', confirm_password: '' };
    adminProfileNotice.value = 'Password changed successfully. Use your new password the next time you sign in.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Failed to change password.';
  } finally {
    formLoading.value = false;
  }
}

async function updateAdminProfile() {
  formLoading.value = true;
  formError.value = '';
  adminProfileNotice.value = '';
  try {
    await adminAuth.updateProfile({
      name: adminProfileForm.value.name,
      address: adminProfileForm.value.address,
      phone: adminProfileForm.value.phone,
    });
    formError.value = '';
    if (user.value) user.value.name = adminProfileForm.value.name;
    await loadAdminProfile();
    adminProfileNotice.value = 'Profile updated successfully.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Update failed.';
  } finally {
    formLoading.value = false;
  }
}

async function updateGymInfo() {
  formLoading.value = true;
  formError.value = '';
  try {
    const payload = new FormData();
    payload.append('name', gymForm.value.name || '');
    payload.append('email', gymForm.value.email || '');
    payload.append('phone', gymForm.value.phone || '');
    payload.append('gcash_number', gymForm.value.gcash_number || '');
    payload.append('gcash_account_name', gymForm.value.gcash_account_name || '');
    payload.append('address', gymForm.value.address || '');
    payload.append('textbee_device_id', gymForm.value.textbee_device_id || '');
    payload.append('textbee_sim_subscription_id', gymForm.value.textbee_sim_subscription_id || '');
    Object.entries(gymForm.value.operating_hours || {}).forEach(([day, value]) => {
      payload.append(`operating_hours[${day}]`, String(value ?? ''));
    });
    if (gcashQrFile.value) {
      payload.append('gcash_qr', gcashQrFile.value);
    }

    await gymInfo.update(payload);
    formError.value = '';
    gcashQrFile.value = null;
    await loadGymInfo();
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    formError.value = ax.response?.data?.message || 'Update failed.';
  } finally {
    formLoading.value = false;
  }
}

function onGcashQrSelected(event: Event) {
  const input = event.target as HTMLInputElement | null;
  gcashQrFile.value = input?.files?.[0] ?? null;
}

const excelDownloading = ref(false);
const excelError = ref('');

/** Downloads the branch report (summary, members, payments, attendance, plans) as an .xlsx file. */
async function downloadExcelReport() {
  if (excelDownloading.value) return;
  excelDownloading.value = true;
  excelError.value = '';
  try {
    const response = await reports.exportExcel();
    const disposition = String(response.headers?.['content-disposition'] || '');
    const fromServer = /filename="?([^";]+)"?/i.exec(disposition)?.[1];
    const slug = gymDisplayName.value.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'FitGym';
    const url = URL.createObjectURL(response.data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = fromServer || `${slug}_Report_${new Date().toISOString().slice(0, 10)}.xlsx`;
    a.click();
    URL.revokeObjectURL(url);
  } catch {
    excelError.value = 'Could not download the Excel report. Try again.';
  } finally {
    excelDownloading.value = false;
  }
}

function filteredMembers() {
  let list = membersList.value;
  const q = (memberSearch.value || '').toLowerCase();
  if (q) {
    list = list.filter(
      (m) =>
        String(m.full_name || '').toLowerCase().includes(q) ||
        String(m.email || '').toLowerCase().includes(q) ||
        String(m.member_id || '').toLowerCase().includes(q)
    );
  }
  const statusFilter = memberStatusFilter.value;
  const planFilter = memberPlanFilter.value;
  if (statusFilter) {
    const today = new Date().toISOString().slice(0, 10);
    const in30 = new Date();
    in30.setDate(in30.getDate() + 30);
    const in30Str = in30.toISOString().slice(0, 10);
    list = list.filter((m) => {
      const status = String(m.status || '').toLowerCase();
      const expiry = m.expiry_date as string | undefined;
      if (statusFilter === 'active') return status === 'active' && (!expiry || expiry >= today);
      if (statusFilter === 'pending') return status === 'pending';
      if (statusFilter === 'expired') return expiry && expiry < today;
      if (statusFilter === 'ending soon') return expiry && expiry >= today && expiry <= in30Str;
      return true;
    });
  }
  if (planFilter) {
    list = list.filter((m) => String(m.membership_plan || '').toLowerCase() === planFilter.toLowerCase());
  }
  return list;
}

/** Status as shown in both portals: Pending Approval, Expired and Ending Soon (30 days) come from the dates. */
function memberStatusLabel(status: unknown, expiryDate?: string): string {
  const s = String(status || '').toLowerCase();
  if (s === 'pending') return 'Pending Approval';
  const today = new Date().toISOString().slice(0, 10);
  if (expiryDate && expiryDate < today) return 'Expired';
  if (s === 'active' && expiryDate) {
    const in30 = new Date();
    in30.setDate(in30.getDate() + 30);
    if (expiryDate <= in30.toISOString().slice(0, 10)) return 'Ending Soon';
  }
  if (s === 'expired') return 'Expired';
  if (s === 'ending soon') return 'Ending Soon';
  return s || 'Active';
}

function memberStatusBadge(status: unknown, expiryDate?: string): string {
  const label = memberStatusLabel(status, expiryDate).toLowerCase();
  if (label === 'active') return 'active';
  if (label === 'pending approval' || label === 'ending soon') return 'pending';
  return 'inactive';
}

function filteredAttendanceLogs() {
  const list = attendanceLogs.value;
  const q = (attendanceSearch.value || '').toLowerCase();
  if (!q) return list;
  return list.filter((l) => String((l as Record<string, unknown>).member_name || '').toLowerCase().includes(q));
}

function parseAmount(value: unknown): number {
  const amount = Number(value ?? 0);
  return Number.isFinite(amount) ? amount : 0;
}

function formatCurrency(value: unknown): string {
  return `PHP ${parseAmount(value).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function memberEmergencyContact(address: unknown): string {
  const meta = parseMemberAddressMeta(address);
  const contact = meta?.emergency_contact as Record<string, unknown> | undefined;
  const name = String(contact?.name || '').trim();
  const phone = String(contact?.phone || '').trim();
  const parts = [name, phone].filter(Boolean);
  return parts.length ? parts.join(' • ') : '-';
}

function memberDateOfBirth(address: unknown): string {
  const meta = parseMemberAddressMeta(address);
  const dob = String(meta?.date_of_birth || '').trim();
  return dob || '-';
}

function memberStreetAddress(address: unknown): string {
  return editableStreetAddress(address) || '-';
}

function revenueMonthly(): string {
  return formatCurrency(reportRevenue.value?.monthly_revenue);
}

function plansWithStats(): Record<string, unknown>[] {
  const plans = plansList.value;
  const revPlans = (reportRevenue.value?.plans as Array<{ id: number; name?: string; member_count: number; price?: number }>) || [];
  return plans.map((p) => {
    const rev = revPlans.find((r) => Number(r.id) === Number((p as Record<string, unknown>).id));
    const count = rev?.member_count ?? 0;
    const price = Number((p as Record<string, unknown>).price) || 0;
    return { ...p, member_count: count, monthly_revenue: price * count };
  });
}

function activePlansWithStats(): Record<string, unknown>[] {
  // Show both active and deactivated plans so admin can reactivate.
  return plansWithStats().sort((a, b) => {
    const aDeactivated = Number(Boolean((a as Record<string, unknown>).is_deactivated));
    const bDeactivated = Number(Boolean((b as Record<string, unknown>).is_deactivated));
    return aDeactivated - bDeactivated;
  });
}

function mostSubscribedPlanName(): string {
  const revPlans = (reportRevenue.value?.plans as Array<{ name?: string; member_count: number }>) || [];
  if (revPlans.length === 0) return '-';
  const top = revPlans.reduce((a, b) => (a.member_count >= b.member_count ? a : b), revPlans[0]);
  return top.member_count > 0 ? (top.name || '-') : '-';
}

function expiringSoon(): number {
  const r = reportMembership.value;
  if (!r || typeof r.expiring_soon !== 'number') return 0;
  return r.expiring_soon as number;
}

function destroyAdminDashboardChart() {
  if (adminDashboardChart) {
    adminDashboardChart.destroy();
    adminDashboardChart = null;
  }
}

function buildAdminDashboardChart() {
  if (currentView.value !== 'dashboard' || !dashboardChartCanvas.value) return;
  destroyAdminDashboardChart();
  const labels = ['Members', 'Plans', 'Active Members', 'Expiring Soon'];
  const values = [
    Number(membersList.value.length || 0),
    Number(plansList.value.length || 0),
    Number(reportAttendance.value?.active_members ?? reportRevenue.value?.active_members ?? 0),
    Number(reportMembership.value?.expiring_soon ?? 0),
  ];
  adminDashboardChart = new Chart(dashboardChartCanvas.value, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Dashboard Metrics',
          data: values,
          backgroundColor: ['#8DFF00', '#00BFFF', '#8A2BE2', '#FF6B6B'],
          borderColor: ['#8DFF00', '#00BFFF', '#8A2BE2', '#FF6B6B'],
          borderWidth: 1,
          borderRadius: 8,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { labels: { color: '#A8A196' } } },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(168,161,150,0.15)' },
          ticks: { color: '#A8A196' },
        },
        x: {
          grid: { display: false },
          ticks: { color: '#A8A196' },
        },
      },
    } as ChartConfiguration<'bar'>['options'],
  });
}

const overviewChartData = computed(() => {
  const daily = (reportAttendance.value?.daily_stats as Array<{ date?: string; count?: number }>) || [];
  const last7 = daily.slice(-7);
  const labels = last7.length
    ? last7.map((d) => {
        const dt = d.date ? new Date(String(d.date)) : null;
        return dt && !Number.isNaN(dt.getTime())
          ? dt.toLocaleDateString('en-US', { weekday: 'short' })
          : 'Day';
      })
    : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const checkins = last7.length ? last7.map((d) => Number(d.count ?? 0)) : [0, 0, 0, 0, 0, 0, 0];
  const monthlyRevenue = Number(reportRevenue.value?.monthly_revenue ?? 0);
  const totalCheckins = checkins.reduce((s, n) => s + n, 0);
  const revenue = checkins.map((c) => {
    if (monthlyRevenue <= 0 || totalCheckins <= 0) return 0;
    return Math.round((monthlyRevenue / totalCheckins) * c);
  });
  return { labels, checkins, revenue };
});

const attendanceTrendData = computed(() => {
  const daily = (reportAttendance.value?.daily_stats as Array<{ date?: string; count?: number }>) || [];
  const last14 = daily.slice(-14);
  const labels = last14.length
    ? last14.map((d) => {
        const dt = d.date ? new Date(String(d.date)) : null;
        return dt && !Number.isNaN(dt.getTime())
          ? dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
          : 'Day';
      })
    : ['No Data'];
  const values = last14.length ? last14.map((d) => Number(d.count ?? 0)) : [0];
  return { labels, values };
});

const membershipDistributionData = computed(() => {
  const plans = (reportRevenue.value?.plans as Array<{ name?: string; member_count?: number }>) || [];
  if (plans.length > 0) {
    return {
      labels: plans.map((p) => String(p.name || 'Plan')),
      values: plans.map((p) => Number(p.member_count ?? 0)),
    };
  }
  return {
    labels: ['Active Members', 'Expiring Soon', 'Others'],
    values: [
      Number(reportMembership.value?.active_members ?? 0),
      Number(reportMembership.value?.expiring_soon ?? 0),
      Math.max(
        0,
        Number(reportMembership.value?.total_members ?? 0)
          - Number(reportMembership.value?.active_members ?? 0)
          - Number(reportMembership.value?.expiring_soon ?? 0)
      ),
    ],
  };
});

function destroyAdminReportCharts() {
  Object.values(adminReportCharts.value).forEach((chart) => chart?.destroy());
  adminReportCharts.value = {};
}

function buildAdminReportCharts() {
  if (currentView.value !== 'reports') return;
  destroyAdminReportCharts();

  nextTick(() => {
    const axisOptions = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: '#A8A196' } },
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(168,161,150,0.15)' },
          ticks: { color: '#A8A196' },
        },
        x: {
          grid: { display: false },
          ticks: { color: '#A8A196' },
        },
      },
    };
    const pieOptions = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { labels: { color: '#A8A196' } } },
    };

    if (reportsTab.value === 'overview' && overviewChartCanvas.value) {
      const overview = overviewChartData.value;
      adminReportCharts.value.overview = new Chart(overviewChartCanvas.value, {
        type: 'bar',
        data: {
          labels: overview.labels,
          datasets: [
            {
              label: 'Check-ins',
              data: overview.checkins,
              backgroundColor: 'rgba(0,191,255,0.45)',
              borderColor: '#00BFFF',
              borderWidth: 1,
              borderRadius: 6,
            },
            {
              label: 'Revenue (PHP)',
              data: overview.revenue,
              backgroundColor: 'rgba(141,255,0,0.35)',
              borderColor: '#8DFF00',
              borderWidth: 1,
              borderRadius: 6,
            },
          ],
        },
        options: axisOptions as ChartConfiguration<'bar'>['options'],
      });
    }

    if (reportsTab.value === 'attendance' && attendanceChartCanvas.value) {
      const trend = attendanceTrendData.value;
      adminReportCharts.value.attendance = new Chart(attendanceChartCanvas.value, {
        type: 'line',
        data: {
          labels: trend.labels,
          datasets: [
            {
              label: 'Daily Check-ins',
              data: trend.values,
              borderColor: '#8A2BE2',
              backgroundColor: 'rgba(138,43,226,0.2)',
              pointBackgroundColor: '#8A2BE2',
              pointBorderColor: '#fff',
              tension: 0.3,
              fill: true,
            },
          ],
        },
        options: axisOptions as ChartConfiguration<'line'>['options'],
      });
    }

    if (reportsTab.value === 'membership' && membershipChartCanvas.value) {
      const data = membershipDistributionData.value;
      adminReportCharts.value.membership = new Chart(membershipChartCanvas.value, {
        type: 'doughnut',
        data: {
          labels: data.labels,
          datasets: [
            {
              label: 'Members',
              data: data.values,
              backgroundColor: ['#8DFF00', '#00BFFF', '#8A2BE2', '#FF6B6B', '#FFD700'],
              borderColor: '#151515',
              borderWidth: 2,
              hoverOffset: 6,
            },
          ],
        },
        options: pieOptions as ChartConfiguration<'doughnut'>['options'],
      });
    }
  });
}

async function handleLogin() {
  loginError.value = '';
  loginLoading.value = true;
  try {
    const { data } = await adminAuth.login({
      email: loginForm.value.email,
      password: loginForm.value.password,
      remember: loginForm.value.remember,
    });
    if (data.success && data.user) {
      user.value = data.user;
      isLoggedIn.value = true;
      await Promise.all([loadData(), loadGymInfo()]);
      startAdminPortalAutoRefresh();
      nextTick(() => buildAdminDashboardChart());
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    loginError.value = ax.response?.data?.message || 'Login failed. Please try again.';
  } finally {
    loginLoading.value = false;
  }
}

async function handleLogout() {
  try {
    await adminAuth.logout();
  } finally {
    user.value = null;
    isLoggedIn.value = false;
    showNotificationsPanel.value = false;
    stopAdminPortalAutoRefresh();
    // Back to the single sign-in page; the reload also refreshes the CSRF token.
    goToEntry('admin');
  }
}

const navItems = [
  { id: 'dashboard', label: 'Dashboard', description: 'Overview and quick actions', icon: 'fas fa-th-large' },
  { id: 'members', label: 'Members', description: 'Accounts, status, and details', icon: 'fas fa-users' },
  { id: 'membership-plans', label: 'Membership Plans', description: 'Plans, pricing, and visibility', icon: 'fas fa-calendar-alt' },
  { id: 'attendance', label: 'Attendance', description: 'Logs, check-ins, and gym activity', icon: 'fas fa-clipboard-list' },
  { id: 'classes', label: 'Classes', description: 'Weekly class schedule for members', icon: 'fas fa-calendar-check' },
  { id: 'payments', label: 'Payments', description: 'Billing, verification, and receipts', icon: 'fas fa-credit-card' },
  { id: 'reports', label: 'Reports', description: 'Revenue, attendance, and insights', icon: 'fas fa-chart-bar' },
  { id: 'trainer', label: 'Staff', description: 'Staff accounts and permissions', icon: 'fas fa-user-tie' },
  { id: 'notifications', label: 'Notifications', description: 'Announcements and member updates', icon: 'fas fa-bell' },
  { id: 'settings', label: 'Settings', description: 'Profile, gym info, and preferences', icon: 'fas fa-cog' },
] as const;

const currentViewInfo = computed(() => navItems.find((item) => item.id === currentView.value) ?? navItems[0]);
const adminDisplayName = computed(() => user.value?.name || 'Admin User');
const adminInitials = computed(() =>
  adminDisplayName.value
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || 'AD'
);
const adminRoleLabel = computed(() => user.value?.role || 'Admin');
const activeNotificationCount = computed(() => notificationList.value.length);

function closeNotificationsPanel() {
  showNotificationsPanel.value = false;
}

useSessionGuard({
  role: 'admin',
  fetchUser: adminAuth.user,
  currentEmail: () => user.value?.email,
  isActive: () => isLoggedIn.value,
});

onMounted(async () => {
  await checkAuth();
  startAdminPortalAutoRefresh();
});
onUnmounted(() => {
  stopAdminPortalAutoRefresh();
  destroyAdminDashboardChart();
  destroyAdminReportCharts();
});
</script>

<template>
  <Head :title="`${gymDisplayName} - Admin Portal`">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
  </Head>
  <div class="fitgym-admin admin-portal fg-marble-bg">
    <div v-if="!isLoggedIn && !loading" class="admin-auth-shell flex min-h-screen items-center justify-center p-4">
      <div class="admin-auth-card w-full max-w-md rounded-2xl border p-6 sm:p-8">
        <div class="text-center">
          <p class="admin-auth-eyebrow">Admin Workspace</p>
          <h1 class="admin-auth-title">Admin Portal</h1>
          <p class="admin-auth-subtitle">Manage members, payments, reports, and staff from one workspace.</p>
        </div>
        <div class="admin-auth-role-badge">Gym Admin Access</div>
        <form @submit.prevent="handleLogin" class="login-form admin-login-form">
          <div class="form-group">
            <label>Email Address</label>
            <input
              v-model="loginForm.email"
              type="email"
              class="form-input"
              placeholder="admin@example.com"
              required
            />
          </div>
          <div class="form-group">
            <div class="admin-auth-label-row">
              <label>Password</label>
              <a href="/forgot-password" class="forgot-password admin-auth-link">Forgot password?</a>
            </div>
            <div class="password-input-wrapper">
              <input
                v-model="loginForm.password"
                :type="showPassword ? 'text' : 'password'"
                class="form-input"
                placeholder="Enter your password"
                required
              />
              <i
                :class="['fas password-toggle', showPassword ? 'fa-eye-slash' : 'fa-eye']"
                @click="showPassword = !showPassword"
              />
            </div>
          </div>
          <div class="form-options admin-auth-options">
            <label class="checkbox-label">
              <input v-model="loginForm.remember" type="checkbox" value="1" />
              <span>Remember me</span>
            </label>
          </div>
          <div v-if="loginError" class="error-message admin-auth-error">{{ loginError }}</div>
          <button type="submit" class="btn-signin admin-auth-primary" :disabled="loginLoading">
            {{ loginLoading ? 'Signing in...' : 'Sign In' }}
          </button>
        </form>
      </div>
    </div>

    <div v-else-if="loading" class="admin-auth-shell flex min-h-screen items-center justify-center p-4">
      <div class="admin-auth-card w-full max-w-md rounded-2xl border p-8 text-center">
        <p class="admin-auth-eyebrow">Admin Workspace</p>
        <p class="admin-auth-subtitle">Loading admin workspace...</p>
      </div>
    </div>

    <div v-else class="sm:p-4 lg:p-6">
      <div class="admin-shell fg-shell fg-marble-bg mx-auto flex min-h-[calc(100vh-2rem)] max-w-[1500px] overflow-hidden rounded-[1.75rem] border shadow-2xl">
        <div v-if="mobileNavOpen" class="fg-nav-backdrop" aria-hidden="true" @click="closeMobileNav" />
        <aside id="admin-sidebar" class="admin-sidebar fg-sidebar flex w-[18rem] shrink-0 flex-col px-5 py-8" :class="{ 'is-open': mobileNavOpen }">
          <div class="flex items-start justify-between gap-3 pb-6">
            <div class="min-w-0">
              <h1 class="text-2xl font-bold break-words text-[var(--text-primary)]">{{ gymDisplayName }}</h1>
              <p class="mt-1 text-sm text-[var(--text-secondary)]">Admin Portal</p>
            </div>
            <button type="button" class="fg-menu-btn fg-sidebar-close" aria-label="Close navigation menu" @click="closeMobileNav">
              <X class="h-5 w-5" aria-hidden="true" />
            </button>
          </div>

          <div class="admin-profile-card rounded-2xl border p-4">
            <div class="flex items-center gap-3">
              <div class="admin-avatar flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-2xl text-lg font-bold">
                <img v-if="userPhotoUrl" :src="userPhotoUrl" alt="" class="h-full w-full object-cover" />
                <template v-else>{{ adminInitials }}</template>
              </div>
              <div class="min-w-0">
                <p class="truncate font-semibold text-[var(--text-primary)]">{{ adminDisplayName }}</p>
                <p class="truncate text-xs text-[var(--text-secondary)]">{{ adminRoleLabel }}</p>
              </div>
            </div>
          </div>

          <nav class="mt-6 flex flex-1 flex-col gap-2">
            <button
              v-for="item in navItems"
              :key="item.id"
              type="button"
              class="admin-nav-item flex w-full items-start rounded-xl px-4 py-3 text-left transition-colors"
              :class="{ active: currentView === item.id }"
              @click="currentView = item.id; closeNotificationsPanel(); closeMobileNav()"
            >
              <span class="min-w-0">
                <span class="block text-sm font-semibold">{{ item.label }}</span>
                <span class="mt-1 block text-xs opacity-70">{{ item.description }}</span>
              </span>
            </button>
          </nav>

          <div class="pt-6">
            <button type="button" class="admin-logout-btn w-full rounded-xl px-4 py-3 text-sm font-semibold transition-opacity hover:opacity-90" @click="handleLogout">
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
              aria-controls="admin-sidebar"
              :aria-expanded="mobileNavOpen"
              @click="mobileNavOpen = true"
            >
              <Menu class="h-5 w-5" aria-hidden="true" />
            </button>
            <div class="min-w-0">
              <p class="truncate text-base font-bold text-[var(--text-primary)]">{{ gymDisplayName }}</p>
              <p class="truncate text-xs text-[var(--text-secondary)]">Admin Portal</p>
            </div>
          </div>

          <header class="admin-header flex flex-col gap-4 border-b px-4 py-5 sm:px-6 sm:py-6 lg:flex-row lg:items-center lg:justify-between lg:px-10">
            <div class="min-w-0">
              <p class="text-sm font-semibold uppercase tracking-[0.24em] text-[var(--fg-gold)]">Admin Workspace</p>
              <h2 class="mt-2 text-2xl font-bold text-[var(--fg-ink)] sm:text-3xl">{{ currentViewInfo.label }}</h2>
              <p class="mt-2 max-w-2xl text-sm text-[var(--fg-ink-soft)]">{{ currentViewInfo.description }}</p>
            </div>

            <div class="relative flex flex-wrap items-center gap-3">
              <div class="admin-header-card rounded-xl border px-4 py-3">
                <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-secondary)]">Admin</p>
                <p class="mt-1 text-sm font-semibold break-all text-[var(--text-primary)]">{{ user?.email || 'No email' }}</p>
              </div>
              <button
                type="button"
                class="admin-header-button flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold transition-opacity hover:opacity-90"
                @click="showNotificationsPanel = !showNotificationsPanel"
              >
                <span>Notifications</span>
                <span v-if="activeNotificationCount" class="admin-header-badge flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1 text-[11px] font-bold">
                  {{ activeNotificationCount }}
                </span>
              </button>
              <div class="admin-header-card rounded-xl border px-4 py-3">
                <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-secondary)]">Role</p>
                <p class="mt-1 text-sm font-semibold text-[var(--accent-green)]">{{ adminRoleLabel }}</p>
              </div>

              <div
                v-if="showNotificationsPanel"
                class="admin-notification-panel absolute right-0 top-full z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-2xl border shadow-2xl"
                @click.stop
              >
                <div class="border-b px-4 py-3">
                  <h3 class="font-semibold text-[var(--text-primary)]">Notifications</h3>
                </div>
                <div class="max-h-72 overflow-y-auto p-3">
                  <template v-if="notificationList.length === 0">
                    <p class="py-4 text-center text-sm text-[var(--text-secondary)]">No active notifications</p>
                  </template>
                  <template v-else>
                    <button
                      v-for="n in notificationList.slice(0, 4)"
                      :key="String((n as Record<string, unknown>).id ?? (n as Record<string, unknown>).created_at ?? (n as Record<string, unknown>).message ?? '')"
                      type="button"
                      class="admin-notification-item mb-2 w-full rounded-xl p-3 text-left transition-opacity hover:opacity-90"
                      @click="currentView = 'notifications'; closeNotificationsPanel()"
                    >
                      <p class="text-sm font-medium text-[var(--text-primary)]">
                        {{ String((n as Record<string, unknown>).title || (n as Record<string, unknown>).notification_type || 'Active Notification') }}
                      </p>
                      <p class="mt-1 text-xs leading-5 text-[var(--text-secondary)]">
                        {{ String((n as Record<string, unknown>).message || (n as Record<string, unknown>).description || 'Open the notification center for more details.') }}
                      </p>
                    </button>
                  </template>
                  <button
                    v-if="notificationList.length > 0"
                    type="button"
                    class="admin-notification-open mt-2 w-full rounded-xl px-4 py-3 text-sm font-semibold transition-opacity hover:opacity-90"
                    @click="currentView = 'notifications'; closeNotificationsPanel()"
                  >
                    Open Notification Center
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

          <main class="admin-main flex-1 overflow-x-hidden overflow-y-auto px-4 py-5 sm:px-6 sm:py-6 lg:px-10">

        <div v-if="currentView === 'dashboard'" class="view active">
          <div class="metrics-grid">
            <div class="metric-card">
              <div class="metric-icon green">
                <i class="fas fa-users"></i>
              </div>
              <div class="metric-content">
                <h3>{{ membersList.length }}</h3>
                <p>Total Members</p>
                <span>Registered members</span>
              </div>
            </div>
            <div class="metric-card">
              <div class="metric-icon blue">
                <i class="fas fa-calendar-alt"></i>
              </div>
              <div class="metric-content">
                <h3>{{ plansList.length }}</h3>
                <p>Membership Plans</p>
                <span>Active plans</span>
              </div>
            </div>
            <div class="metric-card">
              <div class="metric-icon yellow">
                <i class="fas fa-coins"></i>
              </div>
              <div class="metric-content">
                <h3>{{ revenueMonthly() }}</h3>
                <p>Monthly Revenue</p>
                <span>From reports</span>
              </div>
            </div>
            <div class="metric-card">
              <div class="metric-icon purple">
                <i class="fas fa-star"></i>
              </div>
              <div class="metric-content">
                <h3>{{ expiringSoon() }}</h3>
                <p>Expiring Soon</p>
                <span>Memberships</span>
              </div>
            </div>
          </div>
          <div class="dashboard-card dashboard-chart-card">
            <h3>Dashboard Analytics</h3>
            <div class="reports-chart-wrap">
              <canvas ref="dashboardChartCanvas"></canvas>
            </div>
          </div>
          <div class="dashboard-grid">
            <div class="dashboard-card">
              <h3>Quick Actions</h3>
              <div class="quick-actions">
                <button type="button" class="quick-action-btn green" @click="openAddMember">
                  <i class="fas fa-user-plus"></i>
                  <span>Add New Member</span>
                </button>
                <button type="button" class="quick-action-btn blue" @click="openAddPayment">
                  <i class="fas fa-money-bill-wave"></i>
                  <span>Record Payment</span>
                </button>
                <button type="button" class="quick-action-btn pink" @click="currentView = 'reports'">
                  <i class="fas fa-file-alt"></i>
                  <span>Generate Report</span>
                </button>
              </div>
            </div>
          </div>
          <div class="recent-activity">
            <h3>Recent Activity</h3>
            <div class="activity-list">
              <template v-if="recentCheckins.length > 0">
                <div
                  v-for="c in recentCheckins.slice(0, 5)"
                  :key="String((c as Record<string, unknown>).id)"
                  class="activity-item"
                >
                  <div class="activity-avatar">{{ String((c as Record<string, unknown>).initials || '-').slice(0, 2) }}</div>
                  <div class="activity-content">
                    <p>{{ (c as Record<string, unknown>).member_name }}</p>
                  </div>
                  <span :class="['activity-tag', (c as Record<string, unknown>).status === 'Inside' ? 'yellow' : 'blue']">
                    {{ (c as Record<string, unknown>).status }}
                  </span>
                </div>
              </template>
              <template v-else-if="attendanceLogs.length > 0">
                <div
                  v-for="l in attendanceLogs.slice(0, 5)"
                  :key="String((l as Record<string, unknown>).id)"
                  class="activity-item"
                >
                  <div class="activity-avatar">{{ String((l as Record<string, unknown>).member_name || '').slice(0, 2).toUpperCase() }}</div>
                  <div class="activity-content">
                    <p>{{ (l as Record<string, unknown>).member_name }}</p>
                  </div>
                  <span class="activity-tag blue">{{ (l as Record<string, unknown>).status }}</span>
                </div>
              </template>
              <template v-else>
                <p class="placeholder-text">No recent activity.</p>
              </template>
            </div>
          </div>
        </div>

        <div v-if="currentView === 'members'" class="view active">
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
              <option v-for="p in plansList" :key="String((p as Record<string, unknown>).id)" :value="String((p as Record<string, unknown>).name)">
                {{ (p as Record<string, unknown>).name }}
              </option>
            </select>
            <button type="button" class="btn-add-member" @click="openAddMember">
              <i class="fas fa-plus"></i>
              <span>Add Member</span>
            </button>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Member</th>
                  <th>ID</th>
                  <th>Contact</th>
                  <th>Address</th>
                  <th>Plan</th>
                  <th>Status</th>
                  <th>Expiry</th>
                  <th>Payment</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in filteredMembers()" :key="String(m.id)">
                  <td>
                    <div class="member-cell">
                      <div class="member-avatar">{{ String(m.full_name || '').slice(0, 2).toUpperCase() }}</div>
                      <div>
                        <p>{{ m.full_name }}</p>
                        <span>{{ m.email }}</span>
                      </div>
                    </div>
                  </td>
                  <td>{{ m.member_id }}</td>
                  <td>{{ m.phone || '-' }}</td>
                  <td>{{ memberStreetAddress(m.address) }}</td>
                  <td>{{ m.membership_plan }}</td>
                  <td>
                    <span :class="['status-badge', memberStatusBadge(m.status, m.expiry_date as string)]">
                      {{ memberStatusLabel(m.status, m.expiry_date as string) }}
                    </span>
                  </td>
                  <td>{{ m.expiry_date }}</td>
                  <td>
                    <span :class="['status-badge', String(m.payment_status || '').toLowerCase() === 'paid' ? 'paid' : 'pending']">{{ m.payment_status }}</span>
                  </td>
                  <td>
                    <div class="action-buttons">
                      <button v-if="String(m.status).toLowerCase() === 'pending'" type="button" class="action-btn green" title="Approve Account" :disabled="formLoading" @click="approveMember(m)"><i class="fas fa-user-check"></i></button>
                      <button type="button" class="action-btn blue" title="View" @click="viewMember(m)"><i class="fas fa-eye"></i></button>
                      <button type="button" class="action-btn purple" title="Edit" @click="editMember(m)"><i class="fas fa-edit"></i></button>
                      <button type="button" class="action-btn green" title="Send SMS" @click="openSendSmsModal(m)"><i class="fas fa-sms"></i></button>
                      <button type="button" class="action-btn red" title="Delete" @click="deleteMember(m)"><i class="fas fa-trash"></i></button>
                    </div>
                  </td>
                </tr>
                <tr v-if="filteredMembers().length === 0">
                  <td colspan="9" class="empty-row">No members found.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="currentView === 'membership-plans'" class="view active">
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
                <h3>{{ reportRevenue?.total_members ?? membersList.length }}</h3>
                <p>Active members</p>
              </div>
            </div>
            <div class="summary-card">
              <i class="fas fa-coins"></i>
              <div>
                <h3>{{ revenueMonthly() }}</h3>
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
          <!-- Empty state: No Plans Yet -->
          <div v-if="plansList.length === 0" class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No Plans Yet</h3>
            <p>Get started by creating your first membership plan</p>
            <button type="button" class="btn-add-plan" @click="openAddPlan">
              <i class="fas fa-plus"></i>
              <span>Add Your First Plan</span>
            </button>
          </div>
          <!-- Plan cards (same layout as Blade) -->
          <div v-else class="plans-grid">
            <div
              v-for="p in activePlansWithStats()"
              :key="String((p as Record<string, unknown>).id)"
              :class="['plan-card', (p as Record<string, unknown>).is_popular ? 'popular' : '', (p as Record<string, unknown>).is_deactivated ? 'deactivated' : '']"
            >
              <span v-if="(p as Record<string, unknown>).is_popular" class="popular-badge">POPULAR</span>
              <span v-if="(p as Record<string, unknown>).is_deactivated" class="popular-badge" style="right: auto; left: 1rem; background: #ef4444; color: #fff;">DEACTIVATED</span>
              <h3>{{ (p as Record<string, unknown>).name }}</h3>
              <div class="plan-price">
                <span class="price">{{ formatCurrency((p as Record<string, unknown>).price) }}</span>
                <span class="period">{{ (p as Record<string, unknown>).period || 'per month' }}</span>
              </div>
              <p class="plan-description">{{ (p as Record<string, unknown>).description || 'A great membership plan for our valued members.' }}</p>
              <div class="plan-features">
                <p>Features Included</p>
                <ul>
                  <li v-for="(feature, fi) in planFeatureList(p as Record<string, unknown>)" :key="fi"><i class="fas fa-check"></i> {{ feature }}</li>
                </ul>
              </div>
              <div class="plan-stats">
                <p>{{ (p as Record<string, unknown>).member_count ?? 0 }} Members</p>
                <p>Monthly Revenue <span class="revenue-badge">{{ formatCurrency((p as Record<string, unknown>).monthly_revenue) }}</span></p>
              </div>
              <div class="plan-actions">
                <button type="button" class="btn-edit" @click="openEditPlan(p as Record<string, unknown>)">Edit Plan</button>
                <button type="button" class="btn-deactivate" @click="deactivatePlan(p as Record<string, unknown>)">{{ (p as Record<string, unknown>).is_deactivated ? 'Reactivate' : 'Deactivate' }}</button>
                <button type="button" class="btn-delete" @click="deletePlan(p as Record<string, unknown>)">Delete</button>
              </div>
            </div>
          </div>
        </div>

        <div v-if="currentView === 'attendance'" class="view active">
          <div class="page-header">
            <h2>Attendance Tracking</h2>
            <div class="header-actions" style="display: flex; gap: 0.75rem; align-items: center;">
              <button type="button" class="btn-add-plan" @click="openMemberQrScannerModal">
                <i class="fas fa-qrcode"></i>
                <span>Scan Member QR</span>
              </button>
              <button type="button" class="btn-export" :disabled="excelDownloading" @click="downloadExcelReport">
                <i class="fas fa-download"></i>
                <span>Export Report</span>
              </button>
            </div>
          </div>
          <div class="attendance-metrics">
            <div class="attendance-card">
              <p>Total Check-ins</p>
              <h3>{{ reportAttendance?.total_checkins ?? '0' }}</h3>
              <span>from reports</span>
            </div>
            <div class="attendance-card">
              <p>Today's Check-ins</p>
              <h3>{{ reportAttendance?.today_checkins ?? '0' }}</h3>
              <span>today</span>
            </div>
            <div class="attendance-card">
              <p>Week Check-ins</p>
              <h3>{{ reportAttendance?.week_checkins ?? '0' }}</h3>
              <span>this week</span>
            </div>
            <div class="attendance-card">
              <p>Active Members</p>
              <h3>{{ reportAttendance?.active_members ?? '0' }}</h3>
              <span>eligible</span>
            </div>
          </div>
          <div class="attendance-tabs">
            <button type="button" :class="['attendance-tab', { active: attendanceTab === 'log' }]" @click="attendanceTab = 'log'">Attendance Log</button>
            <button type="button" :class="['attendance-tab', { active: attendanceTab === 'current' }]" @click="attendanceTab = 'current'">Currently In Gym</button>
          </div>
          <div v-show="attendanceTab === 'log'" class="attendance-tab-content active">
            <div class="attendance-filters">
              <div class="search-bar">
                <i class="fas fa-search"></i>
                <input v-model="attendanceSearch" type="text" placeholder="Search by member..." />
              </div>
              <button type="button" class="btn-clear-filter" @click="attendanceSearch = ''">Clear Filter</button>
            </div>
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Member Name</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="l in filteredAttendanceLogs()" :key="String((l as Record<string, unknown>).id)">
                    <td>{{ (l as Record<string, unknown>).date }}</td>
                    <td>{{ (l as Record<string, unknown>).member_name }}</td>
                    <td>{{ (l as Record<string, unknown>).check_in }}</td>
                    <td>{{ (l as Record<string, unknown>).check_out }}</td>
                    <td>{{ (l as Record<string, unknown>).status }}</td>
                  </tr>
                  <tr v-if="filteredAttendanceLogs().length === 0">
                    <td colspan="5" class="empty-row">No attendance logs.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div v-show="attendanceTab === 'current'" class="attendance-tab-content">
            <div class="current-gym-header">
              <h3>Currently In Gym</h3>
              <div class="live-updates">
                <span class="live-dot"></span>
                <span>Live Updates</span>
              </div>
            </div>
            <div class="current-gym-list">
              <div
                v-for="c in currentlyInGymList"
                :key="String((c as Record<string, unknown>).id)"
                class="current-member-card"
              >
                <div class="current-member-avatar purple">{{ (c as Record<string, unknown>).initials }}</div>
                <div class="current-member-info">
                  <p>{{ (c as Record<string, unknown>).member_name }}</p>
                  <span>Check-in: {{ (c as Record<string, unknown>).check_in_time }}</span>
                </div>
              </div>
            </div>
            <p v-if="currentlyInGymList.length === 0" class="placeholder-text">No members currently in gym.</p>
          </div>
        </div>

        <div v-if="showMemberQrScannerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="stopAdminQrScanner(); showMemberQrScannerModal = false">
          <div class="max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-xl bg-[var(--bg-card)] p-5 shadow-2xl sm:p-6" @click.stop>
            <h3 class="mb-4 text-xl font-bold text-[var(--text-primary)]">Scan Member QR</h3>
            <p class="mb-4 text-sm text-[var(--text-secondary)]">Scan the member portal QR code here to check members in or out automatically.</p>
            <div class="overflow-hidden rounded-xl border border-gray-700 bg-black">
              <div ref="adminQrVideoRef" class="h-60 w-full sm:h-72" />
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
              <button
                type="button"
                class="rounded px-4 py-2 font-medium text-black"
                :class="adminQrScannerActive ? 'bg-red-400' : 'bg-[var(--accent-green)]'"
                :disabled="adminQrScannerBusy"
                @click="adminQrScannerActive ? stopAdminQrScanner() : startAdminQrScanner()"
              >
                {{ adminQrScannerActive ? 'Stop Scan' : 'Start Scan' }}
              </button>
              <button type="button" class="rounded bg-[var(--bg-input)] px-4 py-2 font-medium text-[var(--text-primary)] hover:opacity-90" @click="stopAdminQrScanner(); showMemberQrScannerModal = false">
                Close
              </button>
            </div>
            <p v-if="adminQrScannerStatus" class="mt-3 text-sm text-cyan-300">{{ adminQrScannerStatus }}</p>
            <p v-if="adminQrScannerError" class="mt-2 text-sm text-red-400">{{ adminQrScannerError }}</p>
            <textarea
              v-model="adminQrManualQrPayload"
              class="mt-4 w-full rounded-lg border border-gray-600 bg-[var(--bg-input)] px-3 py-2 text-sm text-[var(--text-primary)]"
              rows="4"
              placeholder="Paste the member attendance QR payload here if camera scan is unavailable."
            />
            <button
              type="button"
              class="mt-3 w-full rounded-lg bg-blue-500 py-2 font-medium text-white hover:opacity-90"
              :disabled="adminQrScannerBusy"
              @click="submitAdminQrPayload()"
            >
              Process QR Payload
            </button>
          </div>
        </div>

        <div v-if="currentView === 'payments'" class="view active">
          <div class="page-header">
            <h2>Payment & Billing</h2>
            <div class="header-actions">
              <button type="button" class="btn-add-payment" @click="openAddPayment">
                <i class="fas fa-plus"></i>
                <span>Add Payment</span>
              </button>
            </div>
          </div>
          <div class="payment-metrics">
            <div class="payment-card green">
              <h3>{{ paymentsList.length }}</h3>
              <p>Total Payments</p>
            </div>
            <div class="payment-card blue">
              <h3>{{ revenueMonthly() }}</h3>
              <p>Revenue (monthly)</p>
            </div>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Member</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th>Proof</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in paymentsList" :key="String(p.id)">
                  <td>{{ p.invoice_number }}</td>
                  <td>{{ p.member_name || p.member_id }}</td>
                  <td>{{ formatCurrency(p.amount) }}</td>
                  <td>{{ p.payment_method || '-' }}</td>
                  <td>{{ p.payment_date }}</td>
                  <td><span :class="['status-badge', paymentStatusClass(p.status)]">{{ p.status }}</span></td>
                  <td>
                    <button
                      v-if="p.receipt_proof_url"
                      type="button"
                      class="action-btn blue"
                      style="padding: 0.35rem 0.6rem;"
                      @click="viewReceipt(String(p.receipt_proof_url))"
                    >
                      View
                    </button>
                    <span v-else>-</span>
                  </td>
                  <td>
                    <div class="action-buttons" v-if="String(p.status || '').toLowerCase() === 'pending'">
                      <button
                        type="button"
                        class="action-btn green"
                        title="Approve Payment"
                        :disabled="processingPaymentId === Number(p.id)"
                        @click="verifyPaymentStatus((p as Record<string, unknown>), 'Paid')"
                      >
                        <i class="fas fa-check"></i>
                      </button>
                      <button
                        type="button"
                        class="action-btn red"
                        title="Reject Payment"
                        :disabled="processingPaymentId === Number(p.id)"
                        @click="verifyPaymentStatus((p as Record<string, unknown>), 'Cancelled')"
                      >
                        <i class="fas fa-times"></i>
                      </button>
                    </div>
                    <span v-else>-</span>
                  </td>
                </tr>
                <tr v-if="paymentsList.length === 0">
                  <td colspan="8" class="empty-row">No payments yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="currentView === 'reports'" class="view active">
          <div class="page-header">
            <h2>Reports</h2>
            <div class="reports-actions">
              <button type="button" class="btn-export" :disabled="excelDownloading" @click="downloadExcelReport">
                <i class="fas fa-file-excel"></i>
                <span>{{ excelDownloading ? 'Preparing…' : 'Download Excel' }}</span>
              </button>
              <button type="button" class="btn-refresh-plans" style="padding: 0.5rem 0.75rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 0.5rem; color: var(--text-primary); cursor: pointer; font-size: 0.875rem;" title="Refresh reports (same data as Blade when same DB)" @click="loadReportsData">
                <i class="fas fa-sync-alt"></i> Refresh
              </button>
            </div>
          </div>
          <p v-if="excelError" class="notification-inline-error">{{ excelError }}</p>
          <div class="reports-tabs">
            <button type="button" :class="['report-tab', { active: reportsTab === 'overview' }]" @click="reportsTab = 'overview'">Overview</button>
            <button type="button" :class="['report-tab', { active: reportsTab === 'attendance' }]" @click="reportsTab = 'attendance'">Attendance</button>
            <button type="button" :class="['report-tab', { active: reportsTab === 'membership' }]" @click="reportsTab = 'membership'">Membership</button>
          </div>
          <div v-show="reportsTab === 'overview'" class="report-tab-content">
            <div class="reports-metrics">
              <div class="report-card">
                <h3>Total Revenue</h3>
                <h2>{{ formatCurrency(reportRevenue?.total_revenue) }}</h2>
              </div>
              <div class="report-card">
                <h3>Monthly Revenue</h3>
                <h2>{{ revenueMonthly() }}</h2>
              </div>
              <div class="report-card">
                <h3>Active Members</h3>
                <h2>{{ reportRevenue?.active_members ?? 0 }}</h2>
              </div>
            </div>
            <div class="reports-chart-card">
              <h3>Weekly Performance</h3>
              <div class="reports-chart-wrap">
                <canvas ref="overviewChartCanvas"></canvas>
              </div>
            </div>
          </div>
          <div v-show="reportsTab === 'attendance'" class="report-tab-content">
            <div class="reports-metrics">
              <div class="report-card">
                <h3>Today Check-ins</h3>
                <h2>{{ reportAttendance?.today_checkins ?? 0 }}</h2>
              </div>
              <div class="report-card">
                <h3>Week Check-ins</h3>
                <h2>{{ reportAttendance?.week_checkins ?? 0 }}</h2>
              </div>
              <div class="report-card">
                <h3>Active Members</h3>
                <h2>{{ reportAttendance?.active_members ?? 0 }}</h2>
              </div>
            </div>
            <div class="reports-chart-card">
              <h3>14-Day Check-in Trend</h3>
              <div class="reports-chart-wrap">
                <canvas ref="attendanceChartCanvas"></canvas>
              </div>
            </div>
          </div>
          <div v-show="reportsTab === 'membership'" class="report-tab-content">
            <div class="reports-metrics">
              <div class="report-card">
                <h3>Total Members</h3>
                <h2>{{ reportMembership?.total_members ?? 0 }}</h2>
              </div>
              <div class="report-card">
                <h3>Active Members</h3>
                <h2>{{ reportMembership?.active_members ?? 0 }}</h2>
              </div>
              <div class="report-card">
                <h3>Expiring Soon (30d)</h3>
                <h2>{{ reportMembership?.expiring_soon ?? 0 }}</h2>
              </div>
            </div>
            <div class="reports-chart-card">
              <h3>Membership Distribution</h3>
              <div class="reports-chart-wrap">
                <canvas ref="membershipChartCanvas"></canvas>
              </div>
            </div>
          </div>
        </div>

        <div v-if="currentView === 'trainer'" class="view active">
          <div class="page-header">
            <h2>Staff Management</h2>
            <button type="button" class="btn-add-trainer" @click="openAddTrainer">
              <i class="fas fa-plus"></i>
              <span>Add Staff</span>
            </button>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Staff</th>
                  <th>ID</th>
                  <th>Position</th>
                  <th>Department</th>
                  <th>Contact</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in trainerList" :key="String(s.id)">
                  <td>
                    <div class="member-cell">
                      <div class="member-avatar">{{ String(s.full_name || '').slice(0, 2).toUpperCase() }}</div>
                      <div>
                        <p>{{ s.full_name }}</p>
                        <span>{{ s.email }}</span>
                      </div>
                    </div>
                  </td>
                  <td>{{ s.staff_id }}</td>
                  <td>{{ s.position }}</td>
                  <td>{{ s.department || '-' }}</td>
                  <td>{{ s.phone || '-' }}</td>
                  <td><span :class="['status-badge', String(s.status || '').toLowerCase() === 'active' ? 'active' : 'inactive']">{{ s.status }}</span></td>
                  <td>
                    <div class="action-buttons">
                      <button type="button" class="action-btn blue" title="View" @click="viewTrainer(s as Record<string, unknown>)"><i class="fas fa-eye"></i></button>
                      <button type="button" class="action-btn purple" title="Edit" @click="openEditTrainer(s as Record<string, unknown>)"><i class="fas fa-edit"></i></button>
                      <button type="button" class="action-btn red" title="Delete" @click="deleteTrainer(s as Record<string, unknown>)"><i class="fas fa-trash"></i></button>
                    </div>
                  </td>
                </tr>
                <tr v-if="trainerList.length === 0">
                  <td colspan="7" class="empty-row">No staff yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="currentView === 'classes'" class="view active">
          <div class="page-header">
            <h2>Class Schedule</h2>
            <button type="button" class="btn-add-trainer" @click="openAddClass">
              <i class="fas fa-plus"></i>
              <span>Add Class</span>
            </button>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Class</th>
                  <th>Trainer</th>
                  <th>Day</th>
                  <th>Time</th>
                  <th>Capacity</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in classesList" :key="String(c.id)">
                  <td>{{ c.name }}</td>
                  <td>{{ c.trainer_name || 'TBA' }}</td>
                  <td>{{ c.day_of_week }}</td>
                  <td>{{ c.start_time }} - {{ c.end_time }}</td>
                  <td>{{ c.capacity }}</td>
                  <td>
                    <div class="action-buttons">
                      <button type="button" class="action-btn purple" title="Edit" @click="openEditClass(c as Record<string, unknown>)"><i class="fas fa-edit"></i></button>
                      <button type="button" class="action-btn red" title="Delete" @click="deleteClass(c as Record<string, unknown>)"><i class="fas fa-trash"></i></button>
                    </div>
                  </td>
                </tr>
                <tr v-if="classesList.length === 0">
                  <td colspan="6" class="empty-row">No classes yet — add one so members can book it.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="currentView === 'notifications'" class="view active">
          <div class="page-header">
            <h2>Notifications & Alerts</h2>
            <div class="header-actions">
              <button
                type="button"
                class="btn-mark-all-read"
                :disabled="notificationList.length === 0 || notificationActionLoading === 'all'"
                @click="markAllNotificationsAsRead"
              >
                <i class="fas fa-check"></i>
                <span>{{ notificationActionLoading === 'all' ? 'Processing...' : 'Mark All Read' }}</span>
              </button>
              <button type="button" class="btn-notification-settings" @click="currentView = 'settings'; settingsTab = 'notifications'">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
              </button>
            </div>
          </div>
          <div class="notification-tabs">
            <button type="button" :class="['notification-tab', { active: notificationTab === 'active' }]" @click="notificationTab = 'active'">
              <i class="fas fa-bell"></i>
              <span>Active Alerts</span>
              <span class="notification-badge">{{ notificationList.length }}</span>
            </button>
            <button type="button" :class="['notification-tab', { active: notificationTab === 'history' }]" @click="notificationTab = 'history'">
              <i class="fas fa-clock"></i>
              <span>History</span>
            </button>
          </div>
          <p v-if="notificationError" class="notification-inline-error">{{ notificationError }}</p>
          <div v-show="notificationTab === 'active'" class="notification-tab-content">
            <div v-if="notificationList.length === 0" class="empty-state notification-empty-state">
              <i class="fas fa-bell-slash"></i>
              <h3>No active notifications</h3>
              <p>New alerts will appear here once there is admin activity to review.</p>
            </div>
            <div v-else class="notification-list">
              <div v-for="n in notificationList" :key="String(n.id)" :class="['notification-card', n.type]">
                <div class="notification-icon">
                  <i :class="n.icon === 'fas fa-peso-sign' ? 'fas fa-coins' : n.icon"></i>
                </div>
                <div class="notification-content">
                  <h4>{{ n.title }}</h4>
                  <p>{{ n.message }}</p>
                  <span class="notification-time">{{ n.time }}</span>
                </div>
                <div :class="['notification-priority', n.priority]">{{ n.priority === 'high' ? 'High' : 'Info' }}</div>
                <div class="notification-actions">
                  <button
                    type="button"
                    class="btn-mark-read"
                    :disabled="notificationActionLoading === 'all' || isNotificationBusy(n as Record<string, unknown>)"
                    @click="markNotificationAsRead(n as Record<string, unknown>)"
                  >
                    {{ isNotificationBusy(n as Record<string, unknown>) ? 'Processing...' : 'Mark as Read' }}
                  </button>
                  <button
                    type="button"
                    class="btn-dismiss"
                    :disabled="notificationActionLoading === 'all' || isNotificationBusy(n as Record<string, unknown>)"
                    @click="dismissNotification(n as Record<string, unknown>)"
                  >
                    {{ isNotificationBusy(n as Record<string, unknown>) ? 'Processing...' : 'Dismiss' }}
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div v-show="notificationTab === 'history'" class="notification-tab-content">
            <div v-if="notificationHistoryList.length === 0" class="empty-state notification-empty-state">
              <i class="fas fa-clock"></i>
              <h3>No notification history yet</h3>
              <p>Marked or dismissed notifications will be recorded here automatically.</p>
            </div>
            <div v-else class="notification-list">
              <div v-for="n in notificationHistoryList" :key="'hist-' + n.id" class="notification-card">
                <div class="notification-icon">
                  <i :class="n.icon === 'fas fa-peso-sign' ? 'fas fa-coins' : n.icon"></i>
                </div>
                <div class="notification-content">
                  <h4>{{ n.title }}</h4>
                  <p>{{ n.message }}</p>
                  <span class="notification-time">{{ n.time }}</span>
                  <p v-if="n.recorded_at" class="notification-recorded-at">Recorded {{ n.recorded_at }}</p>
                </div>
                <div class="notification-actions">
                  <span :class="['notification-history-badge', String(n.history_status || 'read')]">
                    {{ String(n.action_label || 'Recorded') }}
                  </span>
                  <button type="button" class="btn-view" @click="openNotificationDetail(n as Record<string, unknown>)">View</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="currentView === 'settings'" class="view active">
          <div class="page-header">
            <h2>Settings</h2>
          </div>
          <div class="settings-container">
            <div class="settings-sidebar">
              <button type="button" :class="['settings-nav-item', { active: settingsTab === 'profile' }]" @click="settingsTab = 'profile'">
                <i class="fas fa-user"></i>
                <span>Admin Profile</span>
              </button>
              <button type="button" :class="['settings-nav-item', { active: settingsTab === 'gym' }]" @click="settingsTab = 'gym'">
                <i class="fas fa-building"></i>
                <span>Gym Info</span>
              </button>
              <button type="button" :class="['settings-nav-item', { active: settingsTab === 'notifications' }]" @click="settingsTab = 'notifications'">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
              </button>
            </div>
            <div class="settings-content">
              <div v-show="settingsTab === 'profile'" class="settings-tab-content">
                <h3>Admin Profile</h3>
                <ProfilePhotoPicker v-model:photo-url="userPhotoUrl" :initials="adminInitials" class="mb-6" />
                <div class="settings-form">
                  <div class="form-group">
                    <label>Full Name</label>
                    <input v-model="adminProfileForm.name" type="text" class="form-input" placeholder="Enter full name" />
                  </div>
                  <div class="form-group">
                    <label>Local Address</label>
                    <input v-model="adminProfileForm.address" type="text" class="form-input" placeholder="Enter address" />
                  </div>
                  <div class="form-group">
                    <label>Phone Number</label>
                    <input v-model="adminProfileForm.phone" type="tel" class="form-input" placeholder="Enter phone number" />
                  </div>
                  <div class="form-group">
                    <label>Role</label>
                    <select class="form-input" disabled>
                      <option>Admin</option>
                    </select>
                  </div>
                  <p v-if="formError" class="error-message">{{ formError }}</p>
                  <p v-else-if="adminProfileNotice" class="profile-notice" role="status">{{ adminProfileNotice }}</p>
                  <div class="settings-actions">
                    <button type="button" class="btn-update-profile" :disabled="formLoading" @click="updateAdminProfile">{{ formLoading ? 'Updating...' : 'Update Profile' }}</button>
                    <button type="button" class="btn-change-password" @click="openChangePassword">Change Password</button>
                  </div>
                </div>
              </div>
              <div v-show="settingsTab === 'gym'" class="settings-tab-content">
                <h3>Gym Information</h3>
                <div class="settings-form">
                  <div class="form-group">
                    <label>Gym Name</label>
                    <input v-model="gymForm.name" type="text" class="form-input" placeholder="Enter gym name" />
                  </div>
                  <div class="form-group-row">
                    <div class="form-group">
                      <label>Contact Email</label>
                      <input v-model="gymForm.email" type="email" class="form-input" placeholder="Enter email" />
                    </div>
                    <div class="form-group">
                      <label>Phone Number</label>
                      <input v-model="gymForm.phone" type="tel" class="form-input" placeholder="Enter phone" />
                    </div>
                  </div>
                  <div class="form-group-row">
                    <div class="form-group">
                      <label>GCash Number</label>
                      <input v-model="gymForm.gcash_number" type="text" class="form-input" placeholder="09XXXXXXXXX" />
                    </div>
                    <div class="form-group">
                      <label>GCash Account Name</label>
                      <input v-model="gymForm.gcash_account_name" type="text" class="form-input" placeholder="Account name in GCash" />
                    </div>
                  </div>
                  <div class="form-group">
                    <label>GCash QR (Optional)</label>
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="form-input" @change="onGcashQrSelected" />
                    <p class="text-xs mt-2 text-gray-400">Allowed: PNG, JPG, WEBP (max 5MB)</p>
                    <div v-if="gymForm.gcash_qr_url" class="mt-3">
                      <p class="text-xs text-gray-400 mb-2">Current QR</p>
                      <img :src="gymForm.gcash_qr_url" alt="GCash QR" class="max-h-48 rounded border border-gray-600" />
                    </div>
                  </div>
                  <div class="form-group">
                    <label>Address</label>
                    <textarea v-model="gymForm.address" class="form-input" rows="3" placeholder="Enter address"></textarea>
                  </div>
                  <div class="form-group">
                    <label>Operating Hours</label>
                    <div class="operating-hours">
                      <div v-for="(val, day) in gymForm.operating_hours" :key="day" class="hours-row">
                        <span>{{ day }}</span>
                        <input v-model="gymForm.operating_hours[day]" type="text" class="form-input hours-input" placeholder="9AM-5PM" />
                      </div>
                    </div>
                  </div>
                  <div class="form-group-row">
                    <div class="form-group">
                      <label>SMS Gateway Device ID</label>
                      <input v-model="gymForm.textbee_device_id" type="text" class="form-input" placeholder="From your TextBee device" />
                      <p class="text-xs mt-2 text-gray-400">The phone/SIM that sends SMS to your members (bookings, payments, reminders, messages). Get this from your TextBee account.</p>
                    </div>
                    <div class="form-group">
                      <label>SMS Gateway SIM Slot (optional)</label>
                      <input v-model="gymForm.textbee_sim_subscription_id" type="number" class="form-input" placeholder="e.g. 1 or 2" />
                      <p class="text-xs mt-2 text-gray-400">Only needed if that phone has two SIM cards.</p>
                    </div>
                  </div>
                  <p v-if="formError" class="error-message">{{ formError }}</p>
                  <div class="settings-actions">
                    <button type="button" class="btn-update-gym" :disabled="formLoading" @click="updateGymInfo">{{ formLoading ? 'Updating...' : 'Update Gym Information' }}</button>
                  </div>
                </div>
              </div>
              <div v-show="settingsTab === 'notifications'" class="settings-tab-content">
                <h3>Notifications</h3>
                <div class="notification-settings">
                  <div class="settings-section">
                    <h4>Admin alerts (Notifications page)</h4>
                    <ul class="notification-info-list">
                      <li><i class="fas fa-user-plus"></i> New member registrations awaiting approval</li>
                      <li><i class="fas fa-coins"></i> GCash payments waiting for receipt verification</li>
                      <li><i class="fas fa-life-ring"></i> Support tickets sent by members</li>
                    </ul>
                  </div>
                  <div class="settings-section">
                    <h4>SMS to members</h4>
                    <p class="notification-info-text">
                      Members get an SMS when their account is approved, a payment is verified, a class is booked,
                      and when their membership is about to expire. SMS is sent through the gateway set in
                      <strong>Gym Info → SMS Gateway Device ID</strong>.
                    </p>
                    <p class="notification-info-text" :class="gymForm.textbee_device_id ? 'is-ok' : 'is-warn'">
                      {{ gymForm.textbee_device_id ? 'This branch has its own SMS gateway device set.' : 'This branch uses the platform default SMS gateway.' }}
                    </p>
                  </div>
                  <div class="settings-actions">
                    <button type="button" class="btn-save-settings" @click="settingsTab = 'gym'">Open Gym Info</button>
                    <button type="button" class="btn-cancel-settings" @click="currentView = 'notifications'">View Notifications</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
          </main>
        </div>
      </div>
    </div>

    <!-- Modals -->
    <div v-if="showAddMemberModal" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>{{ selectedMember ? 'Edit Member' : 'Add Member' }}</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="modal-form">
          <div class="form-group">
            <label>Full Name</label>
            <input v-model="memberForm.full_name" type="text" class="form-input" placeholder="Full name" />
          </div>
          <div class="form-group">
            <label>Email</label>
            <input v-model="memberForm.email" type="email" class="form-input" placeholder="Email" :readonly="!!selectedMember" />
          </div>
          <div class="form-group">
            <label>Phone</label>
            <input v-model="memberForm.phone" type="text" class="form-input" placeholder="Phone" />
          </div>
          <div class="form-group">
            <label>Address</label>
            <input v-model="memberForm.address" type="text" class="form-input" placeholder="Address" />
          </div>
          <div class="form-group">
            <label>Membership Plan</label>
            <select v-model="memberForm.membership_plan_id" class="form-input">
              <option v-for="p in plansList" :key="String(p.id)" :value="String(p.id)">{{ p.name }}</option>
            </select>
          </div>
          <p v-if="formError" class="error-message">{{ formError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Cancel</button>
            <button type="button" class="btn-add-member-modal" :disabled="formLoading" @click="submitAddMember">{{ formLoading ? 'Saving...' : (selectedMember ? 'Update Member' : 'Add Member') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showAddPlanModal" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>{{ selectedPlanForEdit ? 'Edit Plan' : 'Add New Plan' }}</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="modal-form">
          <div class="form-group">
            <label>Plan Name</label>
            <input v-model="planForm.name" type="text" class="form-input" placeholder="Plan name" />
          </div>
          <div class="form-group">
            <label>Type</label>
            <select v-model="planForm.type" class="form-input">
              <option>Monthly</option>
              <option>Quarterly</option>
              <option>Annual</option>
            </select>
          </div>
          <div class="form-group">
            <label>Price</label>
            <input v-model="planForm.price" type="number" class="form-input" placeholder="0" step="0.01" />
          </div>
          <div class="form-group">
            <label>Period</label>
            <input v-model="planForm.period" type="text" class="form-input" placeholder="e.g. per month" />
          </div>
          <div class="form-group">
            <label>Description</label>
            <textarea v-model="planForm.description" class="form-input" rows="2" placeholder="Description"></textarea>
          </div>
          <div class="form-group">
            <label>Features Included</label>
            <div class="plan-feature-list">
              <div v-for="(_, fi) in planForm.features" :key="fi" class="plan-feature-row">
                <input
                  v-model="planForm.features[fi]"
                  type="text"
                  class="form-input"
                  maxlength="100"
                  :placeholder="fi === 0 ? 'e.g. Gym Access' : 'Another feature'"
                  :aria-label="`Feature ${fi + 1}`"
                />
                <button type="button" class="plan-feature-remove" :aria-label="`Remove feature ${fi + 1}`" title="Remove" @click="removePlanFeature(fi)">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>
            <button
              v-if="planForm.features.length < MAX_PLAN_FEATURES"
              type="button"
              class="plan-feature-add"
              @click="addPlanFeature"
            >
              <i class="fas fa-plus"></i> Add Feature
            </button>
          </div>
          <div class="form-group checkbox-group">
            <label class="checkbox-label">
              <input v-model="planForm.is_popular" type="checkbox" />
              <span>Mark as popular</span>
            </label>
          </div>
          <p v-if="formError" class="error-message">{{ formError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Cancel</button>
            <button type="button" class="btn-create-plan" :disabled="formLoading" @click="submitAddPlan">
              {{ formLoading ? (selectedPlanForEdit ? 'Updating...' : 'Creating...') : (selectedPlanForEdit ? 'Update' : 'Create Plan') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showAddPaymentModal" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>Add Payment</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="modal-form">
          <div class="form-group">
            <label>Member</label>
            <select v-model="paymentForm.member_id" class="form-input" required @change="onPaymentMemberChange">
              <option value="">Select member</option>
              <option v-for="m in membersList" :key="String(m.id)" :value="String(m.id)">{{ m.full_name }} ({{ m.member_id }})</option>
            </select>
          </div>
          <div class="form-group">
            <label>Membership Plan</label>
            <select v-model="paymentForm.membership_plan_id" class="form-input" @change="onPaymentPlanChange">
              <option value="">No plan (other payment)</option>
              <option v-for="p in plansList" :key="String(p.id)" :value="String(p.id)">{{ p.name }} — {{ p.type }}</option>
            </select>
            <p class="entry-hint" style="margin-top: 4px; color: var(--text-secondary);">Approving the payment extends the membership by this plan (Monthly, Quarterly or Annual).</p>
          </div>
          <div class="form-group">
            <label>Amount</label>
            <input v-model="paymentForm.amount" type="number" class="form-input" placeholder="0" step="0.01" required :readonly="!!paymentForm.membership_plan_id" />
            <p v-if="paymentForm.membership_plan_id" class="entry-hint" style="margin-top: 4px; color: var(--text-secondary);">Set from the plan's price.</p>
          </div>
          <div class="form-group">
            <label>Payment Date</label>
            <input v-model="paymentForm.payment_date" type="date" class="form-input" />
          </div>
          <div class="form-group">
            <label>Payment Method</label>
            <select v-model="paymentForm.payment_method" class="form-input">
              <option>Cash</option>
              <option>GCash</option>
              <option>Credit Card</option>
              <option>Bank Transfer</option>
              <option>Other</option>
            </select>
          </div>
          <p class="entry-hint" style="margin-top: -4px; margin-bottom: 8px; color: var(--text-secondary);">New payments start as Pending — approve or reject them from the payments list once recorded.</p>
          <p v-if="formError" class="error-message">{{ formError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Cancel</button>
            <button type="button" class="btn-add-payment-modal" :disabled="formLoading" @click="submitAddPayment">{{ formLoading ? 'Saving...' : 'Add Payment' }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showAddTrainerModal" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>{{ editingTrainerId ? 'Edit Staff' : 'Add Staff' }}</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="modal-form">
          <div class="form-group">
            <label>Full Name</label>
            <input v-model="trainerForm.full_name" type="text" class="form-input" placeholder="Full name" />
          </div>
          <div class="form-group">
            <label>Email</label>
            <input v-model="trainerForm.email" type="email" class="form-input" placeholder="Email" />
          </div>
          <div class="form-group">
            <label>Password</label>
            <div class="fg-password-field">
              <input v-model="trainerForm.password" :type="pw.type('staff')" class="form-input" placeholder="Password" autocomplete="new-password" />
              <PasswordToggle :shown="pw.isShown('staff')" @toggle="pw.toggle('staff')" />
            </div>
          </div>
          <div class="form-group">
            <label>Phone</label>
            <input v-model="trainerForm.phone" type="text" class="form-input" placeholder="Phone" />
          </div>
          <div class="form-group">
            <label>Position</label>
            <input v-model="trainerForm.position" type="text" class="form-input" placeholder="Position" />
          </div>
          <div class="form-group">
            <label>Department</label>
            <input v-model="trainerForm.department" type="text" class="form-input" placeholder="Department" />
          </div>
          <div class="form-group">
            <label>Status</label>
            <select v-model="trainerForm.status" class="form-input">
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
              <option value="On Leave">On Leave</option>
            </select>
          </div>
          <div class="form-group">
            <label>Hire Date</label>
            <input v-model="trainerForm.hire_date" type="date" class="form-input" />
          </div>
          <p v-if="formError" class="error-message">{{ formError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Cancel</button>
            <button type="button" class="btn-add-trainer-modal" :disabled="formLoading" @click="submitAddTrainer">
              {{ formLoading ? (editingTrainerId ? 'Saving...' : 'Adding...') : editingTrainerId ? 'Save Changes' : 'Add Staff' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showViewTrainerModal && selectedTrainer" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>Staff Details</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="member-details-content">
          <div class="member-details-header">
            <div class="member-avatar-large">{{ String(selectedTrainer.full_name || '').slice(0, 2).toUpperCase() }}</div>
            <div class="member-header-info">
              <h2>{{ selectedTrainer.full_name }}</h2>
              <p>{{ selectedTrainer.email }}</p>
            </div>
          </div>
          <div class="member-details-grid">
            <div class="detail-item">
              <label>Staff ID</label>
              <p>{{ selectedTrainer.staff_id }}</p>
            </div>
            <div class="detail-item">
              <label>Phone</label>
              <p>{{ selectedTrainer.phone || '-' }}</p>
            </div>
            <div class="detail-item">
              <label>Position</label>
              <p>{{ selectedTrainer.position || '-' }}</p>
            </div>
            <div class="detail-item">
              <label>Department</label>
              <p>{{ selectedTrainer.department || '-' }}</p>
            </div>
            <div class="detail-item">
              <label>Hire Date</label>
              <p>{{ selectedTrainer.hire_date ? String(selectedTrainer.hire_date).slice(0, 10) : '-' }}</p>
            </div>
            <div class="detail-item">
              <label>Status</label>
              <p>{{ selectedTrainer.status }}</p>
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Close</button>
            <button type="button" class="btn-edit-member" @click="openEditTrainer(selectedTrainer); showViewTrainerModal = false">Edit Staff</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showAddClassModal" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>{{ editingClassId ? 'Edit Class' : 'Add Class' }}</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="modal-form">
          <div class="form-group">
            <label>Class Name</label>
            <input v-model="classForm.name" type="text" class="form-input" placeholder="e.g. Morning Yoga" />
          </div>
          <div class="form-group">
            <label>Trainer</label>
            <select v-model="classForm.trainer_id" class="form-input">
              <option value="">TBA / Unassigned</option>
              <option v-for="s in trainerList" :key="String(s.id)" :value="String(s.id)">{{ s.full_name }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>Day of Week</label>
            <select v-model="classForm.day_of_week" class="form-input">
              <option v-for="d in ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']" :key="d" :value="d">{{ d }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>Start Time</label>
            <input v-model="classForm.start_time" type="time" class="form-input" />
          </div>
          <div class="form-group">
            <label>End Time</label>
            <input v-model="classForm.end_time" type="time" class="form-input" />
          </div>
          <div class="form-group">
            <label>Capacity</label>
            <input v-model="classForm.capacity" type="number" min="1" class="form-input" placeholder="10" />
          </div>
          <p v-if="formError" class="error-message">{{ formError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Cancel</button>
            <button type="button" class="btn-add-trainer-modal" :disabled="formLoading" @click="submitClass">
              {{ formLoading ? 'Saving...' : editingClassId ? 'Save Changes' : 'Add Class' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showChangePasswordModal" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>Change Password</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="modal-form">
          <div class="form-group">
            <label>Current Password</label>
            <div class="fg-password-field">
              <input v-model="passwordForm.current_password" :type="pw.type('current')" class="form-input" placeholder="Current password" autocomplete="current-password" />
              <PasswordToggle :shown="pw.isShown('current')" @toggle="pw.toggle('current')" />
            </div>
          </div>
          <div class="form-group">
            <label>New Password</label>
            <div class="fg-password-field">
              <input v-model="passwordForm.new_password" :type="pw.type('new')" class="form-input" placeholder="New password" autocomplete="new-password" />
              <PasswordToggle :shown="pw.isShown('new')" @toggle="pw.toggle('new')" />
            </div>
          </div>
          <div class="form-group">
            <label>Confirm New Password</label>
            <div class="fg-password-field">
              <input v-model="passwordForm.confirm_password" :type="pw.type('confirm')" class="form-input" placeholder="Confirm" autocomplete="new-password" />
              <PasswordToggle :shown="pw.isShown('confirm')" @toggle="pw.toggle('confirm')" />
            </div>
          </div>
          <p v-if="formError" class="error-message">{{ formError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Cancel</button>
            <button type="button" class="btn-add-member-modal" :disabled="formLoading" @click="submitChangePassword">{{ formLoading ? 'Changing...' : 'Change Password' }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showViewMemberModal && selectedMember" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>Member Details</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="member-details-content">
          <div class="member-details-header">
            <div class="member-avatar-large">{{ String(selectedMember.full_name || '').slice(0, 2).toUpperCase() }}</div>
            <div class="member-header-info">
              <h2>{{ selectedMember.full_name }}</h2>
              <p>{{ selectedMember.email }}</p>
            </div>
          </div>
          <div class="member-details-grid">
            <div class="detail-item">
              <label>ID</label>
              <p>{{ selectedMember.member_id }}</p>
            </div>
            <div class="detail-item">
              <label>Contact</label>
              <p>{{ selectedMember.phone || selectedMember.email || '-' }}</p>
            </div>
            <div class="detail-item">
              <label>Address</label>
              <p>{{ memberStreetAddress(selectedMember.address) }}</p>
            </div>
            <div class="detail-item">
              <label>Date of Birth</label>
              <p>{{ memberDateOfBirth(selectedMember.address) }}</p>
            </div>
            <div class="detail-item">
              <label>Emergency Contact</label>
              <p>{{ memberEmergencyContact(selectedMember.address) }}</p>
            </div>
            <div class="detail-item">
              <label>Plan</label>
              <p>{{ selectedMember.membership_plan }}</p>
            </div>
            <div class="detail-item">
              <label>Status</label>
              <p>{{ selectedMember.status }}</p>
            </div>
            <div class="detail-item">
              <label>Expiry</label>
              <p>{{ selectedMember.expiry_date || '-' }}</p>
            </div>
            <div class="detail-item">
              <label>Payment</label>
              <p>{{ selectedMember.payment_status || '-' }}</p>
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Close</button>
            <button type="button" class="btn-edit-member" @click="editMember(selectedMember); showViewMemberModal = false">Edit Member</button>
            <button type="button" class="btn-edit-member" @click="openSendSmsModal(selectedMember); showViewMemberModal = false">Send SMS</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showSendSmsModal && selectedMember" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>Send SMS to {{ selectedMember.full_name }}</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="modal-form">
          <div class="form-group">
            <label>Phone Number</label>
            <input type="text" class="form-input" :value="selectedMember.phone || 'No phone on file'" disabled />
          </div>
          <div class="form-group">
            <label>Message</label>
            <textarea v-model="smsMessage" class="form-input" rows="4" maxlength="300" placeholder="Type your message..."></textarea>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 4px;">{{ smsMessage.length }}/300</p>
          </div>
          <p v-if="formError" class="error-message">{{ formError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Cancel</button>
            <button type="button" class="btn-add-payment-modal" :disabled="formLoading" @click="submitSendSms">{{ formLoading ? 'Sending...' : 'Send SMS' }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showReceiptModal && selectedReceiptUrl" class="modal active" @click.self="closeModals">
      <div class="modal-content receipt-modal-content" @click.stop>
        <div class="modal-header">
          <h3>Payment Receipt</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="receipt-preview">
          <img :src="selectedReceiptUrl" alt="Payment receipt" />
        </div>
        <div class="modal-actions">
          <button type="button" class="btn-cancel" @click="closeModals">Close</button>
          <a :href="selectedReceiptUrl" target="_blank" rel="noopener noreferrer" class="btn-add-member-modal" style="text-decoration: none;">Open Full Size</a>
        </div>
      </div>
    </div>

    <div v-if="showNotificationDetailModal && selectedNotification" class="modal active" @click.self="closeModals">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>Notification Details</h3>
          <button type="button" class="modal-close" @click="closeModals">&times;</button>
        </div>
        <div class="member-details-content notification-details-content">
          <div class="member-details-grid">
            <div class="detail-item">
              <label>Title</label>
              <p>{{ selectedNotification.title || '-' }}</p>
            </div>
            <div class="detail-item">
              <label>Status</label>
              <p>{{ selectedNotification.action_label || 'Unread' }}</p>
            </div>
            <div class="detail-item">
              <label>Priority</label>
              <p>{{ selectedNotification.priority === 'high' ? 'High' : 'Info' }}</p>
            </div>
            <div class="detail-item">
              <label>Recorded At</label>
              <p>{{ selectedNotification.recorded_at || selectedNotification.time || '-' }}</p>
            </div>
            <div class="detail-item detail-item-wide">
              <label>Message</label>
              <p class="notification-detail-message">{{ selectedNotification.message || '-' }}</p>
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" @click="closeModals">Close</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Match Blade admin-portal.css (dark theme) */
.fitgym-admin {
  /* Box colors come from the shared --fg-card-* tokens in app.css */
  --bg-primary: var(--fg-card);
  --bg-secondary: var(--fg-card-sidebar);
  --bg-card: var(--fg-card);
  --bg-input: var(--fg-card-2);
  --text-primary: var(--fg-card-ink);
  --text-secondary: var(--fg-card-ink-soft);
  --accent-green: #5FA82E;
  --accent-blue: #1E88C8;
  --accent-purple: #8A2BE2;
  --accent-purple-light: #6A4BC4;
  --accent-pink: #E0565B;
  --accent-yellow: #E2B54A;
  --border-color: var(--fg-card-border);
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.admin-portal {
  background:
    radial-gradient(circle at top left, rgba(138, 43, 226, 0.18), transparent 26%),
    radial-gradient(circle at bottom right, rgba(255, 107, 107, 0.12), transparent 30%),
    var(--bg-primary);
}

.admin-auth-shell {
  background:
    radial-gradient(circle at top, rgba(106, 90, 205, 0.18), transparent 28%),
    radial-gradient(circle at bottom right, rgba(255, 107, 107, 0.1), transparent 26%),
    transparent;
}

.admin-auth-card {
  background: var(--bg-card);
  border-color: var(--border-color);
  box-shadow: 0 28px 80px rgba(2, 6, 23, 0.45);
}

.admin-auth-eyebrow {
  color: var(--accent-purple-light);
  font-size: 0.85rem;
  font-weight: 700;
  letter-spacing: 0.24em;
  margin-bottom: 0.75rem;
  text-transform: uppercase;
}

.admin-auth-title {
  color: var(--text-primary);
  font-size: clamp(2rem, 5vw, 2.5rem);
  font-weight: 700;
}

.admin-auth-subtitle {
  color: var(--text-secondary);
  margin: 0.85rem 0 0;
}

.admin-auth-role-badge {
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

.admin-auth-label-row {
  align-items: center;
  display: flex;
  justify-content: space-between;
  margin-bottom: 0.5rem;
}

.admin-auth-link {
  color: var(--accent-purple-light);
}

.admin-auth-options {
  justify-content: flex-start;
}

.admin-auth-error {
  background: rgba(255, 107, 107, 0.12);
  border: 1px solid rgba(255, 107, 107, 0.3);
  border-radius: 0.75rem;
  color: #fecaca;
  margin-bottom: 1rem;
  padding: 0.75rem 0.9rem;
}

.admin-auth-primary {
  background: linear-gradient(135deg, var(--accent-purple) 0%, #9b36f3 100%);
  border: 0;
  border-radius: 0.75rem;
  color: #fff;
  font-size: 1rem;
  font-weight: 700;
  width: 100%;
}

.admin-auth-primary:hover:not(:disabled) {
  filter: brightness(1.05);
}

.admin-shell {
  background: var(--bg-primary);
  border-color: rgba(160, 122, 31, 0.35);
}

/* backdrop-filter would trap the fixed-position mobile drawer inside the shell */
@media (min-width: 1024px) {
  .admin-shell {
    backdrop-filter: blur(12px);
  }
}

.admin-sidebar {
  background:
    linear-gradient(180deg, rgba(217, 180, 90, 0.07) 0%, rgba(217, 180, 90, 0) 45%),
    var(--bg-secondary);
  border-right: 1px solid var(--border-color);
}

.admin-profile-card,
.admin-header-card,
.admin-header-button,
.admin-notification-panel,
.admin-notification-item,
.admin-notification-open {
  background: var(--bg-card);
  border-color: var(--border-color);
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.admin-avatar {
  background: var(--accent-purple);
  color: #fff;
}

.admin-nav-item {
  background: transparent;
  border: 1px solid transparent;
  color: var(--text-primary);
}

.admin-nav-item:hover {
  background: rgba(217, 180, 90, 0.1);
  border-color: rgba(106, 90, 205, 0.3);
}

.admin-nav-item.active {
  background: linear-gradient(135deg, rgba(138, 43, 226, 0.92) 0%, rgba(155, 54, 243, 0.92) 100%);
  box-shadow: 0 14px 28px rgba(138, 43, 226, 0.28);
  color: #fff;
}

.admin-logout-btn {
  background: var(--accent-pink);
  color: #fff;
}

.admin-header {
  background: transparent;
  border-color: rgba(160, 122, 31, 0.3);
}

.admin-header-button {
  border: 1px solid var(--border-color);
  color: var(--text-primary);
}

.admin-header-badge {
  background: var(--accent-purple);
  color: #fff;
}

.admin-notification-panel .border-b {
  border-color: var(--border-color);
}

.admin-notification-item {
  border: 1px solid transparent;
}

.admin-notification-item:hover {
  border-color: rgba(106, 90, 205, 0.3);
}

.admin-notification-open {
  background: var(--accent-purple);
  color: #fff;
}

.admin-main {
  background: transparent;
}

.login-container {
  display: flex;
  width: 100%;
  min-height: 100vh;
  background: var(--bg-primary);
}

.login-main {
  flex: 1;
  background: var(--bg-primary);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3.75rem;
}

.login-main h2 {
  font-size: clamp(1.25rem, 3vw, 1.75rem);
  font-weight: bold;
  text-transform: uppercase;
  margin-bottom: 0.625rem;
  text-align: center;
  color: var(--text-primary);
}

.login-subtitle {
  font-size: clamp(0.875rem, 1.5vw, 1rem);
  color: var(--text-secondary);
  margin-bottom: 2.5rem;
}

.login-form {
  width: 100%;
  max-width: 25rem;
}

.fitgym-admin .form-group {
  margin-bottom: 1.25rem;
}

.fitgym-admin .form-group label {
  display: block;
  margin-bottom: 0.5rem;
  color: var(--text-primary);
  font-size: clamp(0.75rem, 1vw, 0.875rem);
}

.fitgym-admin .form-input {
  width: 100%;
  padding: 0.75rem 0.9375rem;
  background: var(--bg-input);
  border: 1px solid var(--border-color);
  border-radius: 0.375rem;
  color: var(--text-primary);
  font-size: clamp(0.75rem, 1vw, 0.875rem);
}

.fitgym-admin .form-input:focus {
  outline: none;
  border-color: var(--accent-purple);
  box-shadow: 0 0 0 1px rgba(138, 43, 226, 0.35);
}

.fitgym-admin .form-input::placeholder {
  color: var(--text-secondary);
}

.password-input-wrapper {
  position: relative;
}

.password-input-wrapper .form-input {
  padding-right: 2.5rem;
}

.password-input-wrapper .password-toggle {
  position: absolute;
  right: 0.9375rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-secondary);
  cursor: pointer;
  pointer-events: auto;
}

.form-options {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5625rem;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--text-primary);
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
  width: 1rem;
  height: 1rem;
  cursor: pointer;
}

.forgot-password {
  color: var(--text-primary);
  text-decoration: none;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
}

.forgot-password:hover {
  text-decoration: underline;
}

.profile-notice {
  color: var(--accent-green);
  margin-bottom: 0.625rem;
  font-size: 0.875rem;
}

.fitgym-admin .error-message {
  color: #ff6b6b;
  margin-bottom: 0.625rem;
  font-size: 0.875rem;
}

.btn-signin {
  width: 100%;
  padding: 0.875rem;
  background: var(--accent-green);
  color: #000;
  border: none;
  border-radius: 0.375rem;
  font-size: clamp(0.875rem, 1.5vw, 1rem);
  font-weight: bold;
  cursor: pointer;
  transition: opacity 0.2s;
}

.btn-signin:hover:not(:disabled) {
  opacity: 0.9;
}

.btn-signin:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

/* Dashboard layout (Blade-style) */
.dashboard-container {
  display: flex;
  width: 100%;
  min-height: 100vh;
}

.sidebar {
  width: 17.5rem;
  background: var(--bg-secondary);
  display: flex;
  flex-direction: column;
  padding: 1.875rem 1.25rem;
}

.sidebar-header h1 {
  font-size: clamp(1.25rem, 2vw, 1.75rem);
  font-weight: bold;
  color: var(--text-primary);
  margin-bottom: 0.3125rem;
}

.sidebar-header p {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-secondary);
  margin-bottom: 1.875rem;
}

.sidebar-nav {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 0.9375rem;
  color: var(--text-secondary);
  border: none;
  background: none;
  border-radius: 0.5rem;
  cursor: pointer;
  transition: all 0.2s;
  width: 100%;
  text-align: left;
  font-size: inherit;
  font-family: inherit;
}

.nav-item:hover {
  background: var(--bg-card);
  color: var(--text-primary);
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.nav-item.active {
  background: var(--accent-green);
  color: #000;
  font-weight: 600;
}

.nav-item i {
  font-size: clamp(1rem, 1.5vw, 1.125rem);
  width: 1.25rem;
}

.btn-logout {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  padding: 0.75rem 0.9375rem;
  background: var(--accent-pink);
  color: #fff;
  border: none;
  border-radius: 0.5rem;
  cursor: pointer;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  margin-top: auto;
}

.btn-logout:hover {
  opacity: 0.9;
}

.main-content {
  flex: 1;
  background: var(--bg-primary);
  padding: 1.875rem 2.5rem;
  overflow-y: auto;
  min-height: 100vh;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.875rem;
}

.page-header h2 {
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: bold;
  color: var(--fg-ink);
}

.header-user {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-secondary);
}

/* Members: horizontal row (Blade-style) */
.page-controls {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.9375rem;
  margin-bottom: 1.5625rem;
}

.page-controls .search-bar {
  flex: 1;
  min-width: 0;
  position: relative;
  display: flex;
  align-items: center;
}

.page-controls .search-bar i {
  position: absolute;
  left: 0.9375rem;
  color: var(--text-secondary);
  pointer-events: none;
}

.page-controls .search-bar input {
  width: 100%;
  padding: 0.75rem 0.9375rem 0.75rem 2.5rem;
  background: var(--bg-input);
  border: 1px solid var(--border-color);
  border-radius: 0.5rem;
  color: var(--text-primary);
  font-size: clamp(0.75rem, 1vw, 0.875rem);
}

.page-controls .filter-dropdown {
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

.page-controls .btn-add-member {
  flex-shrink: 0;
}

/* Dashboard metrics (Blade-style) */
.metrics-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}

.metric-card {
  background: var(--bg-card);
  padding: 0.875rem;
  border-radius: 0.5rem;
  display: flex;
  gap: 0.625rem;
  align-items: flex-start;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.metric-icon {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.375rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: clamp(0.875rem, 1.5vw, 1rem);
  flex-shrink: 0;
}

.metric-icon.green {
  background: var(--accent-green);
  color: #000;
}

.metric-icon.blue {
  background: var(--accent-blue);
  color: #fff;
}

.metric-icon.yellow {
  background: var(--accent-yellow);
  color: #000;
}

.metric-icon.purple {
  background: var(--accent-purple-light);
  color: #fff;
}

.metric-currency {
  font-size: 1.25em;
  font-weight: bold;
}

.metric-content {
  min-width: 0;
  flex: 1;
}

.metric-content h3 {
  font-size: clamp(1rem, 2vw, 1.25rem);
  font-weight: bold;
  margin-bottom: 0.25rem;
  line-height: 1.2;
  color: var(--text-primary);
}

.metric-content p {
  font-size: clamp(0.65rem, 0.9vw, 0.75rem);
  color: var(--text-secondary);
  margin-bottom: 0.25rem;
  line-height: 1.3;
}

.metric-content span {
  font-size: clamp(0.55rem, 0.7vw, 0.65rem);
  color: var(--text-secondary);
  line-height: 1.3;
}

/* Tables (Blade-style) */
.view {
  display: block;
}

.table-container {
  background: var(--bg-card);
  border-radius: 0.75rem;
  overflow-x: auto;
  width: 100%;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

/* Wide tables scroll inside .table-container instead of crushing columns */
.data-table {
  width: 100%;
  min-width: 42rem;
  border-collapse: collapse;
}

.data-table thead {
  background: var(--bg-input);
}

.data-table th {
  padding: clamp(0.5rem, 1vw, 0.625rem) clamp(0.625rem, 1.2vw, 0.75rem);
  text-align: left;
  font-size: clamp(0.65rem, 1.1vw, 0.8rem);
  font-weight: 600;
  color: var(--text-primary);
  border-bottom: 1px solid var(--border-color);
  white-space: nowrap;
}

.data-table td {
  padding: clamp(0.4rem, 0.8vw, 0.5625rem) clamp(0.625rem, 1.2vw, 0.75rem);
  border-bottom: 1px solid var(--border-color);
  font-size: clamp(0.65rem, 1.1vw, 0.8rem);
  color: var(--text-primary);
}

.member-cell {
  display: flex;
  align-items: center;
  gap: clamp(0.4rem, 0.8vw, 0.625rem);
}

.member-avatar {
  width: clamp(1.65rem, 2.4vw, 2rem);
  height: clamp(1.65rem, 2.4vw, 2rem);
  min-width: clamp(1.65rem, 2.4vw, 2rem);
  border-radius: 50%;
  background: var(--accent-purple);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: clamp(0.55rem, 0.9vw, 0.7rem);
  color: #fff;
  flex-shrink: 0;
}

.member-cell p {
  font-size: clamp(0.65rem, 1.1vw, 0.8rem);
  color: var(--text-primary);
  margin-bottom: 0.0625rem;
  line-height: 1.25;
}

.member-cell span {
  font-size: clamp(0.55rem, 0.9vw, 0.7rem);
  color: var(--text-secondary);
  line-height: 1.25;
}

.status-badge {
  padding: clamp(0.2rem, 0.5vw, 0.25rem) clamp(0.5rem, 1vw, 0.75rem);
  border-radius: 0.75rem;
  font-size: clamp(0.6rem, 1vw, 0.75rem);
  font-weight: 600;
  display: inline-block;
}

.status-badge.active {
  background: var(--accent-green);
  color: #000;
}

.status-badge.inactive {
  background: var(--accent-pink);
  color: #fff;
}

.empty-row {
  text-align: center;
  padding: 2rem;
  color: var(--text-secondary);
}

.placeholder-text {
  color: var(--text-secondary);
  font-size: clamp(0.875rem, 1vw, 1rem);
}

/* Plans summary - 4 cards in one row like Blade */
.plans-summary {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.25rem;
  margin-bottom: 1.875rem;
}

.summary-card {
  background: var(--bg-card);
  padding: 1.25rem;
  border-radius: 0.75rem;
  display: flex;
  align-items: center;
  gap: 0.9375rem;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.summary-card i {
  font-size: clamp(1.5rem, 3vw, 2rem);
  color: var(--accent-green);
}

.summary-card h3 {
  font-size: clamp(1.125rem, 2.5vw, 1.5rem);
  font-weight: bold;
  margin-bottom: 0.3125rem;
  color: var(--text-primary);
}

.summary-card p {
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
  color: var(--text-secondary);
}

.btn-add-plan {
  padding: 0.75rem 1.25rem;
  background: var(--accent-green);
  color: #000;
  border: none;
  border-radius: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* Membership plan cards (match Blade) */
.plans-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(18rem, 100%), 1fr));
  gap: 1.25rem;
}

.plan-card {
  background: var(--bg-card);
  padding: 1.5625rem;
  border-radius: 0.75rem;
  position: relative;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.plan-card.deactivated {
  opacity: 0.85;
  border: 1px dashed #ef4444;
}

.plan-card.popular .popular-badge {
  position: absolute;
  top: 0.9375rem;
  right: 0.9375rem;
  background: var(--accent-yellow);
  color: #000;
  padding: 0.25rem 0.75rem;
  border-radius: 0.75rem;
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
  font-weight: bold;
}

.plan-card h3 {
  font-size: clamp(1.125rem, 2.5vw, 1.5rem);
  margin-bottom: 0.9375rem;
  color: var(--text-primary);
}

.plan-price {
  margin-bottom: 0.9375rem;
}

.plan-price .price {
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: bold;
  display: block;
  color: var(--text-primary);
}

.plan-price .period {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-secondary);
}

.plan-description {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-secondary);
  margin-bottom: 1.25rem;
  line-height: 1.5;
}

.plan-features {
  margin-bottom: 1.25rem;
}

.plan-features > p {
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
  color: var(--text-secondary);
  margin-bottom: 0.75rem;
  text-transform: uppercase;
}

.plan-features ul {
  list-style: none;
  padding: 0;
  margin: 0;
}

.plan-features li {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  margin-bottom: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-primary);
}

.plan-features li i {
  color: var(--accent-green);
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
}

.plan-stats {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.25rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--border-color);
}

.plan-stats p {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-primary);
}

.plan-stats .revenue-badge {
  background: var(--accent-yellow);
  color: #000;
  padding: 0.25rem 0.625rem;
  border-radius: 0.5rem;
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
  font-weight: 600;
  margin-left: 0.5rem;
}

.plan-actions {
  display: flex;
  gap: 0.625rem;
}

.plan-actions .btn-edit {
  flex: 1;
  padding: 0.625rem;
  background: var(--accent-blue);
  color: #fff;
  border: none;
  border-radius: 0.375rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  cursor: pointer;
}

.plan-actions .btn-deactivate {
  flex: 1;
  padding: 0.625rem;
  background: var(--accent-pink);
  color: #fff;
  border: none;
  border-radius: 0.375rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  cursor: pointer;
}

.plan-actions .btn-delete {
  flex: 1;
  padding: 0.625rem;
  background: var(--accent-purple);
  color: #fff;
  border: none;
  border-radius: 0.375rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  cursor: pointer;
}

.plan-feature-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.plan-feature-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}

.plan-feature-row .form-input {
  flex: 1;
  min-width: 0;
}

.plan-feature-remove {
  flex-shrink: 0;
  width: 2.5rem;
  height: 2.5rem;
  border: 1px solid var(--border-color);
  border-radius: 0.375rem;
  background: var(--bg-input);
  color: var(--accent-pink);
  cursor: pointer;
}

.plan-feature-add {
  margin-top: 0.625rem;
  padding: 0.5rem 0.875rem;
  border: 1px dashed var(--accent-green);
  border-radius: 0.375rem;
  background: transparent;
  color: var(--accent-green);
  font-size: 0.875rem;
  cursor: pointer;
}

.checkbox-group .checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--text-primary);
}

.btn-add-member {
  padding: 0.75rem 1.25rem;
  background: var(--accent-green);
  color: #fff;
  border: none;
  border-radius: 0.75rem;
  font-size: clamp(0.8125rem, 1vw, 0.9375rem);
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  box-shadow: 0 0 12px rgba(141, 255, 0, 0.3);
}

.btn-add-member:hover {
  filter: brightness(1.1);
}

/* Attendance */
.attendance-metrics {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.25rem;
  margin-bottom: 1.875rem;
}

.attendance-card {
  background: var(--bg-card);
  padding: 1.5625rem;
  border-radius: 0.75rem;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.attendance-card p {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-secondary);
  margin-bottom: 0.625rem;
}

.attendance-card h3 {
  font-size: clamp(1.75rem, 3.5vw, 2.25rem);
  font-weight: bold;
  color: var(--accent-green);
  margin-bottom: 0.5rem;
}

.attendance-card span {
  font-size: clamp(0.625rem, 0.8vw, 0.75rem);
  color: var(--text-secondary);
}

.attendance-tabs {
  display: flex;
  gap: 0;
  margin-bottom: 1.5625rem;
  border-bottom: 2px solid rgba(160, 122, 31, 0.3);
}

.attendance-tab {
  padding: 0.75rem 1.5rem;
  background: transparent;
  border: none;
  color: var(--fg-ink-soft);
  font-size: clamp(0.875rem, 1.5vw, 1rem);
  cursor: pointer;
  position: relative;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
}

.attendance-tab.active {
  color: var(--fg-ink);
  font-weight: 600;
  border-bottom-color: var(--fg-gold);
}

.attendance-tab-content {
  display: block;
}

.attendance-filters {
  display: flex;
  gap: 0.9375rem;
  margin-bottom: 1.5625rem;
  align-items: center;
}

/* The attendance search has no box of its own, so it sits right on the marble */
.attendance-filters .search-bar,
.attendance-filters .search-bar input {
  color: var(--fg-ink);
}

.attendance-filters .search-bar input::placeholder {
  color: var(--fg-ink-soft);
}

.btn-clear-filter {
  padding: 0.75rem 1.25rem;
  background: var(--accent-pink);
  color: #fff;
  border: none;
  border-radius: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  cursor: pointer;
}

.current-gym-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5625rem;
}

.current-gym-header h3 {
  font-size: clamp(1.125rem, 2.5vw, 1.5rem);
  color: var(--fg-ink);
}

.live-updates {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--accent-green);
  font-size: clamp(0.75rem, 1vw, 0.875rem);
}

.live-dot {
  width: 0.5rem;
  height: 0.5rem;
  background: var(--accent-green);
  border-radius: 50%;
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.current-gym-list {
  display: flex;
  flex-direction: column;
  gap: 0.9375rem;
  margin-bottom: 1.5625rem;
}

.current-member-card {
  background: var(--bg-card);
  padding: 1.25rem;
  border-radius: 0.75rem;
  display: flex;
  align-items: center;
  gap: 1.25rem;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.current-member-avatar {
  width: 3.75rem;
  height: 3.75rem;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: clamp(1rem, 1.8vw, 1.125rem);
  color: #fff;
}

.current-member-avatar.purple {
  background: var(--accent-purple);
}

.current-member-info {
  flex: 1;
}

.current-member-info p {
  font-size: clamp(0.875rem, 1.5vw, 1rem);
  font-weight: 600;
  margin-bottom: 0.3125rem;
  color: var(--text-primary);
}

.current-member-info span {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-secondary);
}

/* Payment metrics */
.payment-metrics {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1.25rem;
  margin-bottom: 1.875rem;
}

.payment-card {
  padding: 1.5625rem;
  border-radius: 0.75rem;
  text-align: center;
}

.payment-card.green {
  background: var(--accent-green);
  color: #000;
}

.payment-card.blue {
  background: var(--accent-blue);
  color: #fff;
}

.payment-card h3 {
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: bold;
  margin-bottom: 0.5rem;
}

.payment-card p {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
}

.btn-add-payment {
  padding: 0.75rem 1.25rem;
  background: var(--accent-green);
  color: #000;
  border: none;
  border-radius: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.btn-export {
  padding: 0.75rem 1.25rem;
  background: var(--accent-blue);
  color: #fff;
  border: none;
  border-radius: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.btn-export:disabled {
  opacity: 0.6;
  cursor: wait;
}

/* Reports */
.reports-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.reports-tabs {
  display: flex;
  gap: 0;
  margin-bottom: 1.5625rem;
  border-bottom: 2px solid rgba(160, 122, 31, 0.3);
}

.report-tab {
  padding: 0.75rem 1.5rem;
  background: transparent;
  border: none;
  color: var(--fg-ink-soft);
  font-size: clamp(0.875rem, 1vw, 1rem);
  cursor: pointer;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
}

.report-tab.active {
  color: #fff;
  border-bottom-color: var(--accent-purple-light);
  background: var(--accent-purple-light);
}

.report-tab-content {
  display: block;
}

.reports-metrics {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.25rem;
  margin-bottom: 1.875rem;
}

.report-card {
  background: var(--bg-card);
  padding: 1.5625rem;
  border-radius: 0.75rem;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.report-card h3 {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-secondary);
  margin-bottom: 0.625rem;
}

.report-card h2 {
  font-size: clamp(1.75rem, 3.5vw, 2.25rem);
  font-weight: bold;
  color: var(--text-primary);
}

.reports-chart-card {
  background: var(--bg-card);
  border-radius: 0.75rem;
  padding: 1.25rem;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.reports-chart-card h3 {
  font-size: clamp(0.9375rem, 1.2vw, 1.125rem);
  color: var(--text-primary);
  margin-bottom: 0.75rem;
}

.reports-chart-wrap {
  position: relative;
  height: 18.75rem;
}

.btn-add-trainer {
  padding: 0.75rem 1.25rem;
  background: var(--accent-purple);
  color: #fff;
  border: none;
  border-radius: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* Action buttons */
.action-buttons {
  /* two icons per row keeps the Actions column narrow, so the table fits without scrolling */
  display: grid;
  grid-template-columns: repeat(2, 1.625rem);
  gap: 0.375rem;
}

.action-btn {
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

.action-btn.blue {
  background: var(--accent-blue);
  color: #fff;
}

.action-btn.purple {
  background: var(--accent-purple);
  color: #fff;
}

.action-btn.red {
  background: var(--accent-pink);
  color: #fff;
}

.action-btn.green {
  background: var(--accent-green);
  color: #000;
}

.action-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.status-badge.paid {
  background: var(--accent-green);
  color: #000;
}

.status-badge.pending {
  background: var(--accent-yellow);
  color: #000;
}

.status-badge.cancelled {
  background: var(--accent-pink);
  color: #fff;
}

.status-badge.overdue {
  background: var(--accent-blue);
  color: #fff;
}

/* Empty state & settings */
/* Notifications & Alerts */
.header-actions {
  display: flex;
  gap: 0.75rem;
  align-items: center;
}

.btn-mark-all-read,
.btn-notification-settings {
  padding: 0.5rem 1rem;
  border: 1px solid var(--border-color);
  background: var(--bg-input);
  color: var(--text-primary);
  border-radius: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.btn-mark-all-read:disabled,
.btn-notification-settings:disabled,
.btn-mark-read:disabled,
.btn-dismiss:disabled,
.btn-view:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.notification-inline-error {
  margin-bottom: 1rem;
  padding: 0.875rem 1rem;
  border: 1px solid rgba(255, 107, 107, 0.35);
  border-radius: 0.75rem;
  background: rgba(255, 107, 107, 0.12);
  color: #ffd2d2;
  font-size: 0.875rem;
}

.notification-tabs {
  display: flex;
  gap: 0;
  margin-bottom: 1.25rem;
  border-bottom: 2px solid rgba(160, 122, 31, 0.3);
}

.notification-tab {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1.25rem;
  background: transparent;
  border: none;
  color: var(--fg-ink-soft);
  font-size: clamp(0.875rem, 1vw, 1rem);
  cursor: pointer;
  position: relative;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
}

.notification-tab.active {
  color: var(--fg-ink);
  font-weight: 600;
  border-bottom-color: var(--fg-gold);
}

.notification-badge {
  background: var(--accent-green);
  color: #000;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0.15rem 0.5rem;
  border-radius: 1rem;
}

.notification-tab-content {
  display: block;
}

.notification-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.notification-empty-state {
  margin-top: 1.25rem;
}

.notification-card {
  display: grid;
  grid-template-columns: auto 1fr auto auto;
  gap: 1rem;
  align-items: center;
  background: var(--bg-card);
  padding: 1.25rem;
  border-radius: 0.75rem;
  border-left: 4px solid var(--border-color);
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.notification-card.pink {
  border-left-color: var(--accent-pink);
}

.notification-card.yellow {
  border-left-color: var(--accent-yellow);
}

.notification-card.purple {
  border-left-color: var(--accent-purple);
}

.notification-icon {
  width: 2.5rem;
  height: 2.5rem;
  min-width: 2.5rem;
  border-radius: 0.5rem;
  background: var(--bg-input);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-primary);
}

.notification-content h4 {
  font-size: clamp(0.9375rem, 1.2vw, 1.125rem);
  margin-bottom: 0.25rem;
  color: var(--text-primary);
}

.notification-content p {
  font-size: clamp(0.8125rem, 1vw, 0.875rem);
  color: var(--text-secondary);
  margin-bottom: 0.25rem;
}

.notification-time {
  font-size: 0.75rem;
  color: var(--text-secondary);
}

.notification-recorded-at {
  margin-top: 0.4rem;
  font-size: 0.75rem;
  color: var(--accent-purple-light);
}

.notification-priority {
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
}

.notification-priority.high {
  background: rgba(255, 107, 107, 0.2);
  color: var(--accent-pink);
}

.notification-priority.info {
  background: rgba(0, 191, 255, 0.2);
  color: var(--accent-blue);
}

.notification-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.btn-mark-read,
.btn-dismiss,
.btn-view {
  padding: 0.35rem 0.75rem;
  font-size: 0.75rem;
  border-radius: 0.375rem;
  cursor: pointer;
  border: 1px solid var(--border-color);
  background: var(--bg-input);
  color: var(--text-primary);
}

.notification-history-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  border: 1px solid transparent;
}

.notification-history-badge.read {
  background: rgba(141, 255, 0, 0.12);
  border-color: rgba(141, 255, 0, 0.28);
  color: var(--accent-green);
}

.notification-history-badge.dismissed {
  background: rgba(255, 107, 107, 0.12);
  border-color: rgba(255, 107, 107, 0.28);
  color: var(--accent-pink);
}

/* Empty states sit straight on the marble background */
.empty-state {
  text-align: center;
  padding: 4rem 2rem;
  color: var(--fg-ink-soft);
}

.empty-state i {
  font-size: 4rem;
  color: var(--fg-ink-soft);
  margin-bottom: 1rem;
  opacity: 0.5;
}

.empty-state h3 {
  font-size: clamp(1.25rem, 2.5vw, 1.5rem);
  margin-bottom: 0.5rem;
  color: var(--fg-ink);
}

.empty-state p {
  font-size: clamp(0.875rem, 1.2vw, 1rem);
  margin-bottom: 1.5rem;
  color: var(--fg-ink-soft);
}

.empty-state .btn-add-plan {
  margin-top: 0.5rem;
}

.settings-content {
  background: var(--bg-card);
  padding: 1.875rem;
  border-radius: 0.75rem;
  min-width: 0;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.settings-content h3 {
  font-size: clamp(1.125rem, 2.5vw, 1.5rem);
  margin-bottom: 1.25rem;
  color: var(--text-primary);
}

/* Recent activity (dashboard) */
.dashboard-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.25rem;
  margin-top: 1.25rem;
}

.dashboard-card {
  background: var(--bg-card);
  padding: 1.5625rem;
  border-radius: 0.75rem;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.dashboard-card h3 {
  font-size: clamp(1rem, 2vw, 1.25rem);
  margin-bottom: 1rem;
  color: var(--text-primary);
}

.quick-actions {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.quick-action-btn {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  border: none;
  border-radius: 0.5rem;
  font-size: clamp(0.8125rem, 1vw, 0.9375rem);
  font-weight: 600;
  cursor: pointer;
  text-align: left;
  color: #000;
}

.quick-action-btn.green {
  background: var(--accent-green);
}

.quick-action-btn.blue {
  background: var(--accent-blue);
  color: #fff;
}

.quick-action-btn.pink {
  background: var(--accent-pink);
  color: #fff;
}

.recent-activity {
  background: var(--bg-card);
  padding: 1.5625rem;
  border-radius: 0.75rem;
  margin-top: 1.25rem;
  margin-top: 1.25rem;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.recent-activity h3 {
  font-size: clamp(1rem, 2vw, 1.25rem);
  margin-bottom: 1.25rem;
  color: var(--text-primary);
}

.activity-list {
  display: flex;
  flex-direction: column;
  gap: 0.9375rem;
}

.activity-item {
  display: flex;
  align-items: center;
  gap: 0.9375rem;
  padding: 0.9375rem;
  background: var(--bg-input);
  border-radius: 0.5rem;
}

.activity-avatar {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 50%;
  background: var(--accent-purple);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: #fff;
  flex-shrink: 0;
}

.activity-content p {
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-primary);
}

.activity-tag.yellow {
  background: var(--accent-yellow);
  color: #000;
}

.activity-tag.blue {
  background: var(--accent-blue);
  color: #fff;
}

/* Settings container */
.settings-container {
  display: flex;
  gap: 1.875rem;
  margin-top: 1rem;
}

.settings-sidebar {
  width: 12.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.settings-nav-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 0.9375rem;
  background: transparent;
  border: none;
  color: var(--fg-ink-soft);
  text-align: left;
  border-radius: 0.5rem;
  cursor: pointer;
  font-size: clamp(0.875rem, 1vw, 1rem);
  width: 100%;
}

.settings-nav-item:hover {
  background: var(--bg-input);
  color: var(--text-primary);
}

.settings-nav-item.active {
  background: var(--accent-purple-light);
  color: #fff;
}

.settings-nav-item i {
  width: 1.25rem;
}

.settings-tab-content {
  display: block;
}

.settings-tab-content h3 {
  font-size: clamp(1.125rem, 2.5vw, 1.5rem);
  margin-bottom: 1.25rem;
  color: var(--text-primary);
}

.form-group-row {
  display: flex;
  gap: 0.9375rem;
}

.form-group-row .form-group {
  flex: 1;
}

.operating-hours {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
}

.hours-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.hours-row span {
  width: 5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  color: var(--text-primary);
}

.hours-input {
  flex: 1;
}

.settings-actions {
  display: flex;
  gap: 0.9375rem;
  margin-top: 1.25rem;
}

.btn-update-profile,
.btn-change-password {
  padding: 0.75rem 1.25rem;
  border: none;
  border-radius: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  cursor: pointer;
  font-weight: 600;
}

.btn-update-profile {
  background: var(--accent-green);
  color: #000;
}

.btn-change-password {
  background: var(--accent-purple-light);
  color: #fff;
}

.btn-update-gym {
  padding: 0.75rem 1.25rem;
  background: var(--accent-green);
  color: #000;
  border: none;
  border-radius: 0.5rem;
  font-size: clamp(0.75rem, 1vw, 0.875rem);
  font-weight: 600;
  cursor: pointer;
}

.btn-save-settings {
  padding: 0.75rem 1.25rem;
  background: var(--accent-green);
  color: #000;
  border: none;
  border-radius: 0.5rem;
  font-weight: 600;
  cursor: pointer;
}

.btn-cancel-settings {
  padding: 0.75rem 1.25rem;
  background: transparent;
  color: var(--text-primary);
  border: 1px solid var(--border-color);
  border-radius: 0.5rem;
  cursor: pointer;
}

.settings-section {
  margin-bottom: 1.5rem;
}

.notification-info-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.625rem;
  color: var(--text-primary);
  font-size: 0.875rem;
}

.notification-info-list i {
  width: 1.25rem;
  color: var(--accent-green);
}

.notification-info-text {
  color: var(--text-secondary);
  font-size: 0.875rem;
  line-height: 1.6;
  margin-bottom: 0.5rem;
}

.notification-info-text.is-ok {
  color: var(--accent-green);
}

.notification-info-text.is-warn {
  color: var(--accent-yellow);
}

.settings-section h4 {
  font-size: clamp(0.9375rem, 1.5vw, 1.125rem);
  margin-bottom: 0.75rem;
  color: var(--text-primary);
}

.toggle-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 0;
}

.toggle-item > div {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.toggle-item i {
  color: var(--accent-green);
}

.toggle-switch {
  position: relative;
  display: inline-block;
  width: 3rem;
  height: 1.5rem;
}

.toggle-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}

.toggle-switch .slider {
  position: absolute;
  cursor: pointer;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: var(--bg-input);
  border-radius: 1.5rem;
  transition: 0.3s;
}

.toggle-switch .slider.green:before,
.toggle-switch .slider:before {
  position: absolute;
  content: '';
  height: 1.125rem;
  width: 1.125rem;
  left: 0.2rem;
  bottom: 0.2rem;
  background: #fff;
  border-radius: 50%;
  transition: 0.3s;
}

.toggle-switch input:checked + .slider {
  background: var(--accent-green);
}

.toggle-switch input:checked + .slider:before {
  transform: translateX(1.5rem);
}

/* Modals */
.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.8);
  z-index: 1000;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.modal.active {
  display: flex;
}

.modal-content {
  background: var(--bg-card);
  border-radius: 0.75rem;
  padding: 1.875rem;
  width: 100%;
  max-width: 28rem;
  max-height: 90vh;
  max-height: 90dvh;
  overflow-y: auto;
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.receipt-modal-content {
  max-width: 36rem;
}

.receipt-preview {
  display: flex;
  align-items: center;
  justify-content: center;
  max-height: 70vh;
  overflow: auto;
  background: var(--bg-input);
  border-radius: 0.5rem;
  padding: 0.5rem;
}

.receipt-preview img {
  max-width: 100%;
  max-height: 65vh;
  object-fit: contain;
  border-radius: 0.375rem;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.25rem;
}

.modal-header h3 {
  font-size: clamp(1.125rem, 2vw, 1.5rem);
  font-weight: bold;
  color: var(--text-primary);
}

.modal-close {
  background: none;
  border: none;
  color: var(--text-primary);
  font-size: 1.5rem;
  cursor: pointer;
  line-height: 1;
  padding: 0.25rem;
}

.modal-form .form-group {
  margin-bottom: 1rem;
}

.modal-actions {
  display: flex;
  gap: 0.75rem;
  margin-top: 1.25rem;
}

.btn-cancel {
  flex: 1;
  padding: 0.75rem;
  background: var(--bg-input);
  color: var(--text-primary);
  border: 1px solid var(--border-color);
  border-radius: 0.5rem;
  cursor: pointer;
}

.btn-add-member-modal,
.btn-create-plan,
.btn-add-payment-modal,
.btn-add-trainer-modal {
  flex: 1;
  padding: 0.75rem;
  background: var(--accent-green);
  color: #000;
  border: none;
  border-radius: 0.5rem;
  font-weight: 600;
  cursor: pointer;
}

.member-details-content {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.member-details-header {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding-bottom: 1.25rem;
  border-bottom: 1px solid var(--border-color);
}

.member-avatar-large {
  width: 4rem;
  height: 4rem;
  min-width: 4rem;
  border-radius: 50%;
  background: var(--accent-purple);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  font-weight: bold;
  color: #fff;
}

.member-header-info h2 {
  font-size: clamp(1rem, 1.5vw, 1.25rem);
  font-weight: bold;
  margin-bottom: 0.25rem;
  color: var(--text-primary);
}

.member-header-info p {
  font-size: 0.875rem;
  color: var(--text-secondary);
}

.member-details-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.detail-item-wide {
  grid-column: 1 / -1;
}

.detail-item label {
  font-size: 0.75rem;
  color: var(--text-secondary);
  text-transform: uppercase;
  display: block;
  margin-bottom: 0.25rem;
}

.detail-item p {
  font-size: 0.875rem;
  color: var(--text-primary);
}

.notification-detail-message {
  line-height: 1.7;
  white-space: pre-wrap;
}

.btn-edit-member {
  padding: 0.75rem;
  background: var(--accent-purple);
  color: #fff;
  border: none;
  border-radius: 0.5rem;
  font-weight: 600;
  cursor: pointer;
}

/* ---------- Responsive ---------- */
.admin-auth-label-row {
  flex-wrap: wrap;
  gap: 0.25rem 0.75rem;
}

.modal-header {
  gap: 0.75rem;
}

.modal-header h3,
.member-header-info,
.notification-content,
.current-member-info,
.activity-content {
  min-width: 0;
  overflow-wrap: anywhere;
}

/* From 1024px the sidebar is visible, so the content column is narrowest at 1024–1279px */
@media (max-width: 1279.98px) {
  .metrics-grid,
  .attendance-metrics,
  .plans-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .report-card h2 {
    font-size: clamp(1.5rem, 2.6vw, 1.875rem);
    overflow-wrap: anywhere;
  }
}

@media (max-width: 767.98px) {
  .page-header,
  .current-gym-header {
    flex-wrap: wrap;
    gap: 0.75rem;
  }

  .page-header .header-actions,
  .settings-actions,
  .plan-actions,
  .attendance-filters {
    flex-wrap: wrap;
  }

  .page-controls {
    flex-wrap: wrap;
    gap: 0.75rem;
  }

  .page-controls .search-bar {
    flex: 1 1 100%;
  }

  .page-controls .filter-dropdown {
    flex: 1 1 9rem;
    min-width: 0;
  }

  .page-controls .btn-add-member {
    flex: 1 1 100%;
    justify-content: center;
  }

  .attendance-filters {
    gap: 0.75rem;
  }

  .attendance-filters .search-bar {
    flex: 1 1 14rem;
    min-width: 0;
  }

  .attendance-filters .search-bar input {
    width: 100%;
  }

  .reports-metrics,
  .dashboard-grid {
    grid-template-columns: 1fr;
  }

  .attendance-tabs,
  .reports-tabs,
  .notification-tabs {
    flex-wrap: wrap;
  }

  .attendance-tab,
  .report-tab,
  .notification-tab {
    flex: 1 1 auto;
    justify-content: center;
    padding: 0.75rem 0.875rem;
  }

  .notification-card {
    grid-template-columns: auto minmax(0, 1fr);
    padding: 1rem;
  }

  .notification-priority {
    grid-column: 2;
    justify-self: start;
  }

  .notification-actions {
    grid-column: 1 / -1;
  }

  .settings-container {
    flex-direction: column;
    gap: 1rem;
  }

  .settings-sidebar {
    width: 100%;
    flex-direction: row;
    flex-wrap: wrap;
  }

  .settings-nav-item {
    flex: 1 1 auto;
    width: auto;
    justify-content: center;
  }

  .settings-content {
    padding: 1.25rem;
  }

  .settings-actions > button {
    flex: 1 1 auto;
  }

  .form-group-row {
    flex-direction: column;
    gap: 0;
  }

  .operating-hours {
    grid-template-columns: 1fr;
  }

  .plan-stats {
    flex-wrap: wrap;
    gap: 0.5rem;
  }

  .dashboard-card,
  .recent-activity,
  .plan-card,
  .attendance-card,
  .report-card,
  .payment-card {
    padding: 1.25rem;
  }

  .reports-chart-wrap {
    height: 15rem;
  }

  .empty-state {
    padding: 2.5rem 1rem;
  }

  /* The vw-based clamps bottom out too small to read on phones */
  .data-table th,
  .data-table td,
  .member-cell p {
    font-size: 0.8125rem;
  }

  .member-cell span,
  .metric-content p,
  .status-badge {
    font-size: 0.75rem;
  }

  .metric-content span {
    font-size: 0.6875rem;
  }

  /* 16px inputs stop iOS Safari from zooming the page on focus */
  .fitgym-admin .form-input,
  .page-controls .search-bar input,
  .page-controls .filter-dropdown {
    font-size: 1rem;
  }
}

@media (max-width: 479.98px) {
  .metrics-grid,
  .attendance-metrics,
  .plans-summary,
  .payment-metrics,
  .member-details-grid {
    grid-template-columns: 1fr;
  }

  .modal {
    padding: 0.75rem;
  }

  .modal-content {
    padding: 1.25rem;
  }

  .modal-actions {
    flex-wrap: wrap;
  }

  .modal-actions > * {
    flex: 1 1 8rem;
  }

  .current-member-card {
    gap: 0.875rem;
    padding: 1rem;
  }

  .current-member-avatar {
    width: 3rem;
    height: 3rem;
    flex-shrink: 0;
  }
}

/* Touch screens: grow the compact icon buttons to a tappable size */
@media (pointer: coarse) {
  .action-btn {
    width: auto;
    min-width: 2.5rem;
    height: 2.5rem;
    padding: 0 0.5rem;
    font-size: 0.875rem;
  }

  .modal-close {
    min-width: 2.75rem;
    min-height: 2.75rem;
  }

  .btn-mark-read,
  .btn-dismiss,
  .btn-view {
    min-height: 2.5rem;
    padding: 0.5rem 0.875rem;
  }
}
</style>
