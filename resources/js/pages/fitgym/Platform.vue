<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { platformAnalytics, platformAuditLogs, platformAuth, platformGyms } from '@/api/fitgym';
import PasswordToggle from '@/components/PasswordToggle.vue';
import { usePasswordVisibility } from '@/composables/usePasswordVisibility';

const pw = usePasswordVisibility();

// Same palette as the Admin / Trainer / Member portals — nothing new introduced.
const portal = {
  dark: '#1A1A2E',
  dark2: '#2A2A4A',
  dark3: '#2C2C4A',
  sidebar: '#0F0F1A',
  border: '#3A3A5A',
  purple: '#8A2BE2',
  purpleLight: '#6A5ACD',
  pink: '#FF6B6B',
  cyan: '#00BFFF',
  lime: '#8DFF00',
  textDim: '#A0A0A0',
};

const loading = ref(true);
const isLoggedIn = ref(false);
const user = ref<{ id: number; name: string; email: string } | null>(null);

const loginForm = ref({ email: '', password: '' });
const loginLoading = ref(false);
const loginError = ref('');

type Tab = 'branches' | 'analytics' | 'audit';
const currentTab = ref<Tab>('branches');
const tabs: { id: Tab; label: string }[] = [
  { id: 'branches', label: 'Branches' },
  { id: 'analytics', label: 'Analytics' },
  { id: 'audit', label: 'Audit Logs' },
];

const gyms = ref<Record<string, unknown>[]>([]);
const gymsLoading = ref(false);
const actionError = ref('');

const analytics = ref<{ totals: Record<string, number>; branches: Record<string, unknown>[] } | null>(null);
const analyticsLoading = ref(false);

const auditLogs = ref<Record<string, unknown>[]>([]);
const auditLoading = ref(false);
const auditGymFilter = ref('');

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
      gyms.value = data.gyms as Record<string, unknown>[];
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
      analytics.value = { totals: data.totals, branches: data.branches };
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
    const { data } = await platformAuditLogs.list(auditGymFilter.value ? { gym_id: auditGymFilter.value } : undefined);
    if (data.success) {
      auditLogs.value = data.logs as Record<string, unknown>[];
    }
  } catch {
    auditLogs.value = [];
  } finally {
    auditLoading.value = false;
  }
}

async function switchTab(tab: Tab) {
  currentTab.value = tab;
  if (tab === 'analytics' && !analytics.value) await loadAnalytics();
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
  loginForm.value = { email: '', password: '' };
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

  <div class="platform-shell fg-marble-bg">
    <div v-if="loading" class="platform-loading">Loading…</div>

    <div v-else-if="!isLoggedIn" class="platform-login">
      <div class="login-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
        <p class="eyebrow" :style="{ color: portal.textDim }">FitGym Platform</p>
        <h1>Super Admin</h1>

        <div v-if="loginError" class="entry-error">{{ loginError }}</div>

        <form @submit.prevent="handleLogin">
          <div class="field">
            <label :style="{ color: portal.textDim }">Email</label>
            <input v-model="loginForm.email" type="email" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
          </div>
          <div class="field">
            <label :style="{ color: portal.textDim }">Password</label>
            <div class="fg-password-field">
              <input v-model="loginForm.password" :type="pw.type('login')" required autocomplete="current-password" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              <PasswordToggle :shown="pw.isShown('login')" @toggle="pw.toggle('login')" />
            </div>
          </div>
          <button type="submit" class="primary-btn" :style="{ backgroundColor: portal.purple }" :disabled="loginLoading">
            {{ loginLoading ? 'Signing In…' : 'Sign In' }}
          </button>
        </form>
      </div>
    </div>

    <div v-else class="platform-dashboard">
      <header class="dashboard-header">
        <div>
          <p class="eyebrow">FitGym Platform</p>
          <h1>Branches &amp; Subscriptions</h1>
        </div>
        <div class="header-actions">
          <span>{{ user?.name }}</span>
          <button type="button" class="ghost-btn" @click="openPasswordModal">
            Change Password
          </button>
          <button type="button" class="ghost-btn" @click="handleLogout">
            Sign Out
          </button>
        </div>
      </header>
      <p v-if="accountNotice" class="account-notice" role="status">{{ accountNotice }}</p>

      <nav class="tab-nav">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          class="tab-btn"
          :class="{ 'tab-btn--active': currentTab === tab.id }"
          :style="currentTab === tab.id ? { color: 'var(--fg-ink)', borderColor: 'var(--fg-gold)' } : { color: 'var(--fg-ink-soft)', borderColor: 'transparent' }"
          @click="switchTab(tab.id)"
        >
          {{ tab.label }}
        </button>
      </nav>

      <div v-if="actionError" class="entry-error">{{ actionError }}</div>

      <!-- Branches tab -->
      <section v-if="currentTab === 'branches'">
        <div class="section-toolbar">
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
                <td>{{ gym.name }}</td>
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
                </td>
                <td>
                  <span :class="['badge', statusBadgeClass(gym.effective_status ?? gym.subscription_status)]">{{ gym.effective_status ?? gym.subscription_status }}</span>
                </td>
                <td class="actions-cell">
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
      </section>

      <!-- Analytics tab -->
      <section v-else-if="currentTab === 'analytics'">
        <div v-if="analyticsLoading" class="empty-row">Loading analytics…</div>
        <template v-else-if="analytics">
          <div class="stat-grid">
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Total Branches</p>
              <p class="stat-value">{{ analytics.totals.gyms }}</p>
            </div>
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Total Members</p>
              <p class="stat-value">{{ analytics.totals.members }}</p>
            </div>
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Total Staff</p>
              <p class="stat-value">{{ analytics.totals.trainers }}</p>
            </div>
            <div class="stat-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
              <p class="stat-label" :style="{ color: portal.textDim }">Platform Revenue</p>
              <p class="stat-value">{{ formatCurrency(analytics.totals.revenue) }}</p>
            </div>
          </div>

          <div class="table-container" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
            <table>
              <thead>
                <tr>
                  <th>Branch</th>
                  <th>Members</th>
                  <th>Staff</th>
                  <th>Revenue</th>
                  <th>Plan</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="branch in analytics.branches" :key="String(branch.id)">
                  <td>{{ branch.name }}</td>
                  <td>{{ branch.members }}</td>
                  <td>{{ branch.trainers }}</td>
                  <td>{{ formatCurrency(branch.revenue) }}</td>
                  <td class="capitalize">{{ branch.subscription_plan }}</td>
                  <td><span :class="['badge', statusBadgeClass(branch.subscription_status)]">{{ branch.subscription_status }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>

      <!-- Audit Logs tab -->
      <section v-else-if="currentTab === 'audit'">
        <div class="section-toolbar">
          <select
            v-model="auditGymFilter"
            :style="{ backgroundColor: portal.dark2, borderColor: portal.border }"
            @change="loadAuditLogs"
          >
            <option value="">All branches</option>
            <option v-for="gym in gyms" :key="String(gym.id)" :value="String(gym.id)">{{ gym.name }}</option>
          </select>
        </div>

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
  color: #ffffff;
  font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
  padding: 2rem 1.5rem;
}

.platform-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  color: #a0a0a0;
}

