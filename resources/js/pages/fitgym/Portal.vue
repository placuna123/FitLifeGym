<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { adminAuth, gymSignup, gymsPublic, memberAuth, members, portalAuth, trainerAuth } from '@/api/fitgym';
import PasswordToggle from '@/components/PasswordToggle.vue';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';
import { usePasswordVisibility } from '@/composables/usePasswordVisibility';
import {
  appBaseUrl,
  portalUrl,
  recallRole,
  rememberRole,
  toPortalRole,
  type PortalRole,
} from '@/lib/portal';

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
  primaryText: '#FFFFFF',
};

type RoleCard = {
  key: PortalRole;
  label: string;
  title: string;
  tagline: string;
  blurb: string;
  accent: string;
};

const roleCards: RoleCard[] = [
  {
    key: 'admin',
    label: 'Admin',
    title: 'Admin Portal',
    tagline: 'Full gym management',
    blurb: 'Manage members, membership plans, payments, reports, and staff from one workspace.',
    accent: portal.purple,
  },
  {
    key: 'trainer',
    label: 'Staff',
    title: 'Staff Portal',
    tagline: 'Coach your members',
    blurb: 'Manage your assigned members, build workout plans, and track their progress.',
    accent: portal.cyan,
  },
  {
    key: 'member',
    label: 'Member',
    title: 'Member Portal',
    tagline: 'Your membership',
    blurb: 'Track your membership, attendance, progress, and payment history.',
    accent: portal.pink,
  },
];

const selectedRole = ref<PortalRole>('admin');
const activeRole = computed(() => roleCards.find((card) => card.key === selectedRole.value) ?? roleCards[0]);

// Landing hero first ("Sign In" vs "Own a gym?"); 'auth' reveals the existing
// role-picker + login/register/gym-signup card.
const viewState = ref<'landing' | 'auth'>('landing');

const heroFeatures = [
  { icon: 'members', label: 'Members', accent: portal.pink },
  { icon: 'trainers', label: 'Staff', accent: portal.cyan },
  { icon: 'attendance', label: 'Attendance', accent: portal.lime },
  { icon: 'payments', label: 'Payments', accent: portal.purple },
];

function enterSignIn() {
  authMode.value = 'login';
  viewState.value = 'auth';
}

function enterGymSignup() {
  showGymSignupForm();
  viewState.value = 'auth';
}

const checkingSession = ref(true);
const signedInUser = ref<{ name?: string; email?: string; role?: string } | null>(null);
const signedInGym = ref<{ name?: string; slug?: string } | null>(null);
const signedInRole = computed(() => toPortalRole(signedInUser.value?.role));
const signingOut = ref(false);

// 'register' only ever applies to the Member role; 'gym-signup' is a
// separate, role-independent flow for a brand-new gym owner. Switching roles
// or away from Member always falls back to the login form.
const authMode = ref<'login' | 'register' | 'gym-signup'>('login');

// Gyms a new Member can join. Registration has no "current gym" yet (the
// request is unauthenticated), so the registrant has to pick one.
const availableGyms = ref<{ id: number; name: string; slug: string }[]>([]);

const loginForm = ref({ email: '', password: '', remember: false });
const loginLoading = ref(false);
const loginError = ref('');
const notice = ref('');

const registerForm = ref({
  gym_id: '',
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  address: '',
  date_of_birth: '',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  password: '',
  terms: false,
});
const registerLoading = ref(false);
const registerError = ref('');
const otpSent = ref(false);
const otpCode = ref('');
const otpLoading = ref(false);

const gymSignupForm = ref({ gym_name: '', admin_name: '', admin_email: '', admin_password: '' });
const gymSignupLoading = ref(false);
const gymSignupError = ref('');

function resetRegisterForm() {
  registerForm.value = {
    gym_id: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    address: '',
    date_of_birth: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    password: '',
    terms: false,
  };
  otpSent.value = false;
  otpCode.value = '';
}

function resetGymSignupForm() {
  gymSignupForm.value = { gym_name: '', admin_name: '', admin_email: '', admin_password: '' };
}

function selectRole(role: PortalRole) {
  if (selectedRole.value === role) return;
  selectedRole.value = role;
  loginError.value = '';
  authMode.value = 'login';
}

function showRegisterForm() {
  authMode.value = 'register';
  registerError.value = '';
  notice.value = '';
  otpSent.value = false;
  otpCode.value = '';
}

function showGymSignupForm() {
  authMode.value = 'gym-signup';
  gymSignupError.value = '';
  notice.value = '';
}

