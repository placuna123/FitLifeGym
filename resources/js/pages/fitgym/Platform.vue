<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Menu, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { platformAnalytics, platformAnnouncements, platformAuditLogs, platformAuth, platformBilling, platformGyms } from '@/api/fitgym';
import FgThemeToggle from '@/components/FgThemeToggle.vue';
import PasswordToggle from '@/components/PasswordToggle.vue';
import { useMobileNav } from '@/composables/useMobileNav';
import { usePasswordVisibility } from '@/composables/usePasswordVisibility';

const pw = usePasswordVisibility();
const { mobileNavOpen, closeMobileNav } = useMobileNav();

// Same palette as the Admin / Trainer / Member portals — nothing new introduced.
// Box colors come from the shared --fg-card-* tokens in app.css (the keys keep their old names)
const portal = {
  dark: 'var(--fg-card)',
  dark2: 'var(--fg-card-2)',
  dark3: 'var(--fg-card)',
  sidebar: 'var(--fg-card-sidebar)',
  border: 'var(--fg-card-border)',
  purple: 'var(--fg-accent)',
  purpleLight: 'var(--fg-accent-soft)',
  pink: '#E0565B',
  cyan: '#1E88C8',
  lime: '#5FA82E',
  textDim: 'var(--fg-card-ink-soft)',
};

const loading = ref(true);
const isLoggedIn = ref(false);
const user = ref<{ id: number; name: string; email: string } | null>(null);

const loginForm = ref({ email: '', password: '', remember: false });
const loginLoading = ref(false);
const loginError = ref('');

type Tab = 'branches' | 'billing' | 'analytics' | 'announcements' | 'audit';
const currentTab = ref<Tab>('branches');
const tabs: { id: Tab; label: string; description: string }[] = [
  { id: 'branches', label: 'Branches', description: 'Every gym branch with its admin, plan and end date.' },
  { id: 'billing', label: 'Billing', description: 'What branches pay FitGym, receipts to check, and where they pay.' },
  { id: 'analytics', label: 'Analytics', description: 'Members, income and check-ins for each branch.' },
  { id: 'announcements', label: 'Announcements', description: 'Send a message to the admins of one or all branches.' },
  { id: 'audit', label: 'Audit Logs', description: 'Who did what, in every branch.' },
];
const currentTabInfo = computed(() => tabs.find((t) => t.id === currentTab.value) ?? tabs[0]);
const userInitials = computed(() => {
  const parts = (user.value?.name || 'Super Admin').trim().split(/\s+/);
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || 'SA';
});

type Branch = Record<string, unknown> & { admin?: { id: number; name: string; email: string } | null };
const gyms = ref<Branch[]>([]);
const gymsLoading = ref(false);
const actionError = ref('');
const actionNotice = ref('');

// Archived branches can be listed and restored.
const showArchived = ref(false);
const archivedGyms = ref<Branch[]>([]);

const analytics = ref<{ month: string; totals: Record<string, number>; branches: Record<string, unknown>[] } | null>(null);
const analyticsLoading = ref(false);

const auditLogs = ref<Record<string, unknown>[]>([]);
const auditLoading = ref(false);
const auditGymFilter = ref('');
const auditSearch = ref('');
const auditFrom = ref('');
const auditTo = ref('');
const auditTotal = ref(0);

// Manage one branch: details, its Admin, password reset link.
const manageGym = ref<Branch | null>(null);
const manageForm = ref({ name: '', email: '', phone: '', address: '', admin_name: '' });
const manageSaving = ref(false);
const manageError = ref('');
const manageNotice = ref('');
const resetSending = ref(false);

// Billing: what branches pay FitGym for their subscription.
type PaymentDetails = { gcash_number: string; gcash_name: string; bank_details: string; instructions: string };
type BillingData = {
  month: string;
  totals: { all_time: number; this_month: number; payments: number };
  plan_prices: Record<string, number>;
  methods: string[];
  payment_details: PaymentDetails;
  payments: Record<string, unknown>[];
  proofs: PaymentProof[];
  pending_proofs: number;
};
type PaymentProof = {
  id: number;
  gym_name: string;
  plan: string;
  months: number;
  amount: number;
  payment_method: string;
  reference: string | null;
  receipt_url: string | null;
  notes: string | null;
  status: string;
  review_note: string | null;
  sent_on: string;
};
const billing = ref<BillingData | null>(null);
const billingLoading = ref(false);

// Receipts branch Admins sent from Settings → Subscription.
const proofBusy = ref<number | null>(null);
const rejectFor = ref<PaymentProof | null>(null);
const rejectReason = ref('');
const rejectError = ref('');

async function approveProof(proof: PaymentProof) {
  if (proofBusy.value) return;
  if (!window.confirm(`Approve ${proof.gym_name}'s ${formatCurrency(proof.amount)} ${proof.payment_method} payment (ref ${proof.reference})? Check that you received it first.`)) return;
  proofBusy.value = proof.id;
  try {
    const { data } = await platformBilling.approveProof(proof.id);
    actionNotice.value = (data as { message?: string }).message || 'Payment approved.';
    await Promise.all([loadGyms(), loadBilling()]);
  } catch (err: unknown) {
    window.alert(apiMessage(err, 'Failed to approve the payment.'));
  } finally {
    proofBusy.value = null;
  }
}

function openReject(proof: PaymentProof) {
  rejectFor.value = proof;
  rejectReason.value = '';
  rejectError.value = '';
}

async function submitReject() {
  if (!rejectFor.value || proofBusy.value) return;
  proofBusy.value = rejectFor.value.id;
  rejectError.value = '';
  try {
    const { data } = await platformBilling.rejectProof(rejectFor.value.id, rejectReason.value.trim());
    actionNotice.value = (data as { message?: string }).message || 'Payment rejected.';
    rejectFor.value = null;
    await loadBilling();
  } catch (err: unknown) {
    rejectError.value = apiMessage(err, 'Failed to reject the payment.');
  } finally {
    proofBusy.value = null;
  }
}

// Where branches pay FitGym; every branch Admin sees this on their Subscription page.
const paymentDetailsForm = ref<PaymentDetails>({ gcash_number: '', gcash_name: '', bank_details: '', instructions: '' });
const paymentDetailsSaving = ref(false);
const paymentDetailsNotice = ref('');
const paymentDetailsError = ref('');

async function savePaymentDetails() {
  if (paymentDetailsSaving.value) return;
  paymentDetailsSaving.value = true;
  paymentDetailsError.value = '';
  paymentDetailsNotice.value = '';
  try {
    const { data } = await platformBilling.savePaymentDetails({ ...paymentDetailsForm.value });
    paymentDetailsNotice.value = (data as { message?: string }).message || 'Saved.';
  } catch (err: unknown) {
    paymentDetailsError.value = apiMessage(err, 'Failed to save the payment details.');
  } finally {
    paymentDetailsSaving.value = false;
  }
}
const recordFor = ref<Branch | null>(null);
const recordForm = ref({ plan: 'basic', months: 1, amount: 0, payment_method: 'GCash', reference: '', paid_on: '', notes: '' });
const recording = ref(false);
const recordError = ref('');

async function loadBilling() {
  billingLoading.value = true;
  try {
    const { data } = await platformBilling.list();
    billing.value = data.success ? (data as BillingData) : null;
    if (billing.value?.payment_details) paymentDetailsForm.value = { ...billing.value.payment_details };
  } catch {
    billing.value = null;
  } finally {
    billingLoading.value = false;
  }
}

function priceFor(plan: string, months: number): number {
  return Number(billing.value?.plan_prices?.[plan] ?? 0) * (Number(months) || 1);
}

async function openRecordPayment(gym: Branch) {
  if (!billing.value) await loadBilling();
  const plan = gym.subscription_plan === 'pro' ? 'pro' : 'basic';
  recordFor.value = gym;
  recordForm.value = { plan, months: 1, amount: priceFor(plan, 1), payment_method: 'GCash', reference: '', paid_on: '', notes: '' };
  recordError.value = '';
}

// Keep the amount in step with the plan and number of months (it can still be typed over).
function syncRecordAmount() {
  recordForm.value.amount = priceFor(recordForm.value.plan, recordForm.value.months);
}

// The end date the branch will get, worked out the same way the server does.
const recordNewEnd = computed(() => {
  const gym = recordFor.value;
  if (!gym) return '';
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const currentEnd = gym.subscription_expires_at ? new Date(`${String(gym.subscription_expires_at)}T00:00:00`) : null;
  const start = currentEnd && currentEnd >= today && gym.subscription_status === 'active'
    ? new Date(currentEnd.getFullYear(), currentEnd.getMonth(), currentEnd.getDate() + 1)
    : today;
  const end = new Date(start.getFullYear(), start.getMonth() + (Number(recordForm.value.months) || 1), start.getDate() - 1);
  return end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
});

