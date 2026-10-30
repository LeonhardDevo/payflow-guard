import type { ComputedRef, Ref } from 'vue';
import { computed, readonly, ref } from 'vue';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Readonly<Ref<Appearance>>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

const STORAGE_KEY = 'appearance';
const COOKIE_MAX_AGE_DAYS = 365;

export const THEME_EVENT = 'appearance:change';

function isAppearance(value: unknown): value is Appearance {
    return value === 'light' || value === 'dark' || value === 'system';
}

function prefersDark(): boolean {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function readStoredAppearance(): Appearance | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        if (isAppearance(value)) {
            return value;
        }
    } catch {}

    const fromDom = document.documentElement.dataset.appearance;

    return isAppearance(fromDom) ? fromDom : null;
}

function writeCookie(value: Appearance): void {
    const maxAge = COOKIE_MAX_AGE_DAYS * 24 * 60 * 60;

    document.cookie = `${STORAGE_KEY}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
}

const appearance = ref<Appearance>('system');
const systemDark = ref(false);

const resolvedAppearance = computed<ResolvedAppearance>(() => {
    if (appearance.value === 'system') {
        return systemDark.value ? 'dark' : 'light';
    }

    return appearance.value;
});

export function updateTheme(value: Appearance): void {
    if (typeof document === 'undefined') {
        return;
    }

    const dark = value === 'dark' || (value === 'system' && prefersDark());
    const root = document.documentElement;
    const changed = root.classList.contains('dark') !== dark;

    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';
    root.dataset.appearance = value;

    if (changed) {
        document.dispatchEvent(
            new CustomEvent(THEME_EVENT, { detail: { dark } }),
        );
    }
}

let initialized = false;

export function initializeTheme(): void {
    if (typeof window === 'undefined' || initialized) {
        return;
    }

    initialized = true;
    appearance.value = readStoredAppearance() ?? 'system';
    systemDark.value = prefersDark();
    updateTheme(appearance.value);

    window
        .matchMedia?.('(prefers-color-scheme: dark)')
        .addEventListener('change', (event) => {
            systemDark.value = event.matches;

            if (appearance.value === 'system') {
                updateTheme('system');
            }
        });
}

export function useAppearance(): UseAppearanceReturn {
    function updateAppearance(value: Appearance): void {
        appearance.value = value;

        try {
            window.localStorage.setItem(STORAGE_KEY, value);
        } catch {}

        writeCookie(value);
        updateTheme(value);
    }

    return {
        appearance: readonly(appearance),
        resolvedAppearance,
        updateAppearance,
    };
}