function showLoginForm() {
  authMode.value = 'login';
  registerError.value = '';
  gymSignupError.value = '';
  otpSent.value = false;
  otpCode.value = '';
}

async function handleGymSignup() {
  if (gymSignupLoading.value) return;
  gymSignupError.value = '';

  if (gymSignupForm.value.admin_password.length < 8) {
    gymSignupError.value = 'Password must be at least 8 characters long.';
    return;
  }

  gymSignupLoading.value = true;
  try {
    const { data } = await gymSignup.store(gymSignupForm.value);

    if (data.success) {
      resetGymSignupForm();
      selectedRole.value = 'admin';
      authMode.value = 'login';
      notice.value = 'Your gym is ready! Sign in below as its Admin.';
      return;
    }

    gymSignupError.value = (data as { message?: string }).message || 'Failed to create your gym.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string };
    const d = ax.response?.data;
    if (d?.errors) {
      gymSignupError.value = Object.values(d.errors).flat().join(' ');
    } else {
      gymSignupError.value = d?.message || ax.message || 'An error occurred. Please try again.';
    }
  } finally {
    gymSignupLoading.value = false;
  }
}

function loginErrorMessage(err: unknown): string {
  const ax = err as {
    response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } };
    message?: string;
  };
  const status = ax.response?.status;
  const data = ax.response?.data;

  if (status === 419) return 'Session expired. Please refresh the page and try again.';
  if (data?.message) return data.message;
  if (data?.errors) {
    const first = Object.values(data.errors).flat()[0];
    if (first) return first;
  }
  if (status === 422) return 'Please check your email and password.';
  if (status === 403) return `This account is not allowed in the ${activeRole.value.label} portal.`;
  if (status === 401) return 'Invalid email or password.';
  if (String(ax.message ?? '').toLowerCase().includes('network')) {
    return 'Network error. Check your connection and try again.';
  }
  return 'Login failed. Please try again.';
}

async function handleLogin() {
  if (loginLoading.value) return;
  loginError.value = '';
  notice.value = '';
  loginLoading.value = true;

  const role = selectedRole.value;
  const credentials = {
    email: loginForm.value.email.trim().toLowerCase(),
    password: loginForm.value.password,
    remember: loginForm.value.remember,
  };

  try {
    const request =
      role === 'admin'
        ? adminAuth.login(credentials)
        : role === 'trainer'
          ? trainerAuth.login(credentials)
          : memberAuth.login(credentials);

    const { data } = await request;

    if (data?.success && data?.user) {
      rememberRole(role);
      // Full page load so the portal boots with a fresh session + CSRF token.
      window.location.href = portalUrl(role);
      return;
    }

    loginError.value = (data as { message?: string })?.message || 'Login failed. Please try again.';
  } catch (err: unknown) {
    loginError.value = loginErrorMessage(err);
  } finally {
    loginLoading.value = false;
  }
}

function continueToPortal() {
  const role = signedInRole.value;
  if (!role) return;
  rememberRole(role);
  window.location.href = portalUrl(role);
}

async function handleSignOut() {
  if (signingOut.value) return;
  signingOut.value = true;
  try {
    await portalAuth.logout();
  } catch {
    // Even a failed sign-out should drop us back to a clean entry page.
  } finally {
    // Reload so Laravel hands over a fresh session + CSRF token before the next login.
    window.location.replace(appBaseUrl() + '/');
  }
}

async function handleSendOtp() {
  if (otpLoading.value) return;
  registerError.value = '';

  if (!registerForm.value.terms) {
    registerError.value = 'Please agree to the Terms of Service and Privacy Policy.';
    return;
  }
  if (registerForm.value.password.length < 8) {
    registerError.value = 'Password must be at least 8 characters long.';
    return;
  }
  if (!registerForm.value.gym_id) {
    registerError.value = 'Please choose which gym you\'re joining.';
    return;
  }
  if (!registerForm.value.phone) {
    registerError.value = 'Please enter your mobile number.';
    return;
  }

  otpLoading.value = true;
  try {
    const { data } = await members.sendRegistrationOtp({
      gym_id: registerForm.value.gym_id,
      phone: registerForm.value.phone,
    });

    if (data.success) {
      otpSent.value = true;
      otpCode.value = '';
      notice.value = (data as { message?: string }).message || 'Verification code sent to your phone.';
      return;
    }

    registerError.value = (data as { message?: string }).message || 'Failed to send verification code.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string };
    const d = ax.response?.data;
    if (d?.errors) {
      registerError.value = Object.values(d.errors).flat().join(' ');
    } else {
      registerError.value = d?.message || ax.message || 'An error occurred. Please try again.';
    }
  } finally {
    otpLoading.value = false;
  }
}

