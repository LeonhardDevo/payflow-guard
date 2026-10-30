<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import {
    CalendarClock,
    CreditCard,
    ExternalLink,
    FlaskConical,
    Gauge,
    Globe,
    Info,
    Layers,
    PartyPopper,
    RotateCcw,
    CircleX,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import IntervalToggle from '@/components/billing/IntervalToggle.vue';
import PlanCard from '@/components/billing/PlanCard.vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import UsageMeter from '@/components/common/UsageMeter.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { useLimits } from '@/composables/useLimits';
import {
    maxYearlySavings,
    monthlyEquivalent,
    planAction,
    subscriptionTone,
} from '@/lib/billing';
import { endpoints } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';
import type {
    BillingCapabilities,
    BillingInterval,
    BillingMarket,
    Plan,
    Subscription,
    UsageMap,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'ui.settings.title',
                href: endpoints.settings.workspace.show(),
            },
            {
                title: 'ui.settings.nav.billing',
                href: endpoints.settings.billing.show(),
            },
        ],
    },
});

const props = withDefaults(
    defineProps<{
        subscription: Subscription | null;
        plans: Plan[];
        usage?: UsageMap;
        currentPlan?: string | null;
        checkout?: 'success' | 'cancelled' | 'updated' | null;
        market?: BillingMarket | null;
        capabilities?: BillingCapabilities;
    }>(),
    {
        usage: () => ({}),
        currentPlan: null,
        checkout: null,
        market: null,
        capabilities: () => ({ portal: false, manage: false }),
    },
);

const page = usePage();
const fmt = useFormat();
const limits = useLimits();
const { can } = useCan();
const canManage = computed(() => can('billing.manage', 'workspace.manage'));

const interval = ref<BillingInterval>(props.subscription?.interval ?? 'month');
const pendingPlan = ref<string | null>(null);
const portalBusy = ref(false);
const lifecycleBusy = ref(false);
const cancelOpen = ref(false);
const changeTarget = ref<Plan | null>(null);
const changeOpen = computed({
    get: () => changeTarget.value !== null,
    set: (value: boolean) => {
        if (!value) {
            changeTarget.value = null;
        }
    },
});

const liveSubscription = computed(
    () =>
        props.subscription !== null &&
        ['trialing', 'active', 'past_due'].includes(props.subscription.status),
);
const canChangeInPlace = computed(
    () => liveSubscription.value && props.capabilities.manage,
);
const marketLine = computed(() =>
    props.market
        ? t('ui.billing.market_line', {
              country: props.market.country_name,
              currency: props.market.currency,
              gateway: props.market.gateway_name,
          })
        : null,
);

const currentPlanInfo = computed<Plan | null>(() => {
    const key = props.currentPlan ?? props.subscription?.plan ?? 'free';

    return props.plans.find((plan) => plan.key === key) ?? null;
});

const activating = ref(false);
const updating = ref(false);
const poll = usePoll(
    2000,
    { only: ['subscription', 'currentPlan', 'usage'] },
    { autoStart: false },
);
let pollTimeout: ReturnType<typeof setTimeout> | undefined;

function stopActivation(): void {
    poll.stop();
    activating.value = false;
    updating.value = false;
    clearTimeout(pollTimeout);
}

onMounted(() => {
    if (props.checkout === 'updated') {
        updating.value = true;
        poll.start();
        pollTimeout = setTimeout(stopActivation, 8_000);

        return;
    }

    if (
        props.checkout === 'success' &&
        !['active', 'trialing'].includes(props.subscription?.status ?? '')
    ) {
        activating.value = true;
        poll.start();
        pollTimeout = setTimeout(stopActivation, 30_000);
    }
});

watch(
    () => props.subscription?.status,
    (status) => {
        if (
            activating.value &&
            (status === 'active' || status === 'trialing')
        ) {
            stopActivation();
        }
    },
);

onBeforeUnmount(stopActivation);

const savings = computed(() => maxYearlySavings(props.plans));
const usageEntries = computed(() => Object.entries(props.usage ?? {}));

function choose(plan: Plan): void {
    const action = planAction(
        plan,
        props.subscription,
        interval.value,
        props.currentPlan,
    );

    if (action === 'current') {
        return;
    }

    if (canChangeInPlace.value) {
        if (plan.key === 'free') {
            cancelOpen.value = true;
        } else {
            changeTarget.value = plan;
        }

        return;
    }

    if (plan.key === 'free') {
        openPortal();

        return;
    }

    router.post(
        endpoints.settings.billing.checkout().url,
        { plan: plan.key, interval: interval.value },
        {
            preserveScroll: true,
            onStart: () => {
                pendingPlan.value = plan.key;
            },
            onFinish: () => {
                pendingPlan.value = null;
            },
        },
    );
}