async function submitRecordPayment() {
  if (!recordFor.value || recording.value) return;
  recording.value = true;
  recordError.value = '';
  try {
    const { data } = await platformBilling.record(Number(recordFor.value.id), { ...recordForm.value, paid_on: recordForm.value.paid_on || undefined });
    actionNotice.value = (data as { message?: string }).message || 'Payment recorded.';
    recordFor.value = null;
    await Promise.all([loadGyms(), loadBilling()]);
  } catch (err: unknown) {
    recordError.value = apiMessage(err, 'Failed to record the payment.');
  } finally {
    recording.value = false;
  }
}

/** "Ends in 5 days" / "Ended 2 days ago" next to a branch's end date, flagged when close. */
function endsInfo(gym: Branch): { text: string; level: 'ok' | 'soon' | 'past' } | null {
  if (!gym.subscription_expires_at) return null;
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const end = new Date(`${String(gym.subscription_expires_at)}T00:00:00`);
  const days = Math.round((end.getTime() - today.getTime()) / 86_400_000);
  if (days < 0) return { text: `Ended ${-days} day${days === -1 ? '' : 's'} ago`, level: 'past' };
  if (days === 0) return { text: 'Ends today', level: 'soon' };
  return { text: `Ends in ${days} day${days === 1 ? '' : 's'}`, level: days <= 7 ? 'soon' : 'ok' };
}

// Announcement to the Admin of every branch (or one branch).
const announcementForm = ref({ title: '', message: '', gym_id: '' });
const announcementSending = ref(false);
const announcementError = ref('');
const announcementNotice = ref('');

function apiMessage(err: unknown, fallback: string): string {
  const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : undefined;
  return first || data?.message || fallback;
}

async function loadArchived() {
  try {
    const { data } = await platformGyms.list({ archived: 1 });
    archivedGyms.value = data.success ? (data.gyms as Branch[]) : [];
  } catch {
    archivedGyms.value = [];
  }
}

async function toggleArchived() {
  showArchived.value = !showArchived.value;
  if (showArchived.value) await loadArchived();
}

async function restoreGym(gym: Branch) {
  if (!confirm(`Restore "${gym.name}"? Its Admin will be able to sign in again.`)) return;
  actionError.value = '';
  try {
    const { data } = await platformGyms.restore(Number(gym.id));
    actionNotice.value = (data as { message?: string }).message || 'Branch restored.';
    await Promise.all([loadGyms(), loadArchived()]);
  } catch (err: unknown) {
    actionError.value = apiMessage(err, 'Failed to restore the branch.');
  }
}

function openManage(gym: Branch) {
  manageGym.value = gym;
  manageForm.value = {
    name: String(gym.name ?? ''),
    email: String(gym.email ?? ''),
    phone: String(gym.phone ?? ''),
    address: String(gym.address ?? ''),
    admin_name: String(gym.admin?.name ?? ''),
  };
  manageError.value = '';
  manageNotice.value = '';
}

async function saveManage() {
  if (!manageGym.value || manageSaving.value) return;
  manageSaving.value = true;
  manageError.value = '';
  try {
    await platformGyms.update(Number(manageGym.value.id), { ...manageForm.value });
    manageGym.value = null;
    actionNotice.value = 'Branch details saved.';
    await loadGyms();
  } catch (err: unknown) {
    manageError.value = apiMessage(err, 'Failed to save the branch.');
  } finally {
    manageSaving.value = false;
  }
}

async function sendAdminReset() {
  if (!manageGym.value || resetSending.value) return;
  resetSending.value = true;
  manageError.value = '';
  manageNotice.value = '';
  try {
    const { data } = await platformGyms.sendAdminReset(Number(manageGym.value.id));
    manageNotice.value = (data as { message?: string }).message || 'Reset link sent.';
  } catch (err: unknown) {
    manageError.value = apiMessage(err, 'Failed to send the reset link.');
  } finally {
    resetSending.value = false;
  }
}

async function sendAnnouncement() {
  if (announcementSending.value) return;
  announcementSending.value = true;
  announcementError.value = '';
  announcementNotice.value = '';
  try {
    const { data } = await platformAnnouncements.send({
      title: announcementForm.value.title,
      message: announcementForm.value.message,
      gym_id: announcementForm.value.gym_id ? Number(announcementForm.value.gym_id) : null,
    });
    announcementNotice.value = (data as { message?: string }).message || 'Announcement sent.';
    announcementForm.value = { title: '', message: '', gym_id: '' };
  } catch (err: unknown) {
    announcementError.value = apiMessage(err, 'Failed to send the announcement.');
  } finally {
    announcementSending.value = false;
  }
}

const showAddGymModal = ref(false);
const addGymForm = ref({ name: '', admin_name: '', admin_email: '', admin_password: '' });
const addGymLoading = ref(false);
const addGymError = ref('');

async function checkAuth() {
  try {
    const { data } = await platformAuth.user();
    if (data.success && data.user) {
      user.value = data.user as { id: number; name: string; email: string };
      isLoggedIn.value = true;
      await loadGyms();
      void loadBilling(); // for the "receipts waiting" count on the Billing tab
    } else {
      isLoggedIn.value = false;
    }
  } catch {
    isLoggedIn.value = false;
  } finally {
    loading.value = false;
  }
}

async function loadGyms() {
  gymsLoading.value = true;
  try {
    const { data } = await platformGyms.list();
    if (data.success) {
      gyms.value = data.gyms as Branch[];
    }
  } catch {
    gyms.value = [];
  } finally {
    gymsLoading.value = false;
  }
}

async function loadAnalytics() {
  analyticsLoading.value = true;
  try {
    const { data } = await platformAnalytics.get();
    if (data.success) {
      analytics.value = { month: String(data.month ?? ''), totals: data.totals, branches: data.branches };
    }
  } catch {
    analytics.value = null;
  } finally {
    analyticsLoading.value = false;
  }
}

async function loadAuditLogs() {
  auditLoading.value = true;
  try {
    const { data } = await platformAuditLogs.list({
      gym_id: auditGymFilter.value || undefined,
      q: auditSearch.value.trim() || undefined,
      from: auditFrom.value || undefined,
      to: auditTo.value || undefined,
    });
    if (data.success) {
      auditLogs.value = data.logs as Record<string, unknown>[];
      auditTotal.value = Number((data as { total?: number }).total ?? auditLogs.value.length);
    }
  } catch {
    auditLogs.value = [];
  } finally {
    auditLoading.value = false;
  }
}

async function switchTab(tab: Tab) {
  currentTab.value = tab;
  actionNotice.value = '';
  closeMobileNav();
  if (tab === 'analytics') await loadAnalytics();
  if (tab === 'billing') await loadBilling();
  if (tab === 'audit') await loadAuditLogs();
}