async function handleRegister() {
  if (registerLoading.value) return;
  registerError.value = '';

  if (otpCode.value.length !== 6) {
    registerError.value = 'Please enter the 6-digit code sent to your phone.';
    return;
  }

  registerLoading.value = true;
  try {
    const { data } = await members.register({
      gym_id: registerForm.value.gym_id,
      first_name: registerForm.value.first_name,
      last_name: registerForm.value.last_name,
      email: registerForm.value.email,
      phone: registerForm.value.phone,
      address: registerForm.value.address || null,
      date_of_birth: registerForm.value.date_of_birth || null,
      emergency_contact_name: registerForm.value.emergency_contact_name || null,
      emergency_contact_phone: registerForm.value.emergency_contact_phone || null,
      password: registerForm.value.password,
      otp_code: otpCode.value,
    });

    if (data.success) {
      loginForm.value.email = registerForm.value.email;
      resetRegisterForm();
      authMode.value = 'login';
      notice.value = 'Account created! You can sign in now — an admin or staff member will approve your account before you can check in at the gym.';
      return;
    }

    registerError.value = (data as { message?: string }).message || 'Failed to create account.';
  } catch (err: unknown) {
    const ax = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string };
    const d = ax.response?.data;
    if (d?.errors) {
      registerError.value = Object.values(d.errors).flat().join(' ');
    } else {
      registerError.value = d?.message || ax.message || 'An error occurred. Please try again.';
    }
  } finally {
    registerLoading.value = false;
  }
}

onMounted(async () => {
  const params = new URLSearchParams(window.location.search);

  const requestedRole = toPortalRole(params.get('role'));
  if (requestedRole) {
    selectedRole.value = requestedRole;
    // A deep link (e.g. bounced back here after a session ended) means the
    // visitor already knows what they're doing — skip the landing hero.
    viewState.value = 'auth';
  } else {
    const remembered = recallRole();
    if (remembered) selectedRole.value = remembered;
  }

  if (params.get('reason') === 'session') {
    notice.value = 'Your session ended. Please sign in again.';
    viewState.value = 'auth';
  } else if (params.get('reason') === 'password-reset') {
    notice.value = 'Your password has been reset. Sign in with your new password.';
    viewState.value = 'auth';
  }

  try {
    const { data } = await portalAuth.session();
    if (data?.authenticated && data?.user) {
      signedInUser.value = data.user as { name?: string; email?: string; role?: string };
      signedInGym.value = (data.gym as { name?: string; slug?: string } | null) ?? null;
      const role = toPortalRole(data.user.role);
      if (role) selectedRole.value = role;
      // Already signed in — show that state directly, not the landing hero.
      viewState.value = 'auth';
    } else if (data?.message) {
      notice.value = String(data.message);
    }
  } catch {
    signedInUser.value = null;
    signedInGym.value = null;
  } finally {
    checkingSession.value = false;
  }

  try {
    const { data } = await gymsPublic.list();
    if (data.success) {
      availableGyms.value = data.gyms as { id: number; name: string; slug: string }[];
    }
  } catch {
    availableGyms.value = [];
  }
});
</script>

