<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CircleCheck, Clock, Hourglass } from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import PublicFooter from '@/components/pf/public/PublicFooter.vue';
import { Spinner } from '@/components/ui/spinner';
import { applyLocale, t } from '@/lib/i18n';
import { formatMoney } from '@/lib/pf/money';
import type {
    InvoiceDisplayStatus,
    InvoiceStatus,
    Money,
    PaymentThanksProps,
} from '@/types/payflow';

const props = defineProps<PaymentThanksProps>();

const status = ref<InvoiceStatus>(props.invoice.status);
const display = ref<InvoiceDisplayStatus>(props.invoice.display_status);
const balance = ref<Money>(props.invoice.balance_due);
const attempts = ref(0);

const paid = computed(() => status.value === 'paid');
const timedOut = computed(() => !paid.value && attempts.value >= 30);

const { pause } = useIntervalFn(
    async () => {
        attempts.value += 1;

        try {
            const response = await fetch(props.status_url, {
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                const data = (await response.json()) as {
                    status: InvoiceStatus;
                    display_status: InvoiceDisplayStatus;
                    balance_due: Money;
                };
                status.value = data.status;
                display.value = data.display_status;
                balance.value = data.balance_due;
            }
        } catch {
            return;
        }

        if (paid.value || attempts.value >= 30) {
            pause();
        }
    },
    2000,
    { immediate: !['paid'].includes(props.invoice.status) },
);

onMounted(() => {
    void applyLocale(props.invoice.locale);
});
</script>

<template>
    <Head :title="t('pf.public.thanks_title')">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <div
        class="relative z-10 mx-auto flex w-full max-w-lg flex-1 flex-col gap-6 px-5 py-10 sm:px-8"
    >
        <section
            class="rounded-3xl border bg-card p-8 text-center shadow-soft"
            data-test="payment-thanks"
            :data-status="status"
            aria-live="polite"
        >
            <span
                :class="[
                    'mx-auto inline-flex size-14 items-center justify-center rounded-2xl',
                    paid
                        ? 'bg-success-soft text-success-soft-foreground'
                        : 'bg-info-soft text-info-soft-foreground',
                ]"
            >
                <CircleCheck v-if="paid" class="size-7" aria-hidden="true" />
                <Clock v-else-if="timedOut" class="size-7" aria-hidden="true" />
                <Hourglass v-else class="size-7" aria-hidden="true" />
            </span>
            <h1 class="mt-4 text-xl font-semibold tracking-tight">
                {{
                    paid
                        ? t('pf.public.thanks_paid')
                        : timedOut
                          ? t('pf.public.thanks_processing')
                          : t('pf.public.thanks_waiting')
                }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    paid
                        ? t('pf.public.thanks_paid_body', {
                              number: invoice.number,
                              company: company_name,
                          })
                        : timedOut
                          ? t('pf.public.thanks_processing_body')
                          : t('pf.public.thanks_waiting_body')
                }}
            </p>
            <p
                v-if="!paid && !timedOut"
                class="mt-4 inline-flex items-center gap-2 text-xs text-muted-foreground"
            >
                <Spinner class="size-3.5" />{{ t('pf.public.checking') }}
            </p>
            <dl
                class="mt-6 grid grid-cols-2 gap-3 rounded-2xl bg-muted/40 p-4 text-sm"
            >
                <div>
                    <dt class="text-xs text-muted-foreground">
                        {{ t('pf.invoice.number') }}
                    </dt>
                    <dd class="font-mono">{{ invoice.number }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        {{ t('pf.invoice.balance_due') }}
                    </dt>
                    <dd class="tabular-nums">
                        {{ formatMoney(balance, invoice.locale) }}
                    </dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('pf.invoice.status') }}
                    </dt>
                    <dd>{{ t(`pf.status.${display}`) }}</dd>
                </div>
            </dl>
        </section>

        <PublicFooter
            :legal="legal"
            :company="company_name"
            :hide-choices="paid"
        />
    </div>
</template>
