import api from './client';

/** Unified entry page (fitgym/Portal.vue): shared session lookup + sign-out. */
export const portalAuth = {
    session: () => api.get('/portal/session'),
    logout: () => api.post('/portal/logout'),
};

export const adminAuth = {
    login: (data: { email: string; password: string; remember?: boolean }) =>
        api.post('/admin/login', data),
    logout: () => api.post('/admin/logout'),
    user: () => api.get('/admin/user'),
    profile: () => api.get('/admin/profile'),
    updateProfile: (data: Record<string, unknown>) => api.put('/admin/profile', data),
    changePassword: (data: { current_password: string; new_password: string; confirm_password: string }) =>
        api.post('/admin/change-password', data),
};

export const gymInfo = {
    get: () => api.get('/gym/info'),
    update: (data: Record<string, unknown> | FormData) => {
        if (data instanceof FormData) {
            if (!data.has('_method')) {
                data.append('_method', 'PUT');
            }
            return api.post('/gym/info', data, { headers: { 'Content-Type': 'multipart/form-data' } });
        }
        return api.put('/gym/info', data);
    },
};

export const members = {
    list: (params?: { search?: string; status?: string; plan?: string }) =>
        api.get('/members', { params }),
    get: (id: string | number) => api.get(`/members/${id}`),
    create: (data: Record<string, unknown>) => api.post('/members', data),
    sendRegistrationOtp: (data: Record<string, unknown>) => api.post('/members/register/send-otp', data),
    register: (data: Record<string, unknown>) => api.post('/members/register', data),
    update: (id: string | number, data: Record<string, unknown>) => api.put(`/members/${id}`, data),
    delete: (id: string | number) => api.delete(`/members/${id}`),
    sendSms: (id: string | number, data: { message: string }) => api.post(`/members/${id}/send-sms`, data),
    approve: (id: string | number) => api.post(`/members/${id}/approve`),
};

export const membershipPlans = {
    list: (params?: Record<string, unknown>) => api.get('/membership-plans', { params }),
    get: (id: string | number) => api.get(`/membership-plans/${id}`),
    create: (data: Record<string, unknown>) => api.post('/membership-plans', data),
    update: (id: string | number, data: Record<string, unknown>) =>
        api.put(`/membership-plans/${id}`, data),
    delete: (id: string | number) => api.delete(`/membership-plans/${id}`),
};

export const payments = {
    list: () => api.get('/payments'),
    get: (id: string | number) => api.get(`/payments/${id}`),
    create: (data: Record<string, unknown>) => api.post('/payments', data),
    update: (id: string | number, data: Record<string, unknown>) =>
        api.put(`/payments/${id}`, data),
    delete: (id: string | number) => api.delete(`/payments/${id}`),
};

export const reports = {
    revenue: () => api.get('/reports/revenue'),
    attendance: () => api.get('/reports/attendance'),
    membership: () => api.get('/reports/membership'),
    quarterly: () => api.get('/reports/quarterly'),
    breakdown: () => api.get('/reports/breakdown'),
    exportCsv: () => api.get('/reports/export-csv', { responseType: 'blob' }),
    exportExcel: () => api.get('/reports/export-excel', { responseType: 'blob' }),
};

export const trainer = {
    list: () => api.get('/trainer'),
    get: (id: string | number) => api.get(`/trainer/${id}`),
    create: (data: Record<string, unknown>) => api.post('/trainer', data),
    update: (id: string | number, data: Record<string, unknown>) => api.put(`/trainer/${id}`, data),
    delete: (id: string | number) => api.delete(`/trainer/${id}`),
};

export const trainerAttendance = {
    checkIn: (data: Record<string, unknown>) => api.post('/trainer/attendance/check-in', data),
    processQr: (payload: string) => api.post('/trainer/attendance/process-qr', { payload }),
    logs: (params?: { search?: string }) => api.get('/trainer/attendance/logs', { params }),
    recent: () => api.get('/trainer/attendance/recent'),
    currentlyInGym: () => api.get('/trainer/attendance/currently-in-gym'),
};

export const adminTrainerAttendance = {
    list: (params?: { date?: string }) => api.get('/admin/trainer-attendance', { params }),
    checkIn: (data: Record<string, unknown>) => api.post('/admin/trainer-attendance/check-in', data),
    checkOut: (data: Record<string, unknown>) => api.post('/admin/trainer-attendance/check-out', data),
    markStatus: (data: Record<string, unknown>) => api.post('/admin/trainer-attendance/mark-status', data),
};

export const memberAuth = {
    login: (data: { email: string; password: string; remember?: boolean }) => api.post('/member/login', data),
    logout: () => api.post('/member/logout'),
    user: () => api.get('/member/user'),
    updateProfile: (data: {
        name: string;
        email: string;
        phone: string | null;
        fitness_goal: string | null;
        weight_kg: number | null;
    }) => api.put('/member/profile', data),
    changePassword: (data: { current_password: string; new_password: string; new_password_confirmation: string }) =>
        api.post('/member/change-password', data),
    submitSupportTicket: (data: { subject: string; message: string }) => api.post('/member/support-tickets', data),
};