<template>
  <Head title="FitGym - Sign In" />

  <div class="fitgym-entry" :style="{ backgroundColor: portal.dark }">
    <div class="entry-glow entry-glow--one" :style="{ backgroundColor: portal.purple }" />
    <div class="entry-glow entry-glow--two" :style="{ backgroundColor: portal.cyan }" />

    <!-- Faint gym-equipment line art, purely decorative — keeps the page feeling like a gym without a photo asset. -->
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
      <!-- Brand -->
      <header class="entry-brand">
        <div class="entry-logo" :style="{ backgroundColor: portal.purple }">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6.5 6.5 17.5 17.5" />
            <path d="M4 8v8" />
            <path d="M20 8v8" />
            <path d="M7 5v14" />
            <path d="M17 5v14" />
          </svg>
        </div>
        <div>
          <p class="entry-eyebrow" :style="{ color: portal.textDim }">FitGym Platform</p>
          <h1 class="entry-wordmark">{{ viewState === 'landing' ? 'Every gym. One platform.' : 'Welcome back' }}</h1>
        </div>
      </header>

      <!-- Checking for an existing session -->
      <section v-if="checkingSession" class="entry-card entry-card--center" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
        <div class="entry-spinner" :style="{ borderColor: portal.border, borderTopColor: portal.purple }" />
        <p class="entry-muted" :style="{ color: portal.textDim }">Checking your session…</p>
      </section>

      <!-- Landing hero: the first thing anyone sees — pick a lane before the role/login form -->
      <section v-else-if="viewState === 'landing'" class="entry-hero">
        <div class="hero-badges">
          <span class="hero-badge" :style="{ borderColor: portal.border, color: portal.textDim }">
            <span class="hero-dot" :style="{ backgroundColor: portal.lime }" />
            Multi-gym platform
          </span>
        </div>
        <h2 class="hero-title">Run your gym.<br />Grow every branch.</h2>
        <p class="hero-subtitle" :style="{ color: portal.textDim }">
          Members, staff, attendance, and payments — one system for every gym on the platform, each fully your own.
        </p>
        <div class="hero-actions">
          <button type="button" class="entry-primary hero-primary" :style="{ backgroundColor: portal.purple }" @click="enterSignIn">
            Sign In
          </button>
          <button type="button" class="entry-ghost hero-ghost" :style="{ borderColor: portal.lime, color: portal.lime }" @click="enterGymSignup">
            Own a Gym? Start Your Free Trial
          </button>
        </div>
        <p class="hero-footnote" :style="{ color: portal.textDim }">
          Already a member? <a href="#" :style="{ color: portal.purpleLight }" @click.prevent="enterSignIn">Sign in here</a>.
        </p>

        <ul class="hero-features">
          <li v-for="feature in heroFeatures" :key="feature.label" class="hero-feature">
            <span class="hero-feature-icon" :style="{ color: feature.accent, backgroundColor: `${feature.accent}1F` }">
              <svg v-if="feature.icon === 'members'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
              </svg>
              <svg v-else-if="feature.icon === 'trainers'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
              </svg>
              <svg v-else-if="feature.icon === 'attendance'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7v5l3 3" />
              </svg>
              <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 2v20" />
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
              </svg>
            </span>
            <span>{{ feature.label }}</span>
          </li>
        </ul>
      </section>

      <!-- Already signed in -->
      <section
        v-else-if="signedInUser && signedInRole"
        class="entry-card"
        :style="{ backgroundColor: portal.dark3, borderColor: portal.border }"
      >
        <p class="entry-kicker" :style="{ color: portal.textDim }">
          {{ signedInGym?.name ? `Welcome back to ${signedInGym.name}` : "You're already signed in" }}
        </p>
        <h2 class="entry-title">{{ signedInUser.name || 'Welcome back' }}</h2>
        <p class="entry-subtitle" :style="{ color: portal.textDim }">{{ signedInUser.email }}</p>

        <div class="entry-active-role" :style="{ borderColor: activeRole.accent, color: activeRole.accent }">
          {{ activeRole.title }}
        </div>

        <button
          type="button"
          class="entry-primary"
          :style="{ backgroundColor: activeRole.accent }"
          @click="continueToPortal"
        >
          Continue to {{ activeRole.title }}
        </button>

        <button
          type="button"
          class="entry-ghost"
          :style="{ borderColor: portal.border, color: portal.textDim }"
          :disabled="signingOut"
          @click="handleSignOut"
        >
          {{ signingOut ? 'Signing out…' : 'Sign in as someone else' }}
        </button>
      </section>

      <!-- Role picker + single sign-in form -->
      <section v-else class="entry-card" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
        <button type="button" class="entry-back" :style="{ color: portal.textDim }" @click="viewState = 'landing'">
          ← Back
        </button>
        <template v-if="authMode !== 'gym-signup'">
          <p class="entry-kicker" :style="{ color: portal.textDim }">Step 1 — Who are you?</p>

          <div class="entry-roles" role="radiogroup" aria-label="Choose your role">
            <button
              v-for="card in roleCards"
              :key="card.key"
              type="button"
              role="radio"
              :aria-checked="selectedRole === card.key"
              class="entry-role"
              :class="{ 'entry-role--active': selectedRole === card.key }"
              :style="{
                backgroundColor: portal.dark2,
                borderColor: selectedRole === card.key ? card.accent : portal.border,
                boxShadow: selectedRole === card.key ? `0 0 0 1px ${card.accent}, 0 10px 30px -18px ${card.accent}` : 'none',
              }"
              @click="selectRole(card.key)"
            >
              <span class="entry-role-icon" :style="{ color: card.accent, backgroundColor: `${card.accent}1F` }">
                <svg v-if="card.key === 'admin'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                  <path d="M9 12l2 2 4-4" />
                </svg>
                <svg v-else-if="card.key === 'trainer'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                  <circle cx="9" cy="7" r="4" />
                  <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                  <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                  <circle cx="12" cy="7" r="4" />
                </svg>
              </span>
              <span class="entry-role-label">{{ card.label }}</span>
              <span class="entry-role-tagline" :style="{ color: portal.textDim }">{{ card.tagline }}</span>
            </button>
          </div>

          <p class="entry-role-blurb" :style="{ color: portal.textDim }">{{ activeRole.blurb }}</p>

          <div class="entry-divider" :style="{ backgroundColor: portal.border }" />
        </template>

        <!-- Step 2a: sign in -->
        <template v-if="authMode === 'login'">
          <p class="entry-kicker" :style="{ color: portal.textDim }">
            Step 2 — Sign in to the <span :style="{ color: activeRole.accent }">{{ activeRole.title }}</span>
          </p>

          <div v-if="notice" class="entry-notice" :style="{ borderColor: portal.border, color: portal.textDim }">
            {{ notice }}
          </div>

          <div v-if="loginError" class="entry-error">{{ loginError }}</div>

          <form class="entry-form" @submit.prevent="handleLogin">
            <div class="entry-field">
              <label for="entry-email" :style="{ color: portal.textDim }">Email Address</label>
              <input
                id="entry-email"
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
                <label for="entry-password" :style="{ color: portal.textDim }">Password</label>
                <a href="/forgot-password" :style="{ color: portal.purpleLight }">Forgot Password?</a>
              </div>
              <div class="fg-password-field">
                <input
                  id="entry-password"
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
              <input v-model="loginForm.remember" type="checkbox" :style="{ borderColor: portal.border }" />
              Remember me
            </label>

            <button
              type="submit"
              class="entry-primary"
              :style="{ backgroundColor: activeRole.accent }"
              :disabled="loginLoading"
            >
              {{ loginLoading ? 'Signing In…' : `Sign In as ${activeRole.label}` }}
            </button>
          </form>

          <div v-if="selectedRole === 'member'" class="entry-register">
            <p :style="{ color: portal.textDim }">Don't have an account?</p>
            <button type="button" class="entry-ghost" :style="{ borderColor: portal.pink, color: portal.pink }" @click="showRegisterForm">
              Create Member Account
            </button>
          </div>
        </template>

        <!-- Step 2b: create a Member account, right here -->
        <template v-else-if="authMode === 'register'">
          <p class="entry-kicker" :style="{ color: portal.textDim }">
            Step 2 — Join as a <span :style="{ color: activeRole.accent }">Member</span>
          </p>

          <div v-if="registerError" class="entry-error">{{ registerError }}</div>

          <form class="entry-form" @submit.prevent="otpSent ? handleRegister() : handleSendOtp()">
            <template v-if="!otpSent">
              <div class="entry-field">
                <label :style="{ color: portal.textDim }">Which gym are you joining? *</label>
                <select v-model="registerForm.gym_id" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }">
                  <option value="" disabled>Select a gym</option>
                  <option v-for="gym in availableGyms" :key="gym.id" :value="gym.id">{{ gym.name }}</option>
                </select>
              </div>

              <div class="entry-field-row">
                <div class="entry-field">
                  <label :style="{ color: portal.textDim }">First Name *</label>
                  <input v-model="registerForm.first_name" type="text" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                </div>
                <div class="entry-field">
                  <label :style="{ color: portal.textDim }">Last Name *</label>
                  <input v-model="registerForm.last_name" type="text" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                </div>
              </div>

              <div class="entry-field">
                <label :style="{ color: portal.textDim }">Email Address *</label>
                <input v-model="registerForm.email" type="email" required placeholder="your.email@example.com" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              </div>

              <div class="entry-field">
                <label :style="{ color: portal.textDim }">Phone Number *</label>
                <input v-model="registerForm.phone" type="tel" required placeholder="09XXXXXXXXX" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                <p class="entry-hint" :style="{ color: portal.textDim }">A real number — we'll text you a verification code before your account is created.</p>
              </div>

              <div class="entry-field">
                <label :style="{ color: portal.textDim }">Address</label>
                <input v-model="registerForm.address" type="text" placeholder="House/street, barangay, city" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              </div>

              <div class="entry-field">
                <label :style="{ color: portal.textDim }">Date of Birth</label>
                <input v-model="registerForm.date_of_birth" type="date" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
              </div>

              <div class="entry-field-row">
                <div class="entry-field">
                  <label :style="{ color: portal.textDim }">Emergency Contact</label>
                  <input v-model="registerForm.emergency_contact_name" type="text" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                </div>
                <div class="entry-field">
                  <label :style="{ color: portal.textDim }">Emergency Phone</label>
                  <input v-model="registerForm.emergency_contact_phone" type="tel" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                </div>
              </div>

              <div class="entry-field">
                <label :style="{ color: portal.textDim }">Password *</label>
                <div class="fg-password-field">
                  <input v-model="registerForm.password" :type="pw.type('register')" required autocomplete="new-password" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                  <PasswordToggle :shown="pw.isShown('register')" @toggle="pw.toggle('register')" />
                </div>
                <p class="entry-hint" :style="{ color: portal.textDim }">Minimum 8 characters required</p>
              </div>

              <label class="entry-remember entry-terms" :style="{ color: portal.textDim }">
                <input v-model="registerForm.terms" type="checkbox" required :style="{ borderColor: portal.border }" />
                <span>
                  I agree to the <a href="#" :style="{ color: portal.purpleLight }" @click.prevent>Terms of Service</a>
                  and <a href="#" :style="{ color: portal.purpleLight }" @click.prevent>Privacy Policy</a>
                </span>
              </label>

              <button
                type="submit"
                class="entry-primary"
                :style="{ backgroundColor: activeRole.accent }"
                :disabled="otpLoading"
              >
                {{ otpLoading ? 'Sending Code…' : 'Send Verification Code' }}
              </button>
            </template>

            <template v-else>
              <p :style="{ color: portal.textDim }">
                We sent a 6-digit code to <strong :style="{ color: activeRole.accent }">{{ registerForm.phone }}</strong>. Enter it below to finish creating your account.
              </p>

              <div class="entry-field">
                <label :style="{ color: portal.textDim }">Verification Code *</label>
                <InputOTP v-model="otpCode" :maxlength="6" :disabled="registerLoading" autofocus>
                  <InputOTPGroup>
                    <InputOTPSlot v-for="index in 6" :key="index" :index="index - 1" />
                  </InputOTPGroup>
                </InputOTP>
              </div>

              <button
                type="submit"
                class="entry-primary"
                :style="{ backgroundColor: activeRole.accent }"
                :disabled="registerLoading || otpCode.length !== 6"
              >
                {{ registerLoading ? 'Creating Account…' : 'Verify & Create Account' }}
              </button>

              <div class="entry-register">
                <button type="button" class="entry-ghost" :style="{ borderColor: portal.border, color: portal.textDim }" :disabled="otpLoading" @click="handleSendOtp">
                  {{ otpLoading ? 'Resending…' : 'Resend code' }}
                </button>
                <button type="button" class="entry-ghost" :style="{ borderColor: portal.border, color: portal.textDim }" @click="otpSent = false">
                  ← Edit my information
                </button>
              </div>
            </template>
          </form>

          <div class="entry-register">
            <button type="button" class="entry-ghost" :style="{ borderColor: portal.border, color: portal.textDim }" @click="showLoginForm">
              ← Already have an account? Sign In
            </button>
          </div>
        </template>

        <!-- Step 2c: start a brand-new gym (its own Admin account, own trial) -->
        <template v-else-if="authMode === 'gym-signup'">
          <p class="entry-kicker" :style="{ color: portal.textDim }">
            Start your gym's <span :style="{ color: portal.lime }">free trial</span>
          </p>

          <div v-if="gymSignupError" class="entry-error">{{ gymSignupError }}</div>

          <form class="entry-form" @submit.prevent="handleGymSignup">
            <div class="entry-field">
              <label :style="{ color: portal.textDim }">Gym Name *</label>
              <input v-model="gymSignupForm.gym_name" type="text" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
            </div>

            <div class="entry-field">
              <label :style="{ color: portal.textDim }">Your Name *</label>
              <input v-model="gymSignupForm.admin_name" type="text" required :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
            </div>

            <div class="entry-field">
              <label :style="{ color: portal.textDim }">Your Email *</label>
              <input v-model="gymSignupForm.admin_email" type="email" required placeholder="you@yourgym.com" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
            </div>

            <div class="entry-field">
              <label :style="{ color: portal.textDim }">Password *</label>
              <div class="fg-password-field">
                <input v-model="gymSignupForm.admin_password" :type="pw.type('gym')" required autocomplete="new-password" :style="{ backgroundColor: portal.dark2, borderColor: portal.border }" />
                <PasswordToggle :shown="pw.isShown('gym')" @toggle="pw.toggle('gym')" />
              </div>
              <p class="entry-hint" :style="{ color: portal.textDim }">Minimum 8 characters required</p>
            </div>

            <button
              type="submit"
              class="entry-primary"
              :style="{ backgroundColor: portal.lime, color: portal.dark }"
              :disabled="gymSignupLoading"
            >
              {{ gymSignupLoading ? 'Creating Your Gym…' : 'Create My Gym' }}
            </button>
          </form>

          <div class="entry-register">
            <button type="button" class="entry-ghost" :style="{ borderColor: portal.border, color: portal.textDim }" @click="showLoginForm">
              ← Already have an account? Sign In
            </button>
          </div>
        </template>
      </section>

      <p v-if="viewState === 'auth'" class="entry-footnote" :style="{ color: portal.textDim }">
        One sign-in page for Admin, Staff, and Member accounts.
      </p>
    </div>
  </div>
