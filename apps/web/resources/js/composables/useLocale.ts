import { router, usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed, ref } from 'vue';
import { endpoints } from '@/lib/endpoints';
import { applyLocale, isLocale } from '@/lib/i18n';
import type { Locale, LocaleOption } from '@/types/shared';

const FALLBACK_LOCALES: LocaleOption[] = [
    { code: 'en', label: 'English' },
    { code: 'ru', label: 'Русский' },
];

const switching = ref(false);

export type UseLocaleReturn = {
    locale: ComputedRef<Locale>;
    locales: ComputedRef<LocaleOption[]>;
    switching: typeof switching;
    setLocale: (locale: Locale) => Promise<void>;
};

export function useLocale(): UseLocaleReturn {
    const page = usePage();

    const locale = computed<Locale>(() =>
        isLocale(page.props.locale) ? page.props.locale : 'en',
    );

    const locales = computed<LocaleOption[]>(() =>
        page.props.availableLocales?.length
            ? page.props.availableLocales
            : FALLBACK_LOCALES,
    );

    async function setLocale(next: Locale): Promise<void> {
        if (switching.value) {
            return;
        }

        switching.value = true;
        await applyLocale(next);

        router.post(
            endpoints.locale(next).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    switching.value = false;
                },
            },
        );
    }

    return { locale, locales, switching, setLocale };
}