function lifecycle(url: string, data: Record<string, string> = {}): void {
    router.post(url, data, {
        preserveScroll: true,
        onStart: () => {
            lifecycleBusy.value = true;
        },
        onFinish: () => {
            lifecycleBusy.value = false;
            cancelOpen.value = false;
            changeTarget.value = null;
        },
    });
}

function confirmChange(): void {
    if (changeTarget.value) {
        lifecycle(endpoints.settings.billing.changePlan().url, {
            plan: changeTarget.value.key,
            interval: interval.value,
        });
    }
}

function openPortal(): void {
    router.post(
        endpoints.settings.billing.portal().url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                portalBusy.value = true;
            },
            onFinish: () => {
                portalBusy.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="t('ui.billing.title')" />

    <div
        v-if="activating"
        class="flex items-center gap-3 rounded-2xl border border-primary/30 bg-primary-soft p-4 text-sm text-primary-soft-foreground"
        role="status"
        aria-live="polite"
    >
        <Spinner class="size-4" />
        <p>{{ t('ui.billing.activating') }}</p>
    </div>
    <div
        v-else-if="updating"
        class="flex items-center gap-3 rounded-2xl border border-primary/30 bg-primary-soft p-4 text-sm text-primary-soft-foreground"
        role="status"
        aria-live="polite"
    >
        <Spinner class="size-4" />
        <p>{{ t('ui.billing.updating') }}</p>
    </div>
    <div
        v-else-if="checkout === 'success' && subscription?.status === 'active'"
        class="flex items-center gap-3 rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success-soft-foreground"
        role="status"
    >
        <PartyPopper class="size-4 shrink-0" aria-hidden="true" />
        <p>{{ t('ui.billing.activated') }}</p>
    </div>
    <div
        v-else-if="checkout === 'cancelled'"
        class="flex items-center gap-3 rounded-2xl border bg-muted/50 p-4 text-sm text-muted-foreground"
        role="status"
    >
        <Info class="size-4 shrink-0" aria-hidden="true" />
        <p>{{ t('ui.billing.checkout_cancelled') }}</p>
    </div>

    <div
        v-if="page.props.billing?.driver === 'fake'"
        class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
        role="note"
    >
        <FlaskConical class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <p>{{ t('ui.billing.test_mode_note') }}</p>
    </div>

    <SectionCard
        :icon="CreditCard"
        :title="t('ui.billing.current_title')"
        :description="t('ui.billing.current_description')"
    >
        <template #actions>
            <Button
                v-if="
                    liveSubscription &&
                    canManage &&
                    capabilities.manage &&
                    subscription?.cancel_at_period_end
                "
                variant="outline"
                :disabled="lifecycleBusy"
                data-test="billing-resume"
                @click="lifecycle(endpoints.settings.billing.resume().url)"
            >
                <Spinner v-if="lifecycleBusy" />
                <RotateCcw v-else class="size-4" aria-hidden="true" />
                {{ t('ui.billing.resume') }}
            </Button>
            <Button
                v-else-if="liveSubscription && canManage && capabilities.manage"
                variant="ghost"
                :disabled="lifecycleBusy"
                data-test="billing-cancel"
                @click="cancelOpen = true"
            >
                <CircleX class="size-4" aria-hidden="true" />
                {{ t('ui.billing.cancel') }}
            </Button>
            <Button
                v-if="subscription && canManage"
                variant="outline"
                :disabled="portalBusy"
                data-test="billing-portal"
                @click="openPortal"
            >
                <Spinner v-if="portalBusy" />
                <ExternalLink v-else class="size-4" aria-hidden="true" />
                {{ t('ui.billing.manage') }}
            </Button>
        </template>

        <div
            class="grid grid-cols-1 gap-6 sm:grid-cols-[1fr_auto] sm:items-center"
        >
            <div class="space-y-2">
                <div
                    class="flex flex-wrap items-center gap-2"
                    data-test="subscription-status"
                    :data-status="subscription?.status ?? 'free'"
                    :data-plan="currentPlan ?? subscription?.plan ?? 'free'"
                >
                    <p class="font-display text-2xl font-bold">
                        {{
                            currentPlanInfo?.name ??
                            tOr('ui.plans.free', 'Free')
                        }}
                    </p>
                    <StatusBadge
                        v-if="subscription"
                        :tone="subscriptionTone(subscription.status)"
                        :label="t(`ui.billing.status.${subscription.status}`)"
                    />
                    <StatusBadge
                        v-else
                        tone="neutral"
                        :label="t('ui.billing.status.free')"
                    />
                </div>
                <p
                    v-if="subscription?.current_period_end"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <CalendarClock class="size-4" aria-hidden="true" />
                    {{
                        subscription.cancel_at_period_end
                            ? t('ui.billing.cancels_on', {
                                  date: fmt.date(
                                      subscription.current_period_end,
                                      'long',
                                  ),
                              })
                            : t('ui.billing.renews_on', {
                                  date: fmt.date(
                                      subscription.current_period_end,
                                      'long',
                                  ),
                              })
                    }}
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    {{ t('ui.billing.free_note') }}
                </p>
                <p
                    v-if="
                        subscription?.status === 'trialing' &&
                        subscription.trial_ends_at
                    "
                    class="text-sm text-muted-foreground"
                    data-test="billing-trial"
                >
                    {{
                        t('ui.billing.trial_until', {
                            date: fmt.date(subscription.trial_ends_at, 'long'),
                        })
                    }}
                </p>
                <p
                    v-if="marketLine"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                    data-test="billing-market"
                    :data-currency="market?.currency"
                >
                    <Globe class="size-4 shrink-0" aria-hidden="true" />
                    {{ marketLine }}
                </p>
                <p
                    v-if="market && !market.available"
                    class="text-sm font-medium text-warning-soft-foreground"
                    role="note"
                >
                    {{ t('ui.billing.gateway_unavailable') }}
                </p>
                <p
                    v-if="subscription?.status === 'past_due'"
                    class="text-sm font-medium text-warning-soft-foreground"
                    role="alert"
                >
                    {{ t('ui.billing.past_due_note') }}
                </p>
            </div>
            <div
                v-if="currentPlanInfo && subscription"
                class="text-left sm:text-right"
            >
                <p class="font-display text-3xl font-extrabold tabular-nums">
                    {{
                        fmt.money(
                            monthlyEquivalent(
                                currentPlanInfo,
                                subscription.interval,
                            ),
                            currentPlanInfo.currency,
                        )
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        subscription.interval === 'year'
                            ? t('ui.billing.per_month_yearly')
                            : t('ui.billing.per_month')
                    }}
                </p>
            </div>
        </div>
    </SectionCard>

    <SectionCard
        :icon="Gauge"
        :title="t('ui.billing.usage_title')"
        :description="t('ui.billing.usage_description')"
    >
        <div
            v-if="usageEntries.length"
            class="grid grid-cols-1 gap-6 sm:grid-cols-2"
        >
            <UsageMeter
                v-for="[key, entry] in usageEntries"
                :key="key"
                :label="limits.label(key)"
                :entry="entry"
                :format="(value: number) => limits.value(key, value)"
            />
        </div>
        <EmptyState
            v-else
            compact
            tone="muted"
            :icon="Gauge"
            :title="t('ui.billing.usage_empty')"
        />
    </SectionCard>

    <section aria-labelledby="plans-title" class="space-y-5">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h2
                    id="plans-title"
                    class="flex items-center gap-2 font-display text-lg font-semibold"
                >
                    <Layers
                        class="size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ t('ui.billing.plans_title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('ui.billing.plans_description') }}
                </p>
            </div>
            <IntervalToggle v-model="interval" :savings="savings" />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <PlanCard
                v-for="plan in plans"
                :key="plan.key"
                :plan="plan"
                :interval="interval"
                :action="
                    canManage
                        ? planAction(plan, subscription, interval, currentPlan)
                        : null
                "
                :processing="pendingPlan === plan.key"
                :disabled="pendingPlan !== null"
                @select="choose"
            />
        </div>
        <p v-if="!canManage" class="text-sm text-muted-foreground">
            {{ t('ui.billing.read_only') }}
        </p>
    </section>

    <ConfirmDialog
        v-model:open="cancelOpen"
        destructive
        :processing="lifecycleBusy"
        :title="t('ui.billing.cancel_confirm_title')"
        :description="
            t('ui.billing.cancel_confirm_body', {
                date: subscription?.current_period_end
                    ? fmt.date(subscription.current_period_end, 'long')
                    : '—',
            })
        "
        :confirm-label="t('ui.billing.cancel')"
        @confirm="lifecycle(endpoints.settings.billing.cancel().url)"
    />
    <ConfirmDialog
        v-model:open="changeOpen"
        :processing="lifecycleBusy"
        :title="
            t('ui.billing.change_confirm_title', {
                plan: changeTarget?.name ?? '',
            })
        "
        :description="t('ui.billing.change_confirm_body')"
        :confirm-label="t('ui.billing.change_confirm')"
        @confirm="confirmChange"
    />
</template>
