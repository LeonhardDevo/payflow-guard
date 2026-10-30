<script setup lang="ts">
import {
    ArrowLeftRight,
    AtSign,
    CalendarClock,
    Copy,
    Coins,
    FileText,
    Globe,
    Hourglass,
    Mail,
    PhoneCall,
    Split,
    Timer,
    TriangleAlert,
    UserX,
    EyeOff,
    Store,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import { usePf } from '@/composables/pf/usePf';
import { useFormat } from '@/composables/useFormat';
import { has, humanize, t, tc, tOr } from '@/lib/i18n';
import { signalShare, sortSignals } from '@/lib/pf/guard';
import type { Params, RiskBand, Signal } from '@/types/payflow-guard';

const props = withDefaults(
    defineProps<{
        signals: Signal[];
        currency?: string | null;
        score?: number | null;
        band?: RiskBand | null;
    }>(),
    {
        currency: null,
        score: null,
        band: null,
    },
);
const pf = usePf();
const fmt = useFormat();
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

const ICONS: Record<string, Component> = {
    request_via_email: Mail,
    request_via_document: FileText,
    lookalike_domain: AtSign,
    domain_mismatch: AtSign,
    holder_name_mismatch: UserX,
    country_changed: Globe,
    currency_changed: Coins,
    shared_account: ArrowLeftRight,
    reused_rejected_account: ArrowLeftRight,
    recent_change: ArrowLeftRight,
    urgency_language: Timer,
    secrecy_language: EyeOff,
    new_contact_in_request: PhoneCall,
    phone_recently_changed: PhoneCall,
    new_vendor: Store,
    cooling_off: Hourglass,
    account_recently_verified: Hourglass,
    duplicate_suspected: Copy,
    split_suspected: Split,
    urgent_due: CalendarClock,
};

const sorted = computed(() => sortSignals(props.signals));

function text(signal: Signal): string {
    const params: Params = { ...signal.params };

    for (const key of ['median_minor', 'total_minor'] as const) {
        const value = params[key];
        const currency =
            typeof params.currency === 'string'
                ? params.currency
                : props.currency;

        if (typeof value === 'number' && currency) {
            params[key.replace('_minor', '')] = pf.money({
                minor: value,
                currency,
            });
        }
    }

    for (const [name, value] of Object.entries(params)) {
        if (typeof value === 'string' && ISO_DATE.test(value)) {
            params[name] = fmt.value.date(value);
        }
    }

    const key = `guard.signals.${signal.code}`;

    if (typeof params.days === 'number' && has(key)) {
        return tc(key, params.days, params);
    }

    return tOr(key, humanize(signal.code), params);
}
</script>

<template>
    <div>
        <ul class="space-y-2" data-test="risk-signals">
            <li
                v-for="signal in sorted"
                :key="signal.code"
                class="grid grid-cols-[auto_1fr_auto] items-start gap-x-2.5 gap-y-1.5 rounded-xl border bg-muted/20 px-3 py-2 text-sm"
                :data-code="signal.code"
            >
                <component
                    :is="ICONS[signal.code] ?? TriangleAlert"
                    class="mt-0.5 size-4 shrink-0 text-warning-soft-foreground"
                    aria-hidden="true"
                />
                <span class="min-w-0">{{ text(signal) }}</span>
                <span
                    class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground tabular-nums"
                    >+{{ signal.points }}</span
                >
                <span
                    class="col-span-3 h-1 overflow-hidden rounded-full bg-muted sm:col-span-1 sm:col-start-2"
                    aria-hidden="true"
                >
                    <span
                        class="block h-full rounded-full bg-warning"
                        :style="{ width: `${signalShare(signal.points)}%` }"
                    />
                </span>
            </li>
        </ul>
        <div
            v-if="score !== null && band"
            class="mt-3 flex items-center justify-between gap-2 border-t pt-3 text-sm"
            data-test="risk-total"
        >
            <span class="text-muted-foreground">{{
                t('guard.review.total_score')
            }}</span>
            <RiskBadge :band="band" :score="score" />
        </div>
    </div>
</template>
