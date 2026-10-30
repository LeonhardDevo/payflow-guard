<script setup lang="ts">
import { Check, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import BorderBeam from '@/components/fx/BorderBeam.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { useLimits } from '@/composables/useLimits';
import { monthlyEquivalent, yearlySavings } from '@/lib/billing';
import type { PlanAction } from '@/lib/billing';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BillingInterval, Plan } from '@/types';

const props = withDefaults(
    defineProps<{
        plan: Plan;
        interval: BillingInterval;
        action?: PlanAction | 'signup' | null;
        processing?: boolean;
        disabled?: boolean;
    }>(),
    { action: null, processing: false, disabled: false },
);

const emit = defineEmits<{ select: [plan: Plan] }>();

const fmt = useFormat();
const limits = useLimits();

const price = computed(() => monthlyEquivalent(props.plan, props.interval));
const isFree = computed(
    () => props.plan.price_monthly === 0 && props.plan.price_yearly === 0,
);
const savings = computed(() => yearlySavings(props.plan));

const ctaLabel = computed(() => {
    switch (props.action) {
        case 'current':
            return t('ui.billing.current_plan');
        case 'upgrade':
            return t('ui.billing.upgrade_to', { plan: props.plan.name });
        case 'downgrade':
            return t('ui.billing.downgrade_to', { plan: props.plan.name });
        case 'switch-interval':
            return props.interval === 'year'
                ? t('ui.billing.switch_yearly')
                : t('ui.billing.switch_monthly');
        case 'signup':
            return isFree.value
                ? t('ui.billing.start_free')
                : t('ui.billing.start_trial');
        default:
            return '';
    }
});

const limitEntries = computed(() =>
    props.plan.features.length ? [] : Object.entries(props.plan.limits ?? {}),
);
</script>

<template>
    <article
        :class="
            cn(
                'relative flex h-full flex-col rounded-3xl border bg-card p-6 shadow-soft transition-shadow hover:shadow-lifted',
                plan.highlighted
                    ? 'border-primary/50 ring-1 ring-primary/30'
                    : '',
            )
        "
        :aria-labelledby="`plan-${plan.key}-name`"
        :data-test="`plan-card-${plan.key}`"
    >
        <BorderBeam v-if="plan.highlighted" :duration="10" />
        <div class="flex items-center justify-between gap-2">
            <h3
                :id="`plan-${plan.key}-name`"
                class="font-display text-lg font-bold"
            >
                {{ plan.name }}
            </h3>
            <span
                v-if="plan.highlighted"
                class="inline-flex items-center gap-1 rounded-full bg-primary px-2.5 py-1 text-xs font-semibold text-primary-foreground"
            >
                <Sparkles class="size-3" aria-hidden="true" />
                {{ t('ui.billing.popular') }}
            </span>
        </div>
        <p class="mt-1.5 min-h-10 text-sm text-muted-foreground">
            {{ plan.description }}
        </p>

        <div class="mt-5 flex items-baseline gap-1.5">
            <span
                class="font-display text-4xl font-extrabold tracking-tight tabular-nums"
            >
                {{
                    fmt.money(price, plan.currency, {
                        fractionDigits: Number.isInteger(price) ? 0 : 2,
                    })
                }}
            </span>
            <span v-if="!isFree" class="text-sm text-muted-foreground">{{
                t('ui.billing.per_month')
            }}</span>
        </div>
        <p class="mt-1 min-h-5 text-xs text-muted-foreground">
            <template v-if="isFree">{{
                t('ui.billing.free_forever')
            }}</template>
            <template v-else-if="interval === 'year'">
                {{
                    t('ui.billing.billed_yearly', {
                        amount: fmt.money(plan.price_yearly, plan.currency),
                    })
                }}
                <span v-if="savings > 0" class="font-semibold text-primary">
                    ·
                    {{
                        t('ui.billing.save', { percent: fmt.percent(savings) })
                    }}
                </span>
            </template>
            <template v-else>{{ t('ui.billing.billed_monthly') }}</template>
        </p>

        <div v-if="action" class="mt-6">
            <Button
                class="h-10 w-full"
                :variant="
                    action === 'current'
                        ? 'outline'
                        : plan.highlighted || action === 'signup'
                          ? 'default'
                          : 'secondary'
                "
                :disabled="disabled || processing || action === 'current'"
                :data-test="`plan-subscribe-${plan.key}`"
                @click="emit('select', plan)"
            >
                <Spinner v-if="processing" />
                {{ ctaLabel }}
            </Button>
        </div>
        <slot name="cta" />

        <ul class="mt-6 space-y-2.5 border-t pt-6 text-sm">
            <li
                v-for="feature in plan.features"
                :key="feature"
                class="flex gap-2.5"
            >
                <Check
                    class="mt-0.5 size-4 shrink-0 text-primary"
                    aria-hidden="true"
                />
                <span>{{ feature }}</span>
            </li>
            <li
                v-for="[key, value] in limitEntries"
                :key="key"
                class="flex gap-2.5 text-muted-foreground"
            >
                <Check
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground/70"
                    aria-hidden="true"
                />
                <span>
                    {{ limits.label(key) }}:
                    <strong class="font-semibold text-foreground">{{
                        value === null
                            ? t('ui.billing.unlimited')
                            : limits.value(key, value)
                    }}</strong>
                </span>
            </li>
        </ul>
    </article>
</template>
