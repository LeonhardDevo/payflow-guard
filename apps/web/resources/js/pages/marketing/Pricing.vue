<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import IntervalToggle from '@/components/billing/IntervalToggle.vue';
import PlanCard from '@/components/billing/PlanCard.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import AuroraBackground from '@/components/fx/AuroraBackground.vue';
import Reveal from '@/components/fx/Reveal.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import FaqList from '@/components/marketing/FaqList.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { useLimits } from '@/composables/useLimits';
import { maxYearlySavings } from '@/lib/billing';
import { endpoints } from '@/lib/endpoints';
import { listIndices, t } from '@/lib/i18n';
import { register } from '@/routes';
import type { BillingCurrency, BillingInterval, Plan } from '@/types';

const props = withDefaults(
    defineProps<{
        plans?: Plan[];
        canRegister?: boolean;
        market?: { currency: BillingCurrency; country: string } | null;
        currencies?: BillingCurrency[];
    }>(),
    {
        plans: () => [],
        canRegister: true,
        market: null,
        currencies: () => [],
    },
);

const currency = computed<BillingCurrency>({
    get: () => props.market?.currency ?? props.plans[0]?.currency ?? 'USD',
    set: (value) => {
        router.get(
            endpoints.pricing().url,
            { currency: value },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['plans', 'market'],
            },
        );
    },
});
const currencyOptions = computed(() =>
    props.currencies.map((code) => ({ value: code, label: code })),
);

const page = usePage();
const limits = useLimits();
const interval = ref<BillingInterval>('year');
const authed = computed(() => Boolean(page.props.auth?.user));

const ctaHref = computed(() =>
    authed.value
        ? endpoints.settings.billing.show().url
        : props.canRegister
          ? register().url
          : endpoints.home().url,
);

const limitKeys = computed(() => {
    const keys = new Set<string>();

    props.plans.forEach((plan) =>
        Object.keys(plan.limits ?? {}).forEach((key) => keys.add(key)),
    );

    return [...keys];
});

function limitCell(plan: Plan, key: string): string {
    const value = plan.limits?.[key];

    if (value === null) {
        return t('ui.billing.unlimited');
    }

    return value === undefined ? '—' : limits.value(key, value);
}

const faq = computed(() =>
    listIndices('marketing.pricing.faq', 'q').map((index) => ({
        id: `pricing-faq-${index}`,
        question: t(`marketing.pricing.faq.${index}.q`),
        answer: t(`marketing.pricing.faq.${index}.a`),
    })),
);
</script>

<template>
    <SeoHead
        :title="t('marketing.pricing.meta_title')"
        :description="t('marketing.pricing.meta_description')"
    />

    <section class="relative overflow-hidden" aria-labelledby="pricing-hero">
        <AuroraBackground class="opacity-80" />
        <div
            class="relative mx-auto max-w-7xl px-4 pt-16 pb-10 text-center sm:px-6 lg:px-8"
        >
            <div
                class="motion-safe:animate-fade-up"
                style="animation-delay: 0ms"
            >
                <h1
                    id="pricing-hero"
                    class="mx-auto max-w-3xl font-display text-4xl font-extrabold tracking-tight text-balance sm:text-5xl"
                >
                    {{ t('marketing.pricing.title') }}
                </h1>
            </div>
            <div
                class="motion-safe:animate-fade-up"
                style="animation-delay: 60ms"
            >
                <p class="mx-auto mt-4 max-w-2xl text-lg text-muted-foreground">
                    {{ t('marketing.pricing.subtitle') }}
                </p>
            </div>
            <div
                class="motion-safe:animate-fade-up"
                style="animation-delay: 120ms"
            >
                <div
                    class="mt-8 flex flex-wrap items-center justify-center gap-3"
                >
                    <IntervalToggle
                        v-model="interval"
                        :savings="maxYearlySavings(plans)"
                    />
                    <SegmentedControl
                        v-if="currencyOptions.length > 1"
                        v-model="currency"
                        :options="currencyOptions"
                        :label="t('ui.billing.currency_switch')"
                        data-test="pricing-currency"
                    />
                </div>
            </div>
        </div>
    </section>

    <section
        class="mx-auto max-w-7xl px-4 pb-20 sm:px-6 lg:px-8"
        :aria-label="t('marketing.pricing.plans_label')"
    >
        <div
            v-if="plans.length"
            class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4"
        >
            <Reveal
                v-for="(plan, index) in plans"
                :key="plan.key"
                :delay="index * 0.05"
                class="h-full"
            >
                <PlanCard :plan="plan" :interval="interval" class="h-full">
                    <template #cta>
                        <Button
                            as-child
                            class="mt-6 h-10 w-full"
                            :variant="
                                plan.highlighted ? 'default' : 'secondary'
                            "
                        >
                            <Link
                                :href="ctaHref"
                                :data-test="`pricing-${plan.key}`"
                            >
                                {{
                                    authed
                                        ? t('ui.billing.choose_plan')
                                        : plan.price_monthly === 0
                                          ? t('ui.billing.start_free')
                                          : t('ui.billing.start_trial')
                                }}
                            </Link>
                        </Button>
                    </template>
                </PlanCard>
            </Reveal>
        </div>
        <p
            v-else
            class="rounded-2xl border border-dashed p-10 text-center text-muted-foreground"
        >
            {{ t('marketing.pricing.unavailable') }}
        </p>
        <p class="mt-6 text-center text-sm text-muted-foreground">
            {{ t('marketing.pricing.note') }}
        </p>
    </section>

    <section
        v-if="plans.length"
        class="bg-muted/30 py-20"
        aria-labelledby="compare-title"
    >
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <SectionHeading
                id="compare-title"
                :title="t('marketing.pricing.compare_title')"
            />
            <div
                class="mt-10 scrollbar-thin overflow-x-auto rounded-2xl border bg-card shadow-soft focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                role="region"
                aria-labelledby="compare-title"
                tabindex="0"
            >
                <table class="w-full min-w-[40rem] text-sm">
                    <caption class="sr-only">
                        {{
                            t('marketing.pricing.compare_title')
                        }}
                    </caption>
                    <thead>
                        <tr class="border-b">
                            <th
                                scope="col"
                                class="w-1/3 px-5 py-4 text-left font-semibold"
                            >
                                {{ t('marketing.pricing.feature') }}
                            </th>
                            <th
                                v-for="plan in plans"
                                :key="plan.key"
                                scope="col"
                                class="px-4 py-4 text-center font-display font-bold"
                            >
                                {{ plan.name }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="key in limitKeys" :key="key">
                            <th
                                scope="row"
                                class="px-5 py-3 text-left font-medium"
                            >
                                {{ limits.label(key) }}
                            </th>
                            <td
                                v-for="plan in plans"
                                :key="plan.key"
                                class="px-4 py-3 text-center tabular-nums"
                            >
                                {{ limitCell(plan, key) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section
        class="mx-auto max-w-3xl px-4 py-20 sm:px-6"
        aria-labelledby="pricing-faq"
    >
        <SectionHeading
            id="pricing-faq"
            :title="t('marketing.pricing.faq_title')"
        />
        <div class="mt-10">
            <FaqList :items="faq" />
        </div>
    </section>

    <CtaBand
        :title="t('marketing.final_cta.title')"
        :description="t('marketing.final_cta.subtitle')"
    >
        <Button as-child size="lg" class="h-12 px-6 text-base">
            <Link :href="ctaHref">
                {{
                    authed
                        ? t('marketing.nav.dashboard')
                        : t('marketing.hero.cta_primary')
                }}
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </Button>
    </CtaBand>
</template>
