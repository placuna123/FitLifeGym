import { reactive } from 'vue';

/**
 * Show/hide state for the password fields on a page, keyed by field name:
 * `<input :type="pw.type('login')">` + `<PasswordToggle :shown="pw.isShown('login')" @toggle="pw.toggle('login')" />`.
 */
export function usePasswordVisibility() {
    const shown = reactive<Record<string, boolean>>({});

    return {
        type: (key: string) => (shown[key] ? 'text' : 'password'),
        isShown: (key: string) => Boolean(shown[key]),
        toggle: (key: string) => {
            shown[key] = !shown[key];
        },
    };
}
