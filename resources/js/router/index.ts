/**
 * Client-side router for FitGym portals (optional).
 * Main app uses Inertia for routes; FitGym Admin/Trainer/Member use internal
 * view state (e.g. currentView) for tabs/sections. Use this module if you
 * later add Vue Router for FitGym sub-routes.
 */
export const fitgymAdminViews = [
  'dashboard',
  'members',
  'membership-plans',
  'attendance',
  'payments',
  'reports',
  'trainer',
  'notifications',
  'settings',
] as const;

export type FitGymAdminView = (typeof fitgymAdminViews)[number];
