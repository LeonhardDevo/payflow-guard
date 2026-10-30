import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { createFormatter } from '@/lib/format';
import { isLocale, t, tOr, humanize } from '@/lib/i18n';
import { formatMoney } from '@/lib/pf/money';
import type { Money, Params, Reason, Signal } from '@/types/payflow';
import type { Locale } from '@/types/shared';

export function usePf() {
    const page = usePage();

    const locale = computed<Locale>(() =>
        isLocale(page.props.locale) ? page.props.locale : 'en',
    );
    const shared = computed(() => page.props.pf ?? null);

    function money(value: Money | null | undefined, compact = false): string {
        return formatMoney(value, locale.value, { compact });
    }

    function can(ability: string): boolean {
        return page.props.can?.[ability] === true;
    }

    function entitled(
        feature: keyof NonNullable<typeof shared.value>['entitlements'],
    ): boolean {
        return shared.value?.entitlements?.[feature] === true;
    }

    const formatter = computed(() =>
        createFormatter({ locale: locale.value, currency: 'USD' }),
    );

    function num(value: number | null | undefined, digits = 1): string {
        return formatter.value.number(value, {
            maximumFractionDigits: digits,
        });
    }

    function days(value: number | null | undefined, signed = false): string {
        if (value === null || value === undefined || Number.isNaN(value)) {
            return '—';
        }

        const text = num(value);

        return t('pf.client.days', {
            days: signed && value > 0 ? `+${text}` : text,
        });
    }

    function params(values: Params | null | undefined): Params {
        const out: Params = {};

        for (const [key, value] of Object.entries(values ?? {})) {
            out[key] = typeof value === 'number' ? num(value) : value;
        }

        return out;
    }

    function reason(item: Reason | Signal): string {
        return tOr(
            `pf.reasons.${item.code}`,
            humanize(item.code),
            params(item.params),
        );
    }

    function label(group: string, value: string | null | undefined): string {
        if (!value) {
            return '—';
        }

        return tOr(`pf.${group}.${value}`, humanize(value));
    }

    return {
        locale,
        shared,
        money,
        num,
        days,
        params,
        can,
        entitled,
        reason,
        label,
        t,
    };
}