</template>

<style scoped>
.fitgym-entry {
  position: relative;
  display: flex;
  min-height: 100vh;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  padding: 2rem 1rem;
  color: #ffffff;
  font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
}

.entry-glow {
  position: absolute;
  width: 26rem;
  height: 26rem;
  border-radius: 9999px;
  filter: blur(120px);
  opacity: 0.18;
  pointer-events: none;
}

.entry-glow--one {
  top: -8rem;
  left: -6rem;
}

.entry-glow--two {
  bottom: -10rem;
  right: -6rem;
  opacity: 0.12;
}

.entry-deco {
  position: absolute;
  color: #ffffff;
  opacity: 0.05;
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
  gap: 1.5rem;
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
}

.entry-logo svg {
  width: 1.6rem;
  height: 1.6rem;
  color: #ffffff;
}

.entry-eyebrow {
  margin: 0;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.entry-wordmark {
  margin: 0.15rem 0 0;
  font-size: 1.6rem;
  font-weight: 700;
  line-height: 1.2;
}

.entry-hero {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 1.1rem;
  padding: 1rem 0 2rem;
}

.hero-badges {
  display: flex;
}

.hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  border: 1px solid;
  border-radius: 999px;
  padding: 0.35rem 0.9rem;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.hero-dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  flex-shrink: 0;
  animation: hero-dot-pulse 1.8s ease-in-out infinite;
}

