import axios, { type AxiosInstance } from 'axios';

const token = document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
const appUrlMeta = document.head.querySelector<HTMLMetaElement>('meta[name="app-url"]');
const appBaseUrl = (appUrlMeta?.content || import.meta.env.VITE_APP_URL || window.location.origin).replace(/\/+$/, '');
const apiBaseUrl = `${appBaseUrl}/api`;

export const api: AxiosInstance = axios.create({
    baseURL: apiBaseUrl,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token?.content ? { 'X-CSRF-TOKEN': token.content } : {}),
    },
    withCredentials: true,
});

api.interceptors.request.use((config) => {
    const csrf = document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (csrf?.content) config.headers.set('X-CSRF-TOKEN', csrf.content);
    return config;
});

api.interceptors.response.use(
    (response) => response,
    async (error) => {
        if (error.response?.status === 419) {
            // CSRF token mismatch or expired session.
            // Refresh CSRF token and retry once before forcing a reload.
            const config = error.config as unknown as { url?: string; method?: string; data?: unknown; headers?: unknown; _retry419?: boolean } | undefined;
            const url = config?.url ?? '';
            const method = String(config?.method ?? '').toLowerCase();

            if (config && !config._retry419) {
                config._retry419 = true;
                try {
                    const csrfRes = await fetch(`${apiBaseUrl}/csrf-token`, {
                        method: 'GET',
                        credentials: 'include',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (csrfRes.ok) {
                        const data = await csrfRes.json() as { csrf_token?: string };
                        const newToken = data.csrf_token ?? '';
                        if (newToken) {
                            const meta = document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
                            if (meta) meta.content = newToken;

                            const headers = config.headers as { set?: (k: string, v: string) => void; [key: string]: unknown } | undefined;
                            if (headers?.set) {
                                headers.set('X-CSRF-TOKEN', newToken);
                            } else {
                                (config.headers as Record<string, unknown> | undefined) ??= {};
                                (config.headers as Record<string, unknown>)['X-CSRF-TOKEN'] = newToken;
                            }
                        }

                        return api(config as Parameters<typeof api>[0]);
                    }
                } catch {
                    // continue to fallback behavior below
                }
            }

            // Fallback behavior if retry failed.
            if (url === '/trainer/members' && method === 'post') {
                try {
                    sessionStorage.setItem(
                        'pendingApiRequest',
                        JSON.stringify({ url, method, data: config?.data ?? null })
                    );
                } catch {
                    // ignore storage errors
                }
            }

            window.location.reload();
            return Promise.reject(error);
        }
        if (error.response?.status === 401) {
            const url = String(error.config?.url || '');
            const isFitGymAuthCheck =
                url.includes('admin/user') ||
                url.includes('member/user') ||
                url.includes('trainer/user') ||
                url.includes('platform/user') ||
                url.includes('admin/login') ||
                url.includes('member/login') ||
                url.includes('trainer/login') ||
                url.includes('platform/login');
            if (!isFitGymAuthCheck) {
                window.location.href = `${appBaseUrl}/login`;
            }
        }
        return Promise.reject(error);
    }
);

export default api;