async function handleLogin() {
  if (loginLoading.value) return;
  loginLoading.value = true;
  loginError.value = '';
  try {
    const { data } = await platformAuth.login(loginForm.value);
    if (data.success) {
      user.value = data.user as { id: number; name: string; email: string };
      isLoggedIn.value = true;
      await loadGyms();
      void loadBilling(); // for the "receipts waiting" count on the Billing tab
    } else {
      loginError.value = (data as { message?: string }).message || 'Login failed.';
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    loginError.value = ax.response?.data?.message || 'Invalid email or password.';
  } finally {
    loginLoading.value = false;
  }
}

async function handleLogout() {
  try {
    await platformAuth.logout();
  } catch {
    // ignore
  }
  isLoggedIn.value = false;
  user.value = null;
  loginForm.value = { email: '', password: '', remember: false };
}

function usage(count: unknown, limit: unknown): string {
  return limit === null || limit === undefined ? `${Number(count) || 0}` : `${Number(count) || 0} / ${Number(limit)}`;
}

function isOverLimit(count: unknown, limit: unknown): boolean {
  return limit !== null && limit !== undefined && (Number(count) || 0) >= Number(limit);
}

function canActivate(gym: Record<string, unknown>): boolean {
  return !['active', 'trial'].includes(String(gym.effective_status ?? gym.subscription_status));
}

async function setSubscription(gymId: number, field: 'subscription_plan' | 'subscription_status' | 'subscription_expires_at', value: string) {
  actionError.value = '';
  try {
    const { data } = await platformGyms.update(gymId, { [field]: value });
    if (data.success) {
      await loadGyms();
    } else {
      actionError.value = (data as { message?: string }).message || 'Failed to update gym.';
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    actionError.value = ax.response?.data?.message || 'Failed to update gym.';
  }
}

async function deleteGym(gymId: number, name: unknown) {
  if (!confirm(`Archive "${name}"? Its Admin will no longer be able to sign in.`)) return;
  actionError.value = '';
  try {
    const { data } = await platformGyms.delete(gymId);
    if (data.success) {
      await loadGyms();
    } else {
      actionError.value = (data as { message?: string }).message || 'Failed to archive gym.';
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    actionError.value = ax.response?.data?.message || 'Failed to archive gym.';
  }
}

const showPasswordModal = ref(false);
const passwordForm = ref({ current_password: '', new_password: '', new_password_confirmation: '' });
const passwordSaving = ref(false);
const passwordError = ref('');
const accountNotice = ref('');

function openPasswordModal() {
  passwordForm.value = { current_password: '', new_password: '', new_password_confirmation: '' };
  passwordError.value = '';
  showPasswordModal.value = true;
}

async function submitPasswordChange() {
  if (passwordSaving.value) return;
  if (passwordForm.value.new_password !== passwordForm.value.new_password_confirmation) {
    passwordError.value = 'New passwords do not match.';
    return;
  }
  passwordSaving.value = true;
  passwordError.value = '';
  try {
    await platformAuth.changePassword({ ...passwordForm.value });
    showPasswordModal.value = false;
    accountNotice.value = 'Password changed successfully. Use your new password the next time you sign in.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string } } };
    passwordError.value = ax.response?.data?.message || 'Failed to change password.';
  } finally {
    passwordSaving.value = false;
  }
}

function openAddGymModal() {
  addGymForm.value = { name: '', admin_name: '', admin_email: '', admin_password: '' };
  addGymError.value = '';
  showAddGymModal.value = true;
}

async function submitAddGym() {
  if (addGymLoading.value) return;
  addGymLoading.value = true;
  addGymError.value = '';
  try {
    const { data } = await platformGyms.create(addGymForm.value);
    if (data.success) {
      showAddGymModal.value = false;
      await loadGyms();
    } else {
      addGymError.value = (data as { message?: string }).message || 'Failed to create gym.';
    }
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
    const errors = ax.response?.data?.errors;
    addGymError.value = errors ? Object.values(errors).flat().join(' ') : ax.response?.data?.message || 'Failed to create gym.';
  } finally {
    addGymLoading.value = false;
  }
}

function statusBadgeClass(status: unknown): string {
  switch (String(status)) {
    case 'active':
      return 'badge-active';
    case 'trial':
      return 'badge-trial';
    case 'suspended':
    case 'cancelled':
      return 'badge-suspended';
    case 'expired':
      return 'badge-expired';
    default:
      return 'badge-pending';
  }
}

function formatCurrency(value: unknown): string {
  return '₱' + Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

onMounted(checkAuth);
</script>

<template>
  <Head title="Platform Admin" />

  <div class="platform-shell fg-marble-bg" :class="{ 'is-portal': !loading }">
    <div v-if="loading" class="platform-loading">Loading…</div>

    <!-- Same look as the main sign-in page (fitgym/Portal.vue) -->
    <div v-else-if="!isLoggedIn" class="fitgym-entry fg-marble-bg">
      <svg class="entry-deco entry-deco--dumbbell-1" viewBox="0 0 100 40" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
        <rect x="2" y="10" width="10" height="20" rx="2" />
        <rect x="14" y="15" width="6" height="10" rx="1.5" />
        <rect x="20" y="18" width="60" height="4" rx="2" />
        <rect x="80" y="15" width="6" height="10" rx="1.5" />
        <rect x="88" y="10" width="10" height="20" rx="2" />
      </svg>
      <svg class="entry-deco entry-deco--dumbbell-2" viewBox="0 0 100 40" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
        <rect x="2" y="10" width="10" height="20" rx="2" />
        <rect x="14" y="15" width="6" height="10" rx="1.5" />
        <rect x="20" y="18" width="60" height="4" rx="2" />
        <rect x="80" y="15" width="6" height="10" rx="1.5" />
        <rect x="88" y="10" width="10" height="20" rx="2" />
      </svg>
      <svg class="entry-deco entry-deco--pulse" viewBox="0 0 200 40" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M0 20h30l10-14 12 28 10-20 8 12 10-6h130" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      <svg class="entry-deco entry-deco--kettlebell" viewBox="0 0 40 46" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M14 10c0-5 3-8 6-8s6 3 6 8" />
        <circle cx="20" cy="28" r="16" />
      </svg>
      <svg class="entry-deco entry-deco--plate" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
        <circle cx="20" cy="20" r="18" />
        <circle cx="20" cy="20" r="7" />
      </svg>

      <div class="entry-shell">
        <header class="entry-brand">
          <div class="entry-logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M6.5 6.5 17.5 17.5" />
              <path d="M4 8v8" />
              <path d="M20 8v8" />
              <path d="M7 5v14" />
              <path d="M17 5v14" />
            </svg>
          </div>
          <div>
            <p class="entry-eyebrow">FitGym Platform</p>
            <h1 class="entry-wordmark">Super Admin</h1>
          </div>
        </header>

        <section class="entry-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
          <p class="entry-kicker" :style="{ color: portal.textDim }">
            Sign in to the <span class="entry-kicker-accent">Super Admin Portal</span>
          </p>
          <p class="entry-blurb" :style="{ color: portal.textDim }">Manage every gym branch, its subscription and billing, announcements, and audit logs.</p>

          <div v-if="loginError" class="entry-error">{{ loginError }}</div>

          <form class="entry-form" @submit.prevent="handleLogin">
            <div class="entry-field">
              <label for="platform-email" :style="{ color: portal.textDim }">Email Address</label>
              <input
                id="platform-email"
                v-model="loginForm.email"
                type="email"
                required
                autocomplete="username"
                placeholder="your.email@example.com"
                :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
              />
            </div>

            <div class="entry-field">
              <div class="entry-field-head">
                <label for="platform-password" :style="{ color: portal.textDim }">Password</label>
                <a href="/forgot-password">Forgot Password?</a>
              </div>
              <div class="fg-password-field">
                <input
                  id="platform-password"
                  v-model="loginForm.password"
                  :type="pw.type('login')"
                  required
                  autocomplete="current-password"
                  placeholder="Enter your password"
                  :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
                />
                <PasswordToggle :shown="pw.isShown('login')" @toggle="pw.toggle('login')" />
              </div>
            </div>

            <label class="entry-remember" :style="{ color: portal.textDim }">
              <input v-model="loginForm.remember" type="checkbox" />
              Remember me
            </label>

            <button type="submit" class="entry-primary" :disabled="loginLoading">
              {{ loginLoading ? 'Signing In…' : 'Sign In as Super Admin' }}
            </button>
          </form>
        </section>

        <p class="entry-footnote">For the FitGym platform team only. Gym staff and members sign in on the main page.</p>
      </div>
    </div>

    <!-- Same layout as the Admin, Staff and Member portals: sidebar, header, content -->
    <div v-else class="admin-shell fg-shell fg-marble-bg platform-portal flex overflow-hidden">
      <div v-if="mobileNavOpen" class="fg-nav-backdrop" aria-hidden="true" @click="closeMobileNav" />
      <aside id="platform-sidebar" class="admin-sidebar fg-sidebar flex w-[18rem] shrink-0 flex-col px-5 py-8" :class="{ 'is-open': mobileNavOpen }">
        <div class="flex items-start justify-between gap-3 pb-6">
          <div class="min-w-0">
            <h1 class="text-2xl font-bold break-words text-[var(--text-primary)]">FitGym</h1>
            <p class="mt-1 text-sm text-[var(--text-secondary)]">Super Admin Portal</p>
          </div>
          <button type="button" class="fg-menu-btn fg-sidebar-close" aria-label="Close navigation menu" @click="closeMobileNav">
            <X class="h-5 w-5" aria-hidden="true" />
          </button>
        </div>

        <div class="admin-profile-card rounded-2xl border p-4">
          <div class="flex items-center gap-3">
            <div class="admin-avatar flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-lg font-bold">{{ userInitials }}</div>
            <div class="min-w-0">
              <p class="truncate font-semibold text-[var(--text-primary)]">{{ user?.name }}</p>
              <p class="truncate text-xs text-[var(--text-secondary)]">Super Admin</p>
            </div>
          </div>
        </div>

        <nav class="mt-6 flex flex-1 flex-col gap-2">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            class="admin-nav-item flex w-full items-center justify-between rounded-xl px-4 py-3 text-left transition-colors"
            :class="{ active: currentTab === tab.id }"
            @click="switchTab(tab.id)"
          >
            <span class="block text-sm font-semibold">{{ tab.label }}</span>
            <span v-if="tab.id === 'billing' && billing?.pending_proofs" class="tab-count">{{ billing.pending_proofs }}</span>
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
            aria-controls="platform-sidebar"
            :aria-expanded="mobileNavOpen"
            @click="mobileNavOpen = true"
          >
            <Menu class="h-5 w-5" aria-hidden="true" />
          </button>
          <div class="min-w-0">
            <p class="truncate text-base font-bold text-[var(--text-primary)]">FitGym</p>
            <p class="truncate text-xs text-[var(--text-secondary)]">Super Admin Portal</p>
          </div>
        </div>

        <header class="admin-header flex flex-col gap-4 border-b px-4 py-5 sm:px-6 sm:py-6 lg:flex-row lg:items-center lg:justify-between lg:px-10">
          <div class="min-w-0">
            <h2 class="text-2xl font-bold text-[var(--fg-ink)]">{{ currentTabInfo.label }}</h2>
            <p class="mt-1 max-w-2xl text-sm text-[var(--fg-ink-soft)]">{{ currentTabInfo.description }}</p>
          </div>

          <div class="flex flex-wrap items-center gap-3">
            <FgThemeToggle class="admin-header-button rounded-xl px-4 py-3 text-sm" />
            <div class="admin-header-card rounded-xl border px-4 py-3">
              <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-secondary)]">Super Admin</p>
              <p class="mt-1 text-sm font-semibold break-all text-[var(--text-primary)]">{{ user?.email }}</p>
            </div>
            <button type="button" class="admin-header-button rounded-xl px-4 py-3 text-sm font-semibold transition-opacity hover:opacity-90" @click="openPasswordModal">
              Change Password
            </button>
            <div class="admin-header-card rounded-xl border px-4 py-3">
              <p class="text-xs uppercase tracking-[0.2em] text-[var(--text-secondary)]">Role</p>
              <p class="mt-1 text-sm font-semibold text-[var(--fg-card-gold)]">Super Admin</p>
            </div>
          </div>
        </header>

        <main class="admin-main flex-1 overflow-x-hidden overflow-y-auto px-4 py-5 sm:px-6 sm:py-6 lg:px-10">
      <p v-if="accountNotice" class="account-notice" role="status">{{ accountNotice }}</p>
      <div v-if="actionError" class="entry-error">{{ actionError }}</div>
      <p v-if="actionNotice" class="account-notice" role="status">{{ actionNotice }}</p>

      <!-- Branches tab -->
      <section v-if="currentTab === 'branches'">
        <div class="section-toolbar">
          <button type="button" class="ghost-btn" :style="{ borderColor: portal.border, color: 'var(--fg-ink-soft)' }" @click="toggleArchived">
            {{ showArchived ? 'Hide archived branches' : 'Show archived branches' }}
          </button>
          <button type="button" class="primary-btn add-btn" :style="{ backgroundColor: portal.purple }" @click="openAddGymModal">
            + Add Branch
          </button>
        </div>
        <p class="hint-row">
          Plan limits — Trial: 50 members / 3 staff · Basic: 200 / 10 · Pro: unlimited. A branch is locked out when it is suspended or its end date has passed.
        </p>

        <div class="table-container" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
          <table>
            <thead>
              <tr>
                <th>Branch</th>
                <th>Slug</th>
                <th>Members</th>
                <th>Staff</th>
                <th>Plan</th>
                <th>Ends</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="gym in gyms" :key="String(gym.id)">
                <td>
                  <span class="branch-name">{{ gym.name }}</span>
                  <span class="branch-sub">{{ gym.admin ? `Admin: ${gym.admin.name} · ${gym.admin.email}` : 'No Admin assigned' }}</span>
                </td>
                <td>{{ gym.slug }}</td>
                <td :class="{ 'over-limit': isOverLimit(gym.member_count, gym.member_limit) }">{{ usage(gym.member_count, gym.member_limit) }}</td>
                <td :class="{ 'over-limit': isOverLimit(gym.trainer_count, gym.staff_limit) }">{{ usage(gym.trainer_count, gym.staff_limit) }}</td>
                <td>
                  <select
                    :value="gym.subscription_plan"
                    :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
                    @change="setSubscription(Number(gym.id), 'subscription_plan', ($event.target as HTMLSelectElement).value)"
                  >
                    <option value="trial">Trial</option>
                    <option value="basic">Basic</option>
                    <option value="pro">Pro</option>
                  </select>
                </td>
                <td>
                  <input
                    type="date"
                    class="date-input"
                    :value="gym.subscription_expires_at || ''"
                    :aria-label="`End date for ${gym.name}`"
                    :title="gym.subscription_expires_at ? 'Trial / subscription end date' : 'No end date'"
                    :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
                    @change="setSubscription(Number(gym.id), 'subscription_expires_at', ($event.target as HTMLInputElement).value)"
                  />
                  <span v-if="endsInfo(gym)" :class="['ends-info', `ends-info--${endsInfo(gym)?.level}`]">{{ endsInfo(gym)?.text }}</span>
                </td>
                <td>
                  <span :class="['badge', statusBadgeClass(gym.effective_status ?? gym.subscription_status)]">{{ gym.effective_status ?? gym.subscription_status }}</span>
                </td>
                <td class="actions-cell">
                  <button type="button" class="action-btn manage" @click="openManage(gym)">
                    Manage
                  </button>
                  <button type="button" class="action-btn activate" @click="openRecordPayment(gym)">
                    Record Payment
                  </button>
                  <button
                    v-if="!canActivate(gym)"
                    type="button"
                    class="action-btn suspend"
                    @click="setSubscription(Number(gym.id), 'subscription_status', 'suspended')"
                  >
                    Suspend
                  </button>
                  <button
                    v-else
                    type="button"
                    class="action-btn activate"
                    @click="setSubscription(Number(gym.id), 'subscription_status', 'active')"
                  >
                    Activate
                  </button>
                  <button type="button" class="action-btn delete" @click="deleteGym(Number(gym.id), gym.name)">
                    Delete
                  </button>
                </td>
              </tr>
              <tr v-if="!gymsLoading && gyms.length === 0">
                <td colspan="8" class="empty-row">No branches yet.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <template v-if="showArchived">
          <h2 class="section-title">Archived branches</h2>
          <div class="table-container" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
            <table>
              <thead>
                <tr>
                  <th>Branch</th>
                  <th>Members</th>
                  <th>Staff</th>
                  <th>Added</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="gym in archivedGyms" :key="String(gym.id)">
                  <td>
                    <span class="branch-name">{{ gym.name }}</span>
                    <span class="branch-sub">{{ gym.admin ? `Admin: ${gym.admin.name} · ${gym.admin.email}` : 'No Admin assigned' }}</span>
                  </td>
                  <td>{{ gym.member_count }}</td>
                  <td>{{ gym.trainer_count }}</td>
                  <td>{{ gym.created_on }}</td>
                  <td class="actions-cell">
                    <button type="button" class="action-btn activate" @click="restoreGym(gym)">Restore</button>
                  </td>
                </tr>
                <tr v-if="archivedGyms.length === 0">
                  <td colspan="5" class="empty-row">No archived branches.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>

      <!-- Billing tab: what branches pay FitGym -->
      <section v-else-if="currentTab === 'billing'">
        <div v-if="billingLoading && !billing" class="empty-row">Loading billing…</div>
        <template v-else-if="billing">
          <p class="hint-row">
            What gym branches pay FitGym for their subscription. Monthly prices: Basic {{ formatCurrency(billing.plan_prices.basic) }} · Pro {{ formatCurrency(billing.plan_prices.pro) }}.
            Branches send their receipt from <strong>Settings → Subscription</strong>; approve it below once the money is in. For cash or other payments, use <strong>Record Payment</strong> on the Branches tab. Either way the plan and end date update automatically.
          </p>

          <h2 class="section-title">Receipts from branches<span v-if="billing.pending_proofs" class="tab-count">{{ billing.pending_proofs }} waiting</span></h2>
          <div class="table-container" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
            <table>
              <thead>
                <tr>
                  <th>Sent</th>
                  <th>Branch</th>
                  <th>Plan</th>
                  <th>Amount</th>
                  <th>Method / Reference</th>
                  <th>Receipt</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in billing.proofs" :key="r.id">
                  <td class="nowrap">{{ r.sent_on }}</td>
                  <td>{{ r.gym_name }}</td>
                  <td class="capitalize">{{ r.plan }} <span class="branch-sub">{{ r.months }} month{{ r.months === 1 ? '' : 's' }}</span></td>
                  <td>{{ formatCurrency(r.amount) }}</td>
                  <td>{{ r.payment_method }}<span class="branch-sub">{{ r.reference || '-' }}</span><span v-if="r.notes" class="branch-sub">{{ r.notes }}</span></td>
                  <td>
                    <a v-if="r.receipt_url" :href="r.receipt_url" target="_blank" rel="noopener" class="receipt-link">View receipt</a>
                    <span v-else>-</span>
                  </td>
                  <td>
                    <div v-if="r.status === 'Pending'" class="proof-actions">
                      <button type="button" class="action-btn activate" :disabled="proofBusy === r.id" @click="approveProof(r)">Approve</button>
                      <button type="button" class="action-btn delete" :disabled="proofBusy === r.id" @click="openReject(r)">Reject</button>
                    </div>
                    <template v-else>
                      {{ r.status }}<span v-if="r.review_note" class="branch-sub">{{ r.review_note }}</span>
                    </template>
                  </td>
                </tr>
                <tr v-if="billing.proofs.length === 0">
                  <td colspan="7" class="empty-row">No receipts sent by branches yet.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <h2 class="section-title">Recorded payments</h2>
          <div class="stat-grid">
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Income this month</p>
              <p class="stat-value">{{ formatCurrency(billing.totals.this_month) }}</p>
              <p class="stat-sub" :style="{ color: portal.textDim }">{{ billing.month }}</p>
            </div>
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Income all time</p>
              <p class="stat-value">{{ formatCurrency(billing.totals.all_time) }}</p>
              <p class="stat-sub" :style="{ color: portal.textDim }">{{ billing.totals.payments }} payment{{ billing.totals.payments === 1 ? '' : 's' }}</p>
            </div>
          </div>

          <div class="table-container" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
            <table>
              <thead>
                <tr>
                  <th>Paid on</th>
                  <th>Branch</th>
                  <th>Plan</th>
                  <th>Covers</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Reference</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in billing.payments" :key="String(p.id)">
                  <td class="nowrap">{{ p.paid_on }}</td>
                  <td>{{ p.gym_name }}</td>
                  <td class="capitalize">{{ p.plan }} <span class="branch-sub">{{ p.months }} month{{ Number(p.months) === 1 ? '' : 's' }}</span></td>
                  <td>{{ p.period }}</td>
                  <td>{{ formatCurrency(p.amount) }}</td>
                  <td>{{ p.payment_method }}</td>
                  <td>{{ p.reference || '-' }}<span v-if="p.notes" class="branch-sub">{{ p.notes }}</span></td>
                </tr>
                <tr v-if="billing.payments.length === 0">
                  <td colspan="7" class="empty-row">No subscription payments recorded yet.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <h2 class="section-title">Where branches pay FitGym</h2>
          <p class="hint-row">Every branch Admin sees these details on <strong>Settings → Subscription</strong> in their portal.</p>
          <div class="table-container announcement-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
            <div v-if="paymentDetailsError" class="entry-error" role="alert">{{ paymentDetailsError }}</div>
            <p v-if="paymentDetailsNotice" class="account-notice" role="status">{{ paymentDetailsNotice }}</p>
            <form @submit.prevent="savePaymentDetails">
              <div class="field-row">
                <div class="field">
                  <label for="pd-gcash" :style="{ color: portal.textDim }">GCash number</label>
                  <input id="pd-gcash" v-model="paymentDetailsForm.gcash_number" type="text" maxlength="20" placeholder="09XXXXXXXXX" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                </div>
                <div class="field">
                  <label for="pd-gcash-name" :style="{ color: portal.textDim }">GCash account name</label>
                  <input id="pd-gcash-name" v-model="paymentDetailsForm.gcash_name" type="text" maxlength="120" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                </div>
              </div>
              <div class="field">
                <label for="pd-bank" :style="{ color: portal.textDim }">Bank transfer details (optional)</label>
                <textarea id="pd-bank" v-model="paymentDetailsForm.bank_details" rows="2" maxlength="500" placeholder="Bank, account name, account number" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              </div>
              <div class="field">
                <label for="pd-instructions" :style="{ color: portal.textDim }">Instructions for branches (optional)</label>
                <textarea id="pd-instructions" v-model="paymentDetailsForm.instructions" rows="3" maxlength="1000" placeholder="e.g. Send your GCash reference number to the FitGym admin after paying." :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              </div>
              <div class="modal-actions">
                <button type="submit" class="primary-btn" :style="{ backgroundColor: portal.purple }" :disabled="paymentDetailsSaving">
                  {{ paymentDetailsSaving ? 'Saving…' : 'Save Payment Details' }}
                </button>
              </div>
            </form>
          </div>
        </template>
      </section>

      <!-- Analytics tab -->
      <section v-else-if="currentTab === 'analytics'">
        <div v-if="analyticsLoading" class="empty-row">Loading analytics…</div>
        <template v-else-if="analytics">
          <p class="hint-row">"Revenue" is what members paid the branches (approved payments only). This month: {{ analytics.month }}.</p>
          <div class="stat-grid">
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Branches</p>
              <p class="stat-value">{{ analytics.totals.gyms }}</p>
            </div>
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Members</p>
              <p class="stat-value">{{ analytics.totals.members }}</p>
              <p class="stat-sub" :style="{ color: portal.textDim }">{{ analytics.totals.active_members }} with a paid, current membership</p>
            </div>
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Revenue this month</p>
              <p class="stat-value">{{ formatCurrency(analytics.totals.revenue_month) }}</p>
              <p class="stat-sub" :style="{ color: portal.textDim }">{{ formatCurrency(analytics.totals.revenue) }} all time</p>
            </div>
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Check-ins this month</p>
              <p class="stat-value">{{ analytics.totals.checkins_month }}</p>
            </div>
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Payments to approve</p>
              <p class="stat-value">{{ analytics.totals.pending_payments }}</p>
              <p class="stat-sub" :style="{ color: portal.textDim }">Across all branches</p>
            </div>
          </div>

          <div class="table-container" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
            <table>
              <thead>
                <tr>
                  <th>Branch</th>
                  <th>Members</th>
                  <th>Expired</th>
                  <th>Awaiting approval</th>
                  <th>Staff</th>
                  <th>Check-ins (month)</th>
                  <th>Revenue (month)</th>
                  <th>Revenue (all time)</th>
                  <th>Payments to approve</th>
                  <th>Plan</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="branch in analytics.branches" :key="String(branch.id)">
                  <td>{{ branch.name }}</td>
                  <td>{{ branch.members }} <span class="branch-sub">{{ branch.active_members }} paid & current</span></td>
                  <td :class="{ 'over-limit': Number(branch.expired_members) > 0 }">{{ branch.expired_members }}</td>
                  <td>{{ branch.awaiting_approval }}</td>
                  <td>{{ branch.trainers }}</td>
                  <td>{{ branch.checkins_month }}</td>
                  <td>{{ formatCurrency(branch.revenue_month) }}</td>
                  <td>{{ formatCurrency(branch.revenue) }}</td>
                  <td :class="{ 'over-limit': Number(branch.pending_payments) > 0 }">{{ branch.pending_payments }}</td>
                  <td class="capitalize">{{ branch.subscription_plan }}</td>
                  <td><span :class="['badge', statusBadgeClass(branch.subscription_status)]">{{ branch.subscription_status }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>

      <!-- Announcements tab -->
      <section v-else-if="currentTab === 'announcements'">
        <p class="hint-row">Send a message to the Admin of every branch, or just one. It shows up in their Notifications panel.</p>
        <div class="table-container announcement-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
          <div v-if="announcementError" class="entry-error" role="alert">{{ announcementError }}</div>
          <p v-if="announcementNotice" class="account-notice" role="status">{{ announcementNotice }}</p>
          <form @submit.prevent="sendAnnouncement">
            <div class="field">
              <label for="ann-to" :style="{ color: portal.textDim }">Send to</label>
              <select id="ann-to" v-model="announcementForm.gym_id" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }">
                <option value="">All branches</option>
                <option v-for="gym in gyms" :key="String(gym.id)" :value="String(gym.id)">{{ gym.name }}</option>
              </select>
            </div>
            <div class="field">
              <label for="ann-title" :style="{ color: portal.textDim }">Title</label>
              <input id="ann-title" v-model="announcementForm.title" type="text" maxlength="120" required placeholder="e.g. System maintenance on Saturday" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
            </div>
            <div class="field">
              <label for="ann-message" :style="{ color: portal.textDim }">Message</label>
              <textarea id="ann-message" v-model="announcementForm.message" rows="5" maxlength="1000" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
            </div>
            <div class="modal-actions">
              <button type="submit" class="primary-btn" :style="{ backgroundColor: portal.purple }" :disabled="announcementSending">
                {{ announcementSending ? 'Sending…' : 'Send Announcement' }}
              </button>
            </div>
          </form>
        </div>
      </section>

      <!-- Audit Logs tab -->
      <section v-else-if="currentTab === 'audit'">
        <form class="section-toolbar audit-filters" @submit.prevent="loadAuditLogs">
          <input
            v-model="auditSearch"
            type="search"
            placeholder="Search who or what…"
            aria-label="Search the audit log"
            :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
          />
          <label class="audit-date">From <input v-model="auditFrom" type="date" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" /></label>
          <label class="audit-date">To <input v-model="auditTo" type="date" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" /></label>
          <select
            v-model="auditGymFilter"
            aria-label="Branch"
            :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
          >
            <option value="">All branches</option>
            <option v-for="gym in gyms" :key="String(gym.id)" :value="String(gym.id)">{{ gym.name }}</option>
          </select>
          <button type="submit" class="primary-btn add-btn" :style="{ backgroundColor: portal.purple }">Search</button>
        </form>
        <p class="hint-row">Showing {{ auditLogs.length }} of {{ auditTotal }} entries, newest first. Times are Philippine time.</p>

        <div class="table-container" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
          <table>
            <thead>
              <tr>
                <th>When</th>
                <th>Branch</th>
                <th>Who</th>
                <th>Action</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in auditLogs" :key="String(log.id)">
                <td class="nowrap">{{ log.created_at }}</td>
                <td>{{ log.gym_name }}</td>
                <td>{{ log.user_name }} <span class="role-tag">({{ log.role }})</span></td>
                <td>{{ log.action }}</td>
                <td>{{ log.description }}</td>
              </tr>
              <tr v-if="!auditLoading && auditLogs.length === 0">
                <td colspan="5" class="empty-row">No activity recorded yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
        </main>
      </div>
    </div>

    <!-- Change Password modal -->
    <div v-if="showPasswordModal" class="modal-overlay" @click.self="showPasswordModal = false">
      <div class="modal-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
        <h3>Change Password</h3>
        <div v-if="passwordError" class="entry-error" role="alert">{{ passwordError }}</div>
        <form @submit.prevent="submitPasswordChange">
          <div class="field">
            <label for="sa-current" :style="{ color: portal.textDim }">Current Password</label>
            <div class="fg-password-field">
              <input id="sa-current" v-model="passwordForm.current_password" :type="pw.type('current')" required autocomplete="current-password" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              <PasswordToggle :shown="pw.isShown('current')" @toggle="pw.toggle('current')" />
            </div>
          </div>
          <div class="field">
            <label for="sa-new" :style="{ color: portal.textDim }">New Password (at least 8 characters)</label>
            <div class="fg-password-field">
              <input id="sa-new" v-model="passwordForm.new_password" :type="pw.type('new')" required minlength="8" autocomplete="new-password" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              <PasswordToggle :shown="pw.isShown('new')" @toggle="pw.toggle('new')" />
            </div>
          </div>
          <div class="field">
            <label for="sa-confirm" :style="{ color: portal.textDim }">Confirm New Password</label>
            <div class="fg-password-field">
              <input id="sa-confirm" v-model="passwordForm.new_password_confirmation" :type="pw.type('confirm')" required minlength="8" autocomplete="new-password" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              <PasswordToggle :shown="pw.isShown('confirm')" @toggle="pw.toggle('confirm')" />
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="ghost-btn" :style="{ borderColor: portal.border, color: portal.textDim }" @click="showPasswordModal = false">
              Cancel
            </button>
            <button type="submit" class="primary-btn" :style="{ backgroundColor: portal.purple }" :disabled="passwordSaving">
              {{ passwordSaving ? 'Saving…' : 'Change Password' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Manage Branch modal -->
    <div v-if="manageGym" class="modal-overlay" @click.self="manageGym = null">
      <div class="modal-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
        <h3>Manage {{ manageGym.name }}</h3>
        <div v-if="manageError" class="entry-error" role="alert">{{ manageError }}</div>
        <p v-if="manageNotice" class="account-notice" role="status">{{ manageNotice }}</p>

        <div class="manage-admin" :style="{ borderColor: portal.border }">
          <p class="stat-label" :style="{ color: portal.textDim }">Branch Admin</p>
          <template v-if="manageGym.admin">
            <p class="manage-admin-name">{{ manageGym.admin.name }}</p>
            <p :style="{ color: portal.textDim }">{{ manageGym.admin.email }}</p>
            <button type="button" class="ghost-btn manage-reset" :style="{ borderColor: portal.border, color: 'var(--fg-card-ink)' }" :disabled="resetSending" @click="sendAdminReset">
              {{ resetSending ? 'Sending…' : 'Email a password reset link' }}
            </button>
          </template>
          <p v-else :style="{ color: portal.textDim }">No Admin is assigned to this branch.</p>
          <p class="branch-sub">Added {{ manageGym.created_on }} · {{ manageGym.member_count }} members · {{ manageGym.trainer_count }} staff</p>
        </div>

        <form @submit.prevent="saveManage">
          <div v-if="manageGym.admin" class="field">
            <label for="mg-admin-name" :style="{ color: portal.textDim }">Admin's Name</label>
            <input id="mg-admin-name" v-model="manageForm.admin_name" type="text" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="field">
            <label for="mg-name" :style="{ color: portal.textDim }">Branch Name</label>
            <input id="mg-name" v-model="manageForm.name" type="text" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="field">
            <label for="mg-email" :style="{ color: portal.textDim }">Contact Email</label>
            <input id="mg-email" v-model="manageForm.email" type="email" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="field">
            <label for="mg-phone" :style="{ color: portal.textDim }">Phone</label>
            <input id="mg-phone" v-model="manageForm.phone" type="tel" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="field">
            <label for="mg-address" :style="{ color: portal.textDim }">Address</label>
            <textarea id="mg-address" v-model="manageForm.address" rows="2" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="modal-actions">
            <button type="button" class="ghost-btn" :style="{ borderColor: portal.border, color: portal.textDim }" @click="manageGym = null">
              Cancel
            </button>
            <button type="submit" class="primary-btn" :style="{ backgroundColor: portal.purple }" :disabled="manageSaving">
              {{ manageSaving ? 'Saving…' : 'Save Changes' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Reject a branch's receipt -->
    <div v-if="rejectFor" class="modal-overlay" @click.self="rejectFor = null">
      <div class="modal-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
        <h3>Reject Payment · {{ rejectFor.gym_name }}</h3>
        <div v-if="rejectError" class="entry-error" role="alert">{{ rejectError }}</div>
        <form @submit.prevent="submitReject">
          <div class="field">
            <label for="rj-reason" :style="{ color: portal.textDim }">Reason (the branch Admin sees this)</label>
            <textarea id="rj-reason" v-model="rejectReason" rows="3" maxlength="500" required placeholder="e.g. No payment with this reference number was received." :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="modal-actions">
            <button type="button" class="ghost-btn" :style="{ borderColor: portal.border, color: portal.textDim }" @click="rejectFor = null">
              Cancel
            </button>
            <button type="submit" class="primary-btn" :style="{ backgroundColor: portal.purple }" :disabled="proofBusy !== null">
              {{ proofBusy !== null ? 'Saving…' : 'Reject Payment' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Record Subscription Payment modal -->
    <div v-if="recordFor && billing" class="modal-overlay" @click.self="recordFor = null">
      <div class="modal-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
        <h3>Record Payment · {{ recordFor.name }}</h3>
        <div v-if="recordError" class="entry-error" role="alert">{{ recordError }}</div>
        <form @submit.prevent="submitRecordPayment">
          <div class="field-row">
            <div class="field">
              <label for="rp-plan" :style="{ color: portal.textDim }">Plan</label>
              <select id="rp-plan" v-model="recordForm.plan" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" @change="syncRecordAmount">
                <option value="basic">Basic ({{ formatCurrency(billing.plan_prices.basic) }}/month)</option>
                <option value="pro">Pro ({{ formatCurrency(billing.plan_prices.pro) }}/month)</option>
              </select>
            </div>
            <div class="field">
              <label for="rp-months" :style="{ color: portal.textDim }">Months</label>
              <input id="rp-months" v-model.number="recordForm.months" type="number" min="1" max="24" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" @input="syncRecordAmount" />
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label for="rp-amount" :style="{ color: portal.textDim }">Amount received (PHP)</label>
              <input id="rp-amount" v-model.number="recordForm.amount" type="number" min="0" step="0.01" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
            </div>
            <div class="field">
              <label for="rp-method" :style="{ color: portal.textDim }">Paid by</label>
              <select id="rp-method" v-model="recordForm.payment_method" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }">
                <option v-for="m in billing.methods" :key="m" :value="m">{{ m }}</option>
              </select>
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label for="rp-ref" :style="{ color: portal.textDim }">Reference no. (optional)</label>
              <input id="rp-ref" v-model="recordForm.reference" type="text" maxlength="100" placeholder="e.g. GCash ref" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
            </div>
            <div class="field">
              <label for="rp-date" :style="{ color: portal.textDim }">Paid on</label>
              <input id="rp-date" v-model="recordForm.paid_on" type="date" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
            </div>
          </div>
          <div class="field">
            <label for="rp-notes" :style="{ color: portal.textDim }">Notes (optional)</label>
            <textarea id="rp-notes" v-model="recordForm.notes" rows="2" maxlength="500" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <p class="record-preview">After this payment, {{ recordFor.name }} is <strong>active on {{ recordForm.plan === 'pro' ? 'Pro' : 'Basic' }}</strong> until <strong>{{ recordNewEnd }}</strong>.</p>
          <div class="modal-actions">
            <button type="button" class="ghost-btn" :style="{ borderColor: portal.border, color: portal.textDim }" @click="recordFor = null">
              Cancel
            </button>
            <button type="submit" class="primary-btn" :style="{ backgroundColor: portal.purple }" :disabled="recording">
              {{ recording ? 'Saving…' : 'Record Payment' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Add Branch modal -->
    <div v-if="showAddGymModal" class="modal-overlay" @click.self="showAddGymModal = false">
      <div class="modal-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
        <h3>Add Branch</h3>
        <div v-if="addGymError" class="entry-error">{{ addGymError }}</div>
        <form @submit.prevent="submitAddGym">
          <div class="field">
            <label :style="{ color: portal.textDim }">Branch Name</label>
            <input v-model="addGymForm.name" type="text" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="field">
            <label :style="{ color: portal.textDim }">Admin Name</label>
            <input v-model="addGymForm.admin_name" type="text" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="field">
            <label :style="{ color: portal.textDim }">Admin Email</label>
            <input v-model="addGymForm.admin_email" type="email" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="field">
            <label :style="{ color: portal.textDim }">Admin Password</label>
            <div class="fg-password-field">
              <input v-model="addGymForm.admin_password" :type="pw.type('branch')" required minlength="8" autocomplete="new-password" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              <PasswordToggle :shown="pw.isShown('branch')" @toggle="pw.toggle('branch')" />
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="ghost-btn" :style="{ borderColor: portal.border, color: portal.textDim }" @click="showAddGymModal = false">
              Cancel
            </button>
            <button type="submit" class="primary-btn" :style="{ backgroundColor: portal.purple }" :disabled="addGymLoading">
              {{ addGymLoading ? 'Creating…' : 'Create Branch' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.platform-shell {
  min-height: 100vh;
  color: var(--fg-card-ink);
  font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
  padding: 2rem 1.5rem;
}

.platform-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  color: var(--fg-ink-soft);
}

/* Sign-in: copied from the main sign-in page (fitgym/Portal.vue) so both look the same.
   The dark fields and the gold button come from the shared .fitgym-entry rules in app.css. */
.fitgym-entry {
  position: relative;
  display: flex;
  min-height: 100vh;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  padding: 1.25rem 1rem;
  color: var(--fg-card-ink);
}

.entry-deco {
  position: absolute;
  color: var(--fg-gold);
  opacity: 0.22;
  pointer-events: none;
}

.entry-deco--dumbbell-1 {
  width: 14rem;
  top: 8%;
  right: 6%;
  transform: rotate(-18deg);
}

.entry-deco--dumbbell-2 {
  width: 10rem;
  bottom: 10%;
  left: 4%;
  transform: rotate(24deg);
}

.entry-deco--pulse {
  width: 22rem;
  bottom: 22%;
  right: -2%;
  opacity: 0.06;
}

.entry-deco--kettlebell {
  width: 6rem;
  top: 42%;
  left: 10%;
  transform: rotate(-8deg);
}

.entry-deco--plate {
  width: 7rem;
  top: 6%;
  left: 42%;
  opacity: 0.04;
}

@media (max-width: 768px) {
  .entry-deco {
    display: none;
  }
}

.entry-shell {
  position: relative;
  width: 100%;
  max-width: 34rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.entry-brand {
  display: flex;
  align-items: center;
  gap: 0.875rem;
}

.entry-logo {
  display: grid;
  place-items: center;
  width: 3rem;
  height: 3rem;
  border-radius: 0.9rem;
  flex-shrink: 0;
  background-color: #8a2be2;
}

.entry-logo svg {
  width: 1.6rem;
  height: 1.6rem;
  color: #ffffff;
}

.entry-eyebrow {
  color: var(--fg-gold);
  margin: 0;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.entry-wordmark {
  color: var(--fg-ink);
  margin: 0.15rem 0 0;
  font-size: 1.6rem;
  font-weight: 700;
  line-height: 1.2;
}

.entry-card {
  border-radius: 1.25rem;
  border-width: 1px;
  border-style: solid;
  padding: 1.4rem 1.6rem;
  box-shadow: 0 18px 44px rgba(92, 70, 30, 0.1);
}

.entry-kicker {
  margin: 0 0 0.5rem;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.entry-kicker-accent {
  color: var(--fg-gold);
}

.entry-blurb {
  margin: 0 0 1.1rem;
  font-size: 0.85rem;
  line-height: 1.5;
}

.entry-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.entry-field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.entry-field label,
.entry-field-head label {
  font-size: 0.82rem;
  font-weight: 500;
}

.entry-field-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.entry-field-head a {
  color: var(--fg-gold);
  font-size: 0.8rem;
  text-decoration: none;
}

.entry-field-head a:hover {
  text-decoration: underline;
}

.entry-field input {
  width: 100%;
  border-radius: 0.65rem;
  border-width: 1px;
  border-style: solid;
  padding: 0.75rem;
  font-size: 0.92rem;
  outline: none;
}

.entry-remember {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  cursor: pointer;
}

.entry-remember input {
  width: 1rem;
  height: 1rem;
  cursor: pointer;
}

.entry-primary {
  width: 100%;
  border: none;
  border-radius: 0.7rem;
  padding: 0.85rem 1rem;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.18s ease;
}

.entry-primary:hover:not(:disabled) {
  opacity: 0.9;
}

.entry-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.entry-footnote {
  color: var(--fg-ink-soft);
  margin: 0;
  text-align: center;
  font-size: 0.78rem;
}

@media (max-width: 767.98px) {
  .entry-field input {
    font-size: 1rem;
  }
}

@media (max-width: 480px) {
  .entry-card {
    padding: 1.35rem;
  }

  .entry-wordmark {
    font-size: 1.35rem;
  }
}

.field {
  margin-bottom: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field label {
  font-size: 0.82rem;
}

.field input,
select {
  border-radius: 0.6rem;
  border: 1px solid;
  padding: 0.65rem 0.75rem;
  color: var(--fg-card-ink);
  font-size: 0.9rem;
  outline: none;
}

.primary-btn {
  border: none;
  border-radius: 0.6rem;
  padding: 0.75rem;
  color: var(--fg-accent-ink);
  font-weight: 600;
  cursor: pointer;
}

.primary-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.ghost-btn {
  border-radius: 0.5rem;
  border: 1px solid;
  background: transparent;
  padding: 0.5rem 0.9rem;
  cursor: pointer;
}

/* Signed in: the same sidebar + header shell as the Admin portal (base rules copied
   from Admin.vue; the glass and gold come from the shared rules in app.css). */
.platform-shell.is-portal {
  padding: 0;
}

.platform-portal {
  --text-primary: var(--fg-card-ink);
  --text-secondary: var(--fg-card-ink-soft);
  --border-color: var(--fg-card-border);
  min-height: 100vh;
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.admin-shell {
  background: var(--fg-card);
  border-color: rgba(var(--fg-accent-deep-rgb), 0.35);
}

/* backdrop-filter would trap the fixed-position mobile drawer inside the shell */
@media (min-width: 1024px) {
  .admin-shell {
    backdrop-filter: blur(12px);
  }
}

.admin-sidebar {
  background:
    linear-gradient(180deg, rgba(var(--fg-accent-rgb), 0.07) 0%, rgba(var(--fg-accent-rgb), 0) 45%),
    var(--fg-card-sidebar);
  border-right: 1px solid var(--border-color);
}

.admin-profile-card,
.admin-header-card,
.admin-header-button {
  background: var(--fg-card);
  border-color: var(--border-color);
  box-shadow: 0 0 0 1px var(--border-color), 0 10px 28px rgba(92, 70, 30, 0.08);
}

.admin-avatar {
  background: var(--fg-accent);
  color: var(--fg-accent-ink);
}

.admin-nav-item {
  background: transparent;
  border: 1px solid transparent;
  color: var(--text-primary);
  cursor: pointer;
}

.admin-nav-item:hover {
  background: rgba(var(--fg-accent-rgb), 0.1);
  border-color: rgba(var(--fg-accent-rgb), 0.3);
}

.admin-nav-item.active {
  background: rgba(var(--fg-accent-rgb), 0.92);
  box-shadow: 0 14px 28px rgba(var(--fg-accent-rgb), 0.28);
  color: #fff;
}

.admin-nav-item .tab-count {
  background: #1a1407;
  color: var(--fg-gold);
}

.admin-logout-btn {
  background: #c4544c;
  color: #fff;
  cursor: pointer;
}

.admin-header {
  background: transparent;
  border-color: rgba(var(--fg-accent-deep-rgb), 0.3);
}

.admin-header-button {
  border: 1px solid var(--border-color);
  color: var(--text-primary);
  cursor: pointer;
}

.admin-main {
  background: transparent;
}

.admin-main > section > :first-child {
  margin-top: 0;
}

.section-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.6rem;
  margin-bottom: 1rem;
}

.section-title {
  margin: 1.75rem 0 0.75rem;
  color: var(--fg-ink);
  font-size: 1.05rem;
  font-weight: 700;
}

.branch-name {
  display: block;
  font-weight: 600;
}

.tab-count {
  margin-left: 0.45rem;
  border-radius: 999px;
  background: var(--fg-gold);
  padding: 0.05rem 0.5rem;
  color: #1a1407;
  font-size: 0.72rem;
  font-weight: 700;
  vertical-align: middle;
}

.proof-actions {
  display: flex;
  gap: 0.4rem;
}

.receipt-link {
  color: var(--fg-gold);
  font-weight: 600;
  text-decoration: underline;
}

.branch-sub {
  display: block;
  margin-top: 0.15rem;
  color: var(--fg-card-ink-soft);
  font-size: 0.78rem;
}

.stat-sub {
  margin: 0.35rem 0 0;
  font-size: 0.78rem;
}

.field textarea {
  border-radius: 0.6rem;
  border: 1px solid;
  padding: 0.65rem 0.75rem;
  color: var(--fg-card-ink);
  font-size: 0.9rem;
  font-family: inherit;
  outline: none;
  resize: vertical;
}

.audit-filters input[type='search'] {
  min-width: 14rem;
  flex: 1 1 14rem;
  border-radius: 0.6rem;
  border: 1px solid;
  padding: 0.6rem 0.75rem;
  color: var(--fg-card-ink);
  font-size: 0.9rem;
}

.audit-date {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--fg-ink-soft);
  font-size: 0.82rem;
}

.audit-date input {
  border-radius: 0.6rem;
  border: 1px solid;
  padding: 0.5rem 0.6rem;
  color: var(--fg-card-ink);
}

.announcement-card {
  max-width: 40rem;
  padding: 1.5rem;
}

.manage-admin {
  margin-bottom: 1.1rem;
  border: 1px solid;
  border-radius: 0.75rem;
  padding: 0.85rem 1rem;
}

.manage-admin p {
  margin: 0;
}

.manage-admin-name {
  font-weight: 700;
}

.field-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 0.75rem;
}

.record-preview {
  margin: 0.25rem 0 0.75rem;
  border-radius: 0.6rem;
  background: rgba(var(--fg-accent-rgb), 0.14);
  padding: 0.6rem 0.8rem;
  font-size: 0.85rem;
}

.ends-info {
  display: block;
  margin-top: 0.3rem;
  font-size: 0.75rem;
  color: var(--fg-card-ink-soft);
}

.ends-info--soon {
  color: var(--fg-card-gold);
  font-weight: 600;
}

.ends-info--past {
  color: #e08c86;
  font-weight: 600;
}

.manage-reset {
  margin: 0.7rem 0 0.4rem;
  font-size: 0.85rem;
}

.add-btn {
  width: auto;
  padding: 0.6rem 1.25rem;
}

.table-container {
  border: 1px solid;
  border-radius: 1rem;
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 40rem;
}

th, td {
  text-align: left;
  padding: 0.85rem 1rem;
  font-size: 0.88rem;
  border-bottom: 1px solid var(--fg-card-border);
}

.nowrap {
  white-space: nowrap;
}

.capitalize {
  text-transform: capitalize;
}

.role-tag {
  color: var(--fg-card-ink-soft);
  font-size: 0.78rem;
}

.empty-row {
  text-align: center;
  color: var(--fg-card-ink-soft);
  padding: 1.5rem;
}

.badge {
  border-radius: 999px;
  padding: 0.2rem 0.7rem;
  font-size: 0.78rem;
  text-transform: capitalize;
}

.badge-active { background: rgba(95,168,46,0.2); color: #8fd16a; }
.badge-trial { background: rgba(30,136,200,0.2); color: #6cc0f0; }
.badge-pending { background: rgba(168,161,150,0.16); color: #a8a196; }
.badge-suspended { background: rgba(224,86,91,0.2); color: #f08a8e; }
.badge-expired { background: rgba(226,181,74,0.2); color: #e2b54a; }

.over-limit {
  color: #f08a8e;
  font-weight: 600;
}

.date-input {
  min-width: 9.5rem;
  border-radius: 0.6rem;
  border: 1px solid;
  padding: 0.5rem 0.6rem;
  color: var(--fg-card-ink);
  font-size: 0.85rem;
  color-scheme: dark;
}

.hint-row {
  margin: -0.5rem 0 1rem;
  font-size: 0.78rem;
  color: var(--fg-ink-soft);
}

.actions-cell {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.action-btn {
  border: none;
  border-radius: 0.5rem;
  padding: 0.4rem 0.8rem;
  font-size: 0.82rem;
  cursor: pointer;
  color: #ffffff;
}

.action-btn.manage { background: var(--fg-accent); color: var(--fg-accent-ink); font-weight: 600; }
.action-btn.suspend { background: #c4544c; }
.action-btn.activate { background: transparent; border: 1px solid var(--fg-accent); color: var(--fg-card-gold); }
.action-btn.delete { background: transparent; border: 1px solid #c4544c; color: #e08c86; }

/* light cards get a soft shadow so they stand off the marble */
.table-container,
.stat-card,
.modal-card {
  box-shadow: 0 10px 28px rgba(92, 70, 30, 0.08);
}

.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.stat-card {
  border: 1px solid;
  border-radius: 1rem;
  padding: 1.1rem 1.25rem;
}

.stat-label {
  margin: 0 0 0.4rem;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.stat-value {
  margin: 0;
  font-size: 1.6rem;
  font-weight: 700;
}

.entry-error {
  background: rgba(255, 107, 107, 0.12);
  border: 1px solid #ff6b6b;
  color: #d9474c;
  border-radius: 0.6rem;
  padding: 0.6rem 0.85rem;
  font-size: 0.85rem;
  margin-bottom: 1rem;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 50;
}

.modal-card {
  width: 100%;
  max-width: 26rem;
  max-height: 90vh;
  max-height: 90dvh;
  overflow-y: auto;
  border: 1px solid;
  border-radius: 1rem;
  padding: 1.5rem;
}

.modal-card h3 {
  margin: 0 0 1.1rem;
  font-size: 1.15rem;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 0.5rem;
}

.modal-actions .primary-btn {
  width: auto;
  padding: 0.65rem 1.25rem;
}

.account-notice {
  margin: 0 0 1rem;
  border: 1px solid rgba(var(--fg-accent-rgb), 0.45);
  border-radius: 0.6rem;
  background: rgba(var(--fg-accent-rgb), 0.12);
  padding: 0.6rem 0.85rem;
  color: var(--fg-ink);
  font-size: 0.85rem;
}

@media (max-width: 639.98px) {
  .platform-shell {
    padding: 1rem;
  }

  .modal-card {
    padding: 1.25rem;
  }

  .stat-value {
    font-size: 1.35rem;
  }

  .modal-actions {
    flex-wrap: wrap;
  }

  .modal-actions > button {
    flex: 1 1 8rem;
  }

  /* 16px inputs stop iOS Safari from zooming the page on focus */
  .field input,
  select {
    font-size: 1rem;
  }
}
</style>