@keyframes hero-dot-pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.5; transform: scale(0.85); }
}

.hero-features {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem;
  list-style: none;
  margin: 0.75rem 0 0;
  padding: 0;
}

.hero-feature {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  font-size: 0.88rem;
  font-weight: 600;
  color: #ffffff;
}

.hero-feature-icon {
  display: grid;
  place-items: center;
  width: 2.1rem;
  height: 2.1rem;
  border-radius: 0.6rem;
  flex-shrink: 0;
}

.hero-feature-icon svg {
  width: 1.05rem;
  height: 1.05rem;
}

.hero-title {
  margin: 0;
  font-size: clamp(2rem, 5vw, 3rem);
  font-weight: 800;
  line-height: 1.1;
  letter-spacing: -0.01em;
}

.hero-subtitle {
  margin: 0;
  max-width: 32rem;
  font-size: 1.02rem;
  line-height: 1.6;
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.85rem;
  margin-top: 0.4rem;
  width: 100%;
}

.hero-primary,
.hero-ghost {
  width: auto;
  padding-left: 1.75rem;
  padding-right: 1.75rem;
}

.hero-footnote {
  margin: 0.2rem 0 0;
  font-size: 0.88rem;
}

.hero-footnote a {
  text-decoration: none;
  font-weight: 600;
}

