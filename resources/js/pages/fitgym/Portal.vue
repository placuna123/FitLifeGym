<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { adminAuth, gymSignup, gymsPublic, memberAuth, members, portalAuth, trainerAuth } from '@/api/fitgym';
import FgThemeToggle from '@/components/FgThemeToggle.vue';
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
type PublicGym = {
  id: number;
  name: string;
  slug: string;
  description: string;
  cover_photo_url: string | null;
  address: string;
  phone: string;
  email: string;
  operating_hours: Record<string, string>;
  plans: { name: string; price: number; period: string | null }[];
  staff_count: number;
  classes: string[];
};
const availableGyms = ref<PublicGym[]>([]);

// The gym whose details are open in the pop-up while a new Member is choosing.
const previewGym = ref<PublicGym | null>(null);

// Member sign-in on a laptop/desktop: the form and "Create Member Account + our gyms"
// sit side by side so nothing needs scrolling (see .entry-card--split).
const memberSplit = computed(() => viewState.value === 'auth' && authMode.value === 'login' && selectedRole.value === 'member');
const chosenGym = computed(() => availableGyms.value.find((g) => g.id === Number(registerForm.value.gym_id)) ?? null);
const WEEK_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function gymInitials(name: string) {
  return name.split(/s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}

function gymHours(gym: PublicGym) {
  return WEEK_DAYS.filter((day) => gym.operating_hours?.[day]).map((day) => ({ day: day.slice(0, 3), hours: gym.operating_hours[day] }));
}

function formatPeso(value: number) {
  return `₱${Number(value).toLocaleString('en-PH', { maximumFractionDigits: 2 })}`;
}

function openGymPreview(gym: PublicGym) {
  previewGym.value = gym;
}

function closeGymPreview() {
  previewGym.value = null;
}

// From the sign-in screen this opens Create Member Account with the gym already chosen.
function chooseGym(gym: PublicGym) {
  if (authMode.value !== 'register') {
    showRegisterForm();
  }
  registerForm.value.gym_id = String(gym.id);
  if (registerError.value === "Please choose which gym you're joining.") registerError.value = '';
  previewGym.value = null;
}

function onGymPreviewKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && previewGym.value) closeGymPreview();
}

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
      availableGyms.value = data.gyms as PublicGym[];
    }
  } catch {
    availableGyms.value = [];
  }

  window.addEventListener('keydown', onGymPreviewKeydown);
});

onUnmounted(() => {
  window.removeEventListener('keydown', onGymPreviewKeydown);
});
</script>