export const memberAttendance = {
    checkIn: (memberId: number) => api.post('/trainer/attendance/check-in', { member_id: memberId }),
    issueQr: () => api.post('/member/attendance/qr'),
    checkOut: () => api.post('/member/attendance/check-out'),
    logs: () => api.get('/member/attendance/logs'),
    active: () => api.get('/member/attendance/active'),
};

export const memberPayments = {
    list: () => api.get('/member/payments'),
    create: (data: { membership_plan_id: number; amount: number; payment_method: string } | FormData) =>
        data instanceof FormData
            ? api.post('/member/payments', data, { headers: { 'Content-Type': 'multipart/form-data' } })
            : api.post('/member/payments', data),
};

export const gymClasses = {
    list: (params?: { date?: string }) => api.get('/gym-classes', { params }),
    create: (data: Record<string, unknown>) => api.post('/gym-classes', data),
    update: (id: string | number, data: Record<string, unknown>) => api.put(`/gym-classes/${id}`, data),
    delete: (id: string | number) => api.delete(`/gym-classes/${id}`),
};

export const memberClasses = {
    list: (date?: string) => api.get('/member/classes', { params: date ? { date } : {} }),
    book: (data: { gym_class_id: number; class_date: string }) => api.post('/member/classes/book', data),
    cancel: (id: string | number) => api.delete(`/member/classes/bookings/${id}`),
};

export const memberProgress = {
    get: () => api.get('/member/progress'),
    logWeight: (weight_kg: number) => api.post('/member/progress/weight', { weight_kg }),
    updateGoal: (data: { height_cm?: number | null; goal_weight_kg?: number | null }) =>
        api.put('/member/progress/goal', data),
};

export const memberNotifications = {
    list: () => api.get('/member/notifications'),
    markAllRead: () => api.post('/member/notifications/mark-all-read'),
    markRead: (id: string | number) => api.post(`/member/notifications/${id}/read`),
};

export const trainerAuth = {
    login: (data: { email: string; password: string; remember?: boolean }) =>
        api.post('/trainer/login', data),
    logout: () => api.post('/trainer/logout'),
    user: () => api.get('/trainer/user'),
};

export const trainerProfile = {
    show: () => api.get('/trainer/profile'),
    update: (data: Record<string, unknown>) => api.put('/trainer/profile', data),
    changePassword: (data: { current_password: string; new_password: string; new_password_confirmation: string }) =>
        api.post('/trainer/profile/change-password', data),
};

export const adminNotifications = {
    list: () => api.get('/admin/notifications'),
    markAllRead: () => api.post('/admin/notifications/mark-all-read'),
    markRead: (id: string | number) => api.post(`/admin/notifications/${id}/read`),
    dismiss: (id: string | number) => api.post(`/admin/notifications/${id}/dismiss`),
};

export const trainerMembers = {
    active: () => api.get('/trainer/members/active'),
    expiring: () => api.get('/trainer/members/expiring'),
    store: (data: Record<string, unknown>) => api.post('/trainer/members', data),
    renew: (id: string | number) => api.post(`/trainer/members/${id}/renew`),
};

/** Public "which gyms can I join/see" list — fed to the gym picker on registration. */
export const gymsPublic = {
    list: () => api.get('/gyms/public'),
};

/** Public "start your gym's free trial" self-signup — creates a Gym + its first Admin. */
export const gymSignup = {
    store: (data: { gym_name: string; admin_name: string; admin_email: string; admin_password: string }) =>
        api.post('/gyms/signup', data),
};

/** Platform / Super Admin auth — deliberately not on the shared role-selector. */
export const platformAuth = {
    login: (data: { email: string; password: string; remember?: boolean }) => api.post('/platform/login', data),
    logout: () => api.post('/platform/logout'),
    user: () => api.get('/platform/user'),
    changePassword: (data: { current_password: string; new_password: string; new_password_confirmation: string }) =>
        api.post('/platform/change-password', data),
};

export const platformGyms = {
    list: () => api.get('/platform/gyms'),
    get: (id: string | number) => api.get(`/platform/gyms/${id}`),
    create: (data: Record<string, unknown>) => api.post('/platform/gyms', data),
    update: (id: string | number, data: Record<string, unknown>) => api.put(`/platform/gyms/${id}`, data),
    delete: (id: string | number) => api.delete(`/platform/gyms/${id}`),
};

export const platformAnalytics = {
    get: () => api.get('/platform/analytics'),
};

export const platformAuditLogs = {
    list: (params?: { gym_id?: string | number; action?: string }) => api.get('/platform/audit-logs', { params }),
};

/** Profile photo of the signed-in account (any role). */
export const profilePhoto = {
    upload: (file: File) => {
        const form = new FormData();
        form.append('photo', file);
        return api.post('/profile/photo', form, { headers: { 'Content-Type': 'multipart/form-data' } });
    },
    remove: () => api.delete('/profile/photo'),
};