.hero-footnote a:hover {
  text-decoration: underline;
}

.entry-back {
  align-self: flex-start;
  background: none;
  border: none;
  padding: 0;
  margin-bottom: 0.25rem;
  font-size: 0.85rem;
  cursor: pointer;
}

.entry-back:hover {
  text-decoration: underline;
}

.entry-card {
  border-radius: 1.25rem;
  border-width: 1px;
  border-style: solid;
  padding: 1.75rem;
}

.entry-card--center {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.9rem;
  padding: 3rem 1.75rem;
}

.entry-spinner {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 9999px;
  border-width: 3px;
  border-style: solid;
  animation: entry-spin 0.9s linear infinite;
}

@keyframes entry-spin {
  to {
    transform: rotate(360deg);
  }
}

.entry-muted {
  margin: 0;
  font-size: 0.875rem;
}

.entry-kicker {
  margin: 0 0 0.85rem;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.entry-title {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 700;
}

.entry-subtitle {
  margin: 0.3rem 0 0;
  font-size: 0.875rem;
}

.entry-active-role {
  display: inline-block;
  margin: 1.25rem 0;
  border-radius: 9999px;
  border-width: 1px;
  border-style: solid;
  padding: 0.35rem 0.9rem;
  font-size: 0.78rem;
  font-weight: 600;
}

.entry-roles {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.6rem;
}

.entry-role {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  border-radius: 0.9rem;
  border-width: 1px;
  border-style: solid;
  padding: 1rem 0.6rem;
  cursor: pointer;
  text-align: center;
  color: #ffffff;
  transition:
    border-color 0.18s ease,
    box-shadow 0.18s ease,
    transform 0.18s ease;
}

.entry-role:hover {
  transform: translateY(-2px);
}

.entry-role-icon {
  display: grid;
  place-items: center;
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 0.75rem;
}

.entry-role-icon svg {
  width: 1.25rem;
  height: 1.25rem;
}

.entry-role-label {
  font-size: 0.95rem;
  font-weight: 600;
}

.entry-role-tagline {
  font-size: 0.68rem;
  line-height: 1.3;
}

.entry-role-blurb {
  margin: 0.9rem 0 0;
  font-size: 0.8rem;
  line-height: 1.5;
  min-height: 2.4rem;
}

.entry-divider {
  height: 1px;
  margin: 1.4rem 0;
}

.entry-notice {
  margin-bottom: 0.85rem;
  border-radius: 0.7rem;
  border-width: 1px;
  border-style: solid;
  padding: 0.7rem 0.85rem;
  font-size: 0.82rem;
}

.entry-error {
  margin-bottom: 0.85rem;
  border-radius: 0.7rem;
  border: 1px solid #ef4444;
  background-color: rgba(127, 29, 29, 0.3);
  padding: 0.7rem 0.85rem;
  font-size: 0.85rem;
  color: #fca5a5;
}

.entry-form {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
}

.entry-field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.entry-field-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1.1rem;
}