.platform-login {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
}

.login-card {
  width: 100%;
  max-width: 22rem;
  border-radius: 1rem;
  border: 1px solid;
  padding: 2rem;
}

.eyebrow {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin: 0 0 0.4rem;
}

h1 {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
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
  color: #ffffff;
  font-size: 0.9rem;
  outline: none;
}

.primary-btn {
  border: none;
  border-radius: 0.6rem;
  padding: 0.75rem;
  color: #ffffff;
  font-weight: 600;
  cursor: pointer;
}

.login-card .primary-btn {
  width: 100%;
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

.platform-dashboard {
  max-width: 78rem;
  margin: 0 auto;
}

.dashboard-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid rgba(160, 122, 31, 0.3);
  margin-bottom: 1rem;
}

/* The header and tabs sit straight on the marble background, so they use dark ink */
.dashboard-header h1 {
  color: var(--fg-ink);
}

.dashboard-header .eyebrow {
  color: var(--fg-gold);
  font-weight: 600;
}

.dashboard-header .header-actions span {
  color: var(--fg-ink-soft);
}

.dashboard-header .ghost-btn {
  border-color: rgba(107, 93, 69, 0.45);
  color: var(--fg-ink);
}

.dashboard-header .ghost-btn:hover {
  background: rgba(255, 255, 255, 0.45);
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.tab-nav {
  display: flex;
  gap: 0.5rem;
  border-bottom: 1px solid rgba(160, 122, 31, 0.3);
  margin-bottom: 1.5rem;
  overflow-x: auto;
}

.tab-btn {
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  padding: 0.75rem 0.25rem;
  margin-right: 1.25rem;
  font-size: 0.92rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}

.section-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 1rem;
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
  border-bottom: 1px solid #3a3a5a;
}

.nowrap {
  white-space: nowrap;
}

.capitalize {
  text-transform: capitalize;
}

.role-tag {
  color: #a0a0a0;
  font-size: 0.78rem;
}

.empty-row {
  text-align: center;
  color: #a0a0a0;
  padding: 1.5rem;
}

.badge {
  border-radius: 999px;
  padding: 0.2rem 0.7rem;
  font-size: 0.78rem;
  text-transform: capitalize;
}

.badge-active { background: rgba(141,255,0,0.15); color: #8dff00; }
.badge-trial { background: rgba(0,191,255,0.15); color: #00bfff; }
.badge-pending { background: rgba(160,160,160,0.15); color: #a0a0a0; }
.badge-suspended { background: rgba(255,107,107,0.15); color: #ff6b6b; }
.badge-expired { background: rgba(255,215,0,0.15); color: #ffd700; }

.over-limit {
  color: #ff6b6b;
  font-weight: 600;
}

.date-input {
  min-width: 9.5rem;
  border-radius: 0.6rem;
  border: 1px solid;
  padding: 0.5rem 0.6rem;
  color: #ffffff;
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

.action-btn.suspend { background: #ff6b6b; }
.action-btn.activate { background: #8dff00; color: #1a1a2e; }
.action-btn.delete { background: transparent; border: 1px solid #ff6b6b; color: #ff6b6b; }

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
  color: #ff6b6b;
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

.header-actions {
  flex-wrap: wrap;
  min-width: 0;
}

.header-actions span {
  overflow-wrap: anywhere;
}

.account-notice {
  margin: 0 0 1rem;
  border: 1px solid rgba(141, 255, 0, 0.35);
  border-radius: 0.6rem;
  background: rgba(141, 255, 0, 0.1);
  padding: 0.6rem 0.85rem;
  color: #3f6b00;
  font-size: 0.85rem;
}

@media (max-width: 639.98px) {
  .platform-shell {
    padding: 1rem;
  }

  .login-card,
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
