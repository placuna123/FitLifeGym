# Blade → Vue Frontend Migration Summary

This document summarizes the migration of the FitGym/Laravel Blade frontend into the Vue (Inertia) project while keeping the Laravel backend logic.

## 1. Blade Analysis (laravel-blade-app)

### Pages
- **Welcome** – Home with login/register links  
- **Dashboard** – Authenticated dashboard  
- **Profile** – Edit profile, password, delete account (partials: update-profile, update-password, delete-user)  
- **Auth** – login, register, forgot-password, reset-password, verify-email, confirm-password  
- **FitGym Admin** – `fitgym/admin` – Admin portal (login + dashboard, members, plans, attendance, payments, reports, staff, settings)  
- **FitGym Staff** – `fitgym/staff` – Staff portal (login + dashboard, attendance, members, etc.)  
- **FitGym Member** – `fitgym/member` – Member portal (HTML shell mounting React app)

### Layouts
- **app** – Main layout with navigation and slot  
- **guest** – Centered card layout for auth pages  

### Controllers / routes
- Web routes return views for `/`, `/dashboard`, `/profile`, `/fitgym/admin`, `/fitgym/staff`, `/fitgym/member`.  
- All FitGym features are already driven by **API routes** (JSON); Blade only renders the shell and the frontend JS calls the API.

---

## 2. Created / Modified in laravel-vue-app

### 2.1 Vue structure (`resources/js/`)

```
resources/js/
  api/
    client.ts       # Axios instance (CSRF, baseURL /api, withCredentials)
    fitgym.ts       # FitGym API: adminAuth, gymInfo, members, membershipPlans, payments, reports, staff, staffAuth, memberAuth, staffProfile
  pages/
    fitgym/
      Admin.vue     # Admin portal: login + sidebar (Dashboard, Members, Plans, Payments, Settings) + data tables
      Staff.vue     # Staff portal: login + dashboard placeholder
      Member.vue    # Member portal: login + “My Membership” summary
```

### 2.2 Backend (Laravel) in laravel-vue-app

**Models**
- `App\Models\Member`
- `App\Models\MembershipPlan`
- `App\Models\Payment`
- `App\Models\Staff`
- `User` model extended with `role` and `is_archived` (fillable + cast)

**Controllers**
- `AdminAuthController` – login, logout, user, profile, updateProfile, changePassword  
- `GymInfoController` – index (get gym info), update  
- `MemberController` – index, store, show, update, destroy, register  
- `MembershipPlanController` – index, store, show, update, destroy  
- `PaymentController` – index, store, show, update, destroy  
- `MemberAuthController` – login, logout, user  
- `StaffAuthController` – login, logout, user  

**Routes** (in `routes/web.php`)
- Inertia: `GET /fitgym/admin`, `/fitgym/staff`, `/fitgym/member` → render `fitgym/Admin`, `fitgym/Staff`, `fitgym/Member`.  
- API:  
  - Admin: `POST /api/admin/login`, `POST /api/admin/logout`, `GET /api/admin/user`, `GET|PUT /api/admin/profile`, `POST /api/admin/change-password`  
  - Gym: `GET|PUT /api/gym/info`  
  - Members: `GET|POST /api/members`, `POST /api/members/register`, `GET|PUT|DELETE /api/members/{id}`  
  - Plans: `GET|POST /api/membership-plans`, `GET|PUT|DELETE /api/membership-plans/{id}`  
  - Payments: `GET|POST /api/payments`, `GET|PUT|DELETE /api/payments/{id}`  
  - Member auth: `POST /api/member/login`, `POST /api/member/logout`, `GET /api/member/user`  
  - Staff auth: `POST /api/staff/login`, `GET /api/staff/user`, `POST /api/staff/logout`  

**Migrations**
- `2025_03_10_000000_add_role_and_is_archived_to_users_table.php`  
- `2025_03_10_100000_create_membership_plans_table.php`  
- `2025_03_10_100001_create_members_table.php`  
- `2025_03_10_100002_create_payments_table.php`  

---

## 3. Replacements (Blade → Vue)

| Blade | Vue |
|-------|-----|
| `@auth` / `@guest` | `v-if` + reactive `user` / `isLoggedIn` |
| `@foreach` | `v-for` |
| `@if` | `v-if` / `v-else` |
| `{{ $var }}` | `{{ var }}` or reactive state |
| Form `action` + `@csrf` | Axios with API client (CSRF from meta tag) |
| `route('name')` | Inertia `Link` or `router.visit`; API calls via `api/fitgym.ts` |

---

## 4. How to run

1. **Install deps (adds Axios)**  
   `npm install`

2. **Run migrations**  
   `php artisan migrate`  
   (Ensures `users` has `role`/`is_archived`, and `membership_plans`, `members`, `payments` exist.)  
   If you hit MySQL index length errors on a fresh DB, the app sets `Schema::defaultStringLength(191)` in `AppServiceProvider`. If the DB was partially migrated, run `php artisan migrate:fresh` (drops all tables) or fix manually.

3. **Dev**  
   `npm run dev`  
   In another terminal: `php artisan serve`  
   - `/` – Welcome  
   - `/dashboard` – Dashboard (auth)  
   - `/fitgym/admin` – Admin portal (login then dashboard/members/plans)  
   - `/fitgym/staff` – Staff portal  
   - `/fitgym/member` – Member portal  

4. **Create an admin user** (for FitGym admin):  
   Ensure a user has `role = 'Admin'` (e.g. in DB or via a seeder).  
   Optionally add a migration or seeder that sets `role` and `is_archived` on existing users.

---

## 5. What’s preserved

- All **backend logic**: validation, auth checks, role-based access, soft deletes (archived), same JSON response shapes.  
- **Profile** and **auth** (login/register/forgot/reset/verify) remain Inertia + Fortify in the Vue app; no change to their backend behavior.  
- FitGym **API contract** is kept so the Vue frontend can replace the Blade/JS (and optionally the React member app) without changing the API.

---

## 6. Optional next steps

- Copy over **Reports**, **Staff CRUD**, **StaffAttendance**, **StaffProfile**, **StaffMember** controllers and routes from `laravel-blade-app` if you need full parity.  
- Add **Vue router** under `resources/js/router/` for client-only FitGym sub-routes (e.g. `/fitgym/admin/members/:id`) while still using Inertia for initial load.  
- Expand **Admin.vue** / **Staff.vue** with modals and forms for add/edit member, add plan, record payment, etc., using the existing API in `api/fitgym.ts`.