.entry-hint {
  margin: 0;
  font-size: 0.72rem;
}

.entry-terms {
  align-items: flex-start;
}

.entry-terms input {
  margin-top: 0.2rem;
  flex-shrink: 0;
}

.entry-terms a {
  text-decoration: none;
}

.entry-terms a:hover {
  text-decoration: underline;
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
  font-size: 0.8rem;
  text-decoration: none;
}

.entry-field-head a:hover {
  text-decoration: underline;
}

.entry-field input,
.entry-field select {
  width: 100%;
  border-radius: 0.65rem;
  border-width: 1px;
  border-style: solid;
  padding: 0.75rem;
  font-size: 0.92rem;
  color: #ffffff;
  outline: none;
}

.entry-field select {
  appearance: none;
}

.entry-field input::placeholder {
  color: #6b6b8a;
}

.entry-field input:focus,
.entry-field select:focus {
  border-color: #8a2be2;
}

.entry-field input:-webkit-autofill,
.entry-field input:-webkit-autofill:hover,
.entry-field input:-webkit-autofill:focus,
.entry-field input:-webkit-autofill:active {
  -webkit-text-fill-color: #ffffff;
  -webkit-box-shadow: 0 0 0px 1000px #2a2a4a inset;
  box-shadow: 0 0 0px 1000px #2a2a4a inset;
  caret-color: #ffffff;
  transition: background-color 5000s ease-in-out 0s;
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
  color: #ffffff;
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

.entry-ghost {
  width: 100%;
  margin-top: 0.7rem;
  border-radius: 0.7rem;
  border-width: 1px;
  border-style: solid;
  background: transparent;
  padding: 0.75rem 1rem;
  font-size: 0.92rem;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.18s ease;
}

.entry-ghost:hover:not(:disabled) {
  opacity: 0.8;
}

.entry-ghost:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.entry-register {
  margin-top: 1.4rem;
  text-align: center;
}

.entry-register p {
  margin: 0;
  font-size: 0.85rem;
}

.entry-footnote {
  margin: 0;
  text-align: center;
  font-size: 0.78rem;
}

/* 16px inputs stop iOS Safari from zooming the page on focus */
@media (max-width: 767.98px) {
  .entry-field input,
  .entry-field select {
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

  .hero-primary,
  .hero-ghost {
    flex: 1 1 100%;
    padding-left: 1rem;
    padding-right: 1rem;
  }

  .hero-features {
    gap: 0.9rem 1.25rem;
  }

  .entry-roles {
    grid-template-columns: 1fr;
  }

  .entry-field-row {
    grid-template-columns: 1fr;
    gap: 1.1rem;
  }

  .entry-role {
    flex-direction: row;
    justify-content: flex-start;
    gap: 0.75rem;
    text-align: left;
    padding: 0.85rem;
  }

  .entry-role-tagline {
    display: none;
  }

  .entry-role-blurb {
    min-height: 0;
  }
}
</style>