<template>
  <Head title="FitGym - Sign In" />

  <div class="fitgym-entry fg-marble-bg">
    <FgThemeToggle floating />

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

    <div class="entry-shell" :class="{ 'entry-shell--wide': memberSplit && !checkingSession }">
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
          <p class="entry-eyebrow">FitGym Platform</p>
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
          <span class="hero-badge">
            <span class="hero-dot" :style="{ backgroundColor: portal.lime }" />
            Multi-gym platform
          </span>
        </div>
        <h2 class="hero-title">Run your gym.<br />Grow every branch.</h2>
        <p class="hero-subtitle">
          Members, staff, attendance, and payments — one system for every gym on the platform, each fully your own.
        </p>
        <div class="hero-actions">
          <button type="button" class="entry-primary hero-primary" :style="{ backgroundColor: portal.purple }" @click="enterSignIn">
            Sign In
          </button>
          <button type="button" class="entry-ghost hero-ghost" @click="enterGymSignup">
            Own a Gym? Start Your Free Trial
          </button>
        </div>
        <p class="hero-footnote">
          Already a member? <a href="#" @click.prevent="enterSignIn">Sign in here</a>.
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
      <section v-else class="entry-card" :class="{ 'entry-card--split': memberSplit }" :style="{ backgroundColor: portal.dark3, borderColor: portal.border }">
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
          <div class="entry-signin">
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
          </div>

          <div v-if="selectedRole === 'member'" class="entry-register">
            <p :style="{ color: portal.textDim }">Don't have an account?</p>
            <button type="button" class="entry-ghost" :style="{ borderColor: portal.pink, color: portal.pink }" @click="showRegisterForm">
              Create Member Account
            </button>

            <div v-if="availableGyms.length" class="entry-gyms">
              <p class="entry-gyms-title" :style="{ color: portal.textDim }">Our gyms — tap one to see what it offers</p>
              <div class="gym-picker">
                <button
                  v-for="(gym, index) in availableGyms"
                  :key="gym.id"
                  type="button"
                  class="gym-option"
                  :style="{ animationDelay: `${index * 70}ms` }"
                  @click="openGymPreview(gym)"
                >
                  <span class="gym-option-photo">
                    <img v-if="gym.cover_photo_url" :src="gym.cover_photo_url" alt="" />
                    <template v-else>{{ gymInitials(gym.name) }}</template>
                  </span>
                  <span class="gym-option-text">
                    <span class="gym-option-name">{{ gym.name }}</span>
                    <span class="gym-option-meta">{{ gym.address || (gym.plans.length ? `Plans from ${formatPeso(gym.plans[0].price)}` : 'See details') }}</span>
                  </span>
                  <span class="gym-option-action">View</span>
                </button>
              </div>
            </div>
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
                <p class="entry-hint" :style="{ color: portal.textDim }">Tap a gym to choose it. Use View to see what it offers.</p>
                <div class="gym-picker" role="radiogroup" aria-label="Which gym are you joining?">
                  <div
                    v-for="(gym, index) in availableGyms"
                    :key="gym.id"
                    class="gym-option gym-option--split"
                    :class="{ 'gym-option--chosen': chosenGym?.id === gym.id }"
                    :style="{ animationDelay: `${index * 70}ms` }"
                  >
                    <button
                      type="button"
                      class="gym-option-main"
                      role="radio"
                      :aria-checked="chosenGym?.id === gym.id"
                      @click="chooseGym(gym)"
                    >
                      <span class="gym-option-check" aria-hidden="true">
                        <svg v-if="chosenGym?.id === gym.id" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                      </span>
                      <span class="gym-option-photo">
                        <img v-if="gym.cover_photo_url" :src="gym.cover_photo_url" alt="" />
                        <template v-else>{{ gymInitials(gym.name) }}</template>
                      </span>
                      <span class="gym-option-text">
                        <span class="gym-option-name">{{ gym.name }}</span>
                        <span class="gym-option-meta">{{ gym.address || (gym.plans.length ? `Plans from ${formatPeso(gym.plans[0].price)}` : 'See details') }}</span>
                      </span>
                    </button>
                    <button type="button" class="gym-option-action" :aria-label="`View ${gym.name}`" @click="openGymPreview(gym)">View</button>
                  </div>
                  <p v-if="availableGyms.length === 0" class="entry-hint" :style="{ color: portal.textDim }">No gyms are open for sign-up right now.</p>
                </div>
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

      <p v-if="viewState === 'auth'" class="entry-footnote">
        One sign-in page for Admin, Staff, and Member accounts.
      </p>
    </div>
  </div>

  <!-- Gym details pop-up for people choosing a branch while creating a Member account -->
  <Teleport to="body">
    <Transition name="gym-pop">
      <div v-if="previewGym" class="gym-preview-overlay" @click.self="closeGymPreview">
        <div class="gym-preview fg-light-surface" role="dialog" aria-modal="true" :aria-label="previewGym.name">
          <button type="button" class="gym-preview-close" aria-label="Close" @click="closeGymPreview">&times;</button>

          <div class="gym-preview-cover">
            <img v-if="previewGym.cover_photo_url" :src="previewGym.cover_photo_url" :alt="previewGym.name" />
            <span v-else class="gym-preview-cover-initials">{{ gymInitials(previewGym.name) }}</span>
          </div>

          <div class="gym-preview-body">
            <div class="gym-preview-step" style="--step: 0">
              <h3 class="gym-preview-name">{{ previewGym.name }}</h3>
              <p v-if="previewGym.address" class="gym-preview-sub">{{ previewGym.address }}</p>
            </div>

            <div class="gym-preview-stats gym-preview-step" style="--step: 1">
              <div>
                <strong>{{ previewGym.plans.length ? formatPeso(previewGym.plans[0].price) : '—' }}</strong>
                <span>Plans from</span>
              </div>
              <div>
                <strong>{{ previewGym.staff_count }}</strong>
                <span>{{ previewGym.staff_count === 1 ? 'Staff member' : 'Staff' }}</span>
              </div>
              <div>
                <strong>{{ previewGym.classes.length }}</strong>
                <span>{{ previewGym.classes.length === 1 ? 'Class' : 'Classes' }}</span>
              </div>
            </div>

            <div class="gym-preview-step" style="--step: 2">
              <h4>About</h4>
              <p class="gym-preview-text">{{ previewGym.description || 'No description yet. Check the plans and hours below, or contact the front desk.' }}</p>
            </div>

            <div v-if="previewGym.plans.length" class="gym-preview-step" style="--step: 3">
              <h4>Membership plans</h4>
              <ul class="gym-preview-plans">
                <li v-for="plan in previewGym.plans" :key="plan.name">
                  <span>{{ plan.name }}</span>
                  <span><strong>{{ formatPeso(plan.price) }}</strong> <small v-if="plan.period">{{ plan.period }}</small></span>
                </li>
              </ul>
            </div>

            <div v-if="gymHours(previewGym).length" class="gym-preview-step" style="--step: 4">
              <h4>Opening hours</h4>
              <ul class="gym-preview-hours">
                <li v-for="row in gymHours(previewGym)" :key="row.day"><span>{{ row.day }}</span><span>{{ row.hours }}</span></li>
              </ul>
            </div>

            <div v-if="previewGym.classes.length" class="gym-preview-step" style="--step: 5">
              <h4>Classes</h4>
              <div class="gym-preview-chips">
                <span v-for="name in previewGym.classes" :key="name">{{ name }}</span>
              </div>
            </div>

            <div v-if="previewGym.phone || previewGym.email" class="gym-preview-step" style="--step: 6">
              <h4>Contact</h4>
              <p class="gym-preview-text">
                <template v-if="previewGym.phone">{{ previewGym.phone }}</template>
                <template v-if="previewGym.phone && previewGym.email"> · </template>
                <template v-if="previewGym.email">{{ previewGym.email }}</template>
              </p>
            </div>
          </div>

          <div class="gym-preview-actions">
            <button type="button" class="gym-preview-back" @click="closeGymPreview">Back</button>
            <button type="button" class="gym-preview-join" @click="chooseGym(previewGym)">
              {{ chosenGym?.id === previewGym.id ? 'Selected — continue' : 'Join this gym' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fitgym-entry {
  position: relative;
  display: flex;
  min-height: 100vh;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  padding: 1.25rem 1rem;
  color: var(--fg-card-ink);
  font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
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
}

.entry-logo svg {
  width: 1.6rem;
  height: 1.6rem;
  color: #ffffff;
}

/* Brand, hero and footnote text sit straight on the marble background, so they use dark ink */
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
  border: 1px solid rgba(var(--fg-accent-deep-rgb), 0.45);
  border-radius: 999px;
  color: var(--fg-ink-soft);
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
  color: var(--fg-ink);
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
  color: var(--fg-ink);
  margin: 0;
  font-size: clamp(2rem, 5vw, 3rem);
  font-weight: 800;
  line-height: 1.1;
  letter-spacing: -0.01em;
}

.hero-subtitle {
  color: var(--fg-ink-soft);
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

.hero-ghost {
  border-color: #3f6b00;
  color: #3f6b00;
}

.hero-footnote {
  color: var(--fg-ink-soft);
  margin: 0.2rem 0 0;
  font-size: 0.88rem;
}

.hero-footnote a {
  color: #6b3fc4;
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
  padding: 1.4rem 1.6rem;
  box-shadow: 0 18px 44px rgba(92, 70, 30, 0.1);
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
  gap: 0.3rem;
  border-radius: 0.9rem;
  border-width: 1px;
  border-style: solid;
  padding: 0.7rem 0.6rem;
  cursor: pointer;
  text-align: center;
  color: var(--fg-card-ink);
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
  margin: 0.65rem 0 0;
  font-size: 0.8rem;
  line-height: 1.5;
}

.entry-divider {
  height: 1px;
  margin: 1rem 0;
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
  border: 1px solid rgba(224, 86, 91, 0.5);
  background-color: rgba(224, 86, 91, 0.1);
  padding: 0.7rem 0.85rem;
  font-size: 0.85rem;
  color: #f08a8e;
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
  color: var(--fg-card-ink);
  outline: none;
}

.entry-field select {
  appearance: none;
}

.entry-field input::placeholder {
  color: #77716a;
}

.entry-field input:focus,
.entry-field select:focus {
  border-color: #8a2be2;
}

.entry-field input:-webkit-autofill,
.entry-field input:-webkit-autofill:hover,
.entry-field input:-webkit-autofill:focus,
.entry-field input:-webkit-autofill:active {
  -webkit-text-fill-color: #2b2418;
  -webkit-box-shadow: 0 0 0px 1000px #fffaf0 inset;
  box-shadow: 0 0 0px 1000px #fffaf0 inset;
  caret-color: #2b2418;
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
  margin-top: 1rem;
  text-align: center;
}

.entry-register p {
  margin: 0;
  font-size: 0.85rem;
}

.entry-gyms {
  margin-top: 1rem;
  border-top: 1px solid var(--fg-card-border);
  padding-top: 0.9rem;
  text-align: left;
}

/* ---- Member sign-in on a laptop/desktop: two columns, no scrolling ----
   Left: Step 2 sign-in form. Right: Create Member Account and our gyms.
   Phones keep the single column (scrolling there is normal). */
@media (min-width: 1024px) {
  .entry-shell--wide {
    max-width: 58rem;
  }

  .entry-card--split {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    column-gap: 2rem;
  }

  .entry-card--split > * {
    grid-column: 1 / -1;
  }

  .entry-card--split > .entry-back {
    justify-self: start;
  }

  .entry-card--split > .entry-signin {
    grid-column: 1;
  }

  .entry-card--split > .entry-register {
    grid-column: 2;
    display: flex;
    flex-direction: column;
    margin-top: 0;
    border-left: 1px solid var(--fg-card-border);
    padding-left: 2rem;
    text-align: left;
  }

  .entry-card--split > .entry-register .entry-gyms {
    margin-top: 0.9rem;
  }
}

.entry-register p.entry-gyms-title {
  margin-bottom: 0.6rem;
  font-size: 0.78rem;
  font-weight: 600;
}

.entry-footnote {
  color: var(--fg-ink-soft);
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

/* ---- Gym picker on the Create Member Account form ---- */
.gym-picker {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin-top: 0.2rem;
}

.gym-option {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  width: 100%;
  border: 1px solid var(--fg-card-border);
  border-radius: 0.9rem;
  background: var(--fg-card-2);
  padding: 0.7rem 0.85rem;
  color: var(--fg-card-ink);
  text-align: left;
  cursor: pointer;
  animation: gym-option-in 0.42s cubic-bezier(0.22, 1, 0.36, 1) both;
  transition:
    border-color 0.18s ease,
    box-shadow 0.18s ease,
    transform 0.18s ease;
}

.gym-option:hover {
  border-color: rgba(var(--fg-accent-rgb), 0.7);
  transform: translateY(-2px);
}

.gym-option--chosen {
  border-color: var(--fg-accent);
  box-shadow: 0 0 0 1px var(--fg-accent), 0 10px 24px rgba(var(--fg-accent-rgb), 0.22);
}

.gym-option-photo {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 3rem;
  height: 3rem;
  overflow: hidden;
  border-radius: 0.7rem;
  background: linear-gradient(135deg, var(--fg-accent-light) 0%, var(--fg-accent) 55%, var(--fg-accent-dark) 100%);
  color: var(--fg-accent-ink);
  font-size: 0.95rem;
  font-weight: 700;
}

.gym-option-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.gym-option-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.gym-option-name {
  overflow: hidden;
  font-size: 0.92rem;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.gym-option-meta {
  overflow: hidden;
  color: var(--fg-card-ink-soft);
  font-size: 0.75rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.gym-option-action {
  flex-shrink: 0;
  border: 1px solid rgba(var(--fg-accent-rgb), 0.6);
  border-radius: 9999px;
  padding: 0.25rem 0.7rem;
  color: var(--fg-card-gold);
  font-size: 0.72rem;
  font-weight: 600;
}

.gym-option--chosen .gym-option-action {
  border-color: var(--fg-accent);
  background: var(--fg-accent);
  color: var(--fg-accent-ink);
}

/* Inside Create Member Account: the card picks the gym, View opens its details */
.gym-option--split {
  padding: 0;
  cursor: default;
}

.gym-option-main {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 0.85rem;
  min-width: 0;
  border: 0;
  background: transparent;
  padding: 0.7rem 0 0.7rem 0.85rem;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.gym-option--split .gym-option-action {
  margin-right: 0.85rem;
  background: transparent;
  cursor: pointer;
}

.gym-option--split.gym-option--chosen .gym-option-action {
  border-color: rgba(var(--fg-accent-rgb), 0.6);
  background: transparent;
  color: var(--fg-card-gold);
}

.gym-option--split .gym-option-action:hover {
  background: rgba(var(--fg-accent-rgb), 0.14);
}

.gym-option-check {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 1.25rem;
  height: 1.25rem;
  border: 2px solid var(--fg-card-border);
  border-radius: 9999px;
  color: var(--fg-accent-ink);
  transition: background 0.18s ease, border-color 0.18s ease;
}

.gym-option--chosen .gym-option-check {
  border-color: var(--fg-accent);
  background: var(--fg-accent);
}

.gym-option-check svg {
  width: 0.75rem;
  height: 0.75rem;
}

@keyframes gym-option-in {
  from {
    opacity: 0;
    transform: translateY(8px);
  }
}

/* ---- Gym details pop-up ---- */
.gym-preview-overlay {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgba(15, 12, 6, 0.6);
  backdrop-filter: blur(3px);
}

.gym-preview {
  position: relative;
  display: flex;
  flex-direction: column;
  width: min(32rem, 100%);
  max-height: calc(100dvh - 2rem);
  overflow: hidden;
  border: 1px solid var(--fg-card-border);
  border-radius: 1.25rem;
  color: var(--fg-card-ink);
}

.gym-preview-close {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  z-index: 1;
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border: 0;
  border-radius: 9999px;
  background: rgba(21, 21, 21, 0.6);
  color: #fff;
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
}

.gym-preview-cover {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  height: 9.5rem;
  overflow: hidden;
  background:
    linear-gradient(rgba(10, 10, 10, 0.35), rgba(10, 10, 10, 0.35)),
    url('../../../images/fitgym-box-marble.jpg') center / cover;
}

.gym-preview-cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.gym-preview-cover-initials {
  display: grid;
  place-items: center;
  width: 4.5rem;
  height: 4.5rem;
  border-radius: 1.1rem;
  background: linear-gradient(135deg, var(--fg-accent-light) 0%, var(--fg-accent) 55%, var(--fg-accent-dark) 100%);
  color: var(--fg-accent-ink);
  font-size: 1.6rem;
  font-weight: 700;
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.35);
}

.gym-preview-body {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  overflow-y: auto;
  padding: 1.25rem 1.4rem;
}

.gym-preview-name {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 700;
}

.gym-preview-sub {
  margin: 0.2rem 0 0;
  color: var(--fg-card-ink-soft);
  font-size: 0.85rem;
}

.gym-preview-body h4 {
  margin: 0 0 0.45rem;
  color: var(--fg-card-gold);
  font-size: 0.78rem;
  font-weight: 700;
}

.gym-preview-text {
  margin: 0;
  font-size: 0.88rem;
  line-height: 1.6;
  white-space: pre-line;
}

.gym-preview-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
}

.gym-preview-stats > div {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  border: 1px solid var(--fg-card-border);
  border-radius: 0.75rem;
  background: var(--fg-card-2);
  padding: 0.6rem 0.7rem;
}

.gym-preview-stats strong {
  font-size: 1.05rem;
}

.gym-preview-stats span {
  color: var(--fg-card-ink-soft);
  font-size: 0.72rem;
}

.gym-preview-plans,
.gym-preview-hours {
  margin: 0;
  padding: 0;
  list-style: none;
}

.gym-preview-plans li,
.gym-preview-hours li {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  border-bottom: 1px solid var(--fg-card-border);
  padding: 0.45rem 0;
  font-size: 0.86rem;
}

.gym-preview-plans li:last-child,
.gym-preview-hours li:last-child {
  border-bottom: 0;
}

.gym-preview-plans small {
  color: var(--fg-card-ink-soft);
}

.gym-preview-hours {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  column-gap: 1.25rem;
}

.gym-preview-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.gym-preview-chips span {
  border: 1px solid rgba(var(--fg-accent-rgb), 0.6);
  border-radius: 9999px;
  background: rgba(var(--fg-accent-rgb), 0.12);
  padding: 0.2rem 0.7rem;
  font-size: 0.78rem;
}

.gym-preview-actions {
  display: flex;
  flex-shrink: 0;
  gap: 0.6rem;
  border-top: 1px solid var(--fg-card-border);
  padding: 0.9rem 1.4rem;
}

.gym-preview-back,
.gym-preview-join {
  border-radius: 0.7rem;
  padding: 0.75rem 1rem;
  font-size: 0.9rem;
  font-weight: 700;
  cursor: pointer;
}

.gym-preview-back {
  border: 1px solid var(--fg-card-border);
  background: transparent;
  color: var(--fg-card-ink);
}

.gym-preview-join {
  flex: 1;
  border: 1px solid var(--fg-accent-light);
  background: linear-gradient(135deg, var(--fg-accent-light) 0%, var(--fg-accent) 55%, var(--fg-accent-dark) 100%);
  color: var(--fg-accent-ink);
}

.gym-preview-join:hover {
  filter: brightness(1.06);
}

/* Pop-up animation: the backdrop fades, the card springs up, then each section slides in */
.gym-pop-enter-active,
.gym-pop-leave-active {
  transition: opacity 0.25s ease;
}

.gym-pop-enter-active .gym-preview {
  transition: transform 0.45s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
}

.gym-pop-leave-active .gym-preview {
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.gym-pop-enter-from,
.gym-pop-leave-to {
  opacity: 0;
}

.gym-pop-enter-from .gym-preview {
  opacity: 0;
  transform: translateY(28px) scale(0.92);
}

.gym-pop-leave-to .gym-preview {
  opacity: 0;
  transform: translateY(12px) scale(0.97);
}

.gym-preview-step {
  animation: gym-step-in 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
  animation-delay: calc(140ms + var(--step, 0) * 60ms);
}

@keyframes gym-step-in {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
}

@media (max-width: 479.98px) {
  .gym-preview-stats {
    grid-template-columns: 1fr 1fr 1fr;
  }

  .gym-preview-hours {
    grid-template-columns: 1fr;
  }
}

@media (prefers-reduced-motion: reduce) {
  .gym-option,
  .gym-preview-step {
    animation: none;
  }

  .gym-pop-enter-active .gym-preview,
  .gym-pop-leave-active .gym-preview {
    transition: none;
  }
}
</style>
