import { createInertiaApp, router } from '@inertiajs/vue3';
import { I18n, i18nVue } from 'laravel-vue-i18n';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import FocusLayout from '@/layouts/FocusLayout.vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { applyLocale, isLocale } from '@/lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const dictionaries = import.meta.glob<{ default: Record<string, string> }>(
    '../../lang/*.json',
    { eager: true },
);

function resolveDictionary(lang: string): Record<string, string> {
    return dictionaries[`../../lang/${lang}.json`]?.default ?? {};
}

const htmlLang =
    typeof document !== 'undefined' ? document.documentElement.lang : '';
const initialLocale = isLocale(htmlLang) ? htmlLang : 'en';

void createInertiaApp({
    title: (title) =>
        !title
            ? appName
            : title.includes(appName)
              ? title
              : `${title} · ${appName}`,
    layout: (name) => {
        switch (true) {
            case name.startsWith('marketing/'):
                return MarketingLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('onboarding/'):
            case name.startsWith('invitations/'):
            case name.startsWith('legal/'):
            case name.startsWith('email/'):
            case name.startsWith('trusted/'):
            case name.startsWith('public/'):
                return FocusLayout;
            case name.startsWith('dev/'):
                return null;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app, { page }) => {
        const lang = isLocale(page.props.locale)
            ? page.props.locale
            : initialLocale;

        app.use(i18nVue, {
            lang,
            fallbackLang: 'en',
            resolve: resolveDictionary,
        });

        if (typeof window !== 'undefined') {
            I18n.getSharedInstance().loadLanguage(lang);
        }

        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#10B981',
        showSpinner: false,
    },
});

if (typeof window !== 'undefined') {
    router.on('success', (event) => {
        const locale = event.detail.page.props.locale;

        if (isLocale(locale)) {
            void applyLocale(locale);
        }
    });

    initializeTheme();

    initializeFlashToast();
}
