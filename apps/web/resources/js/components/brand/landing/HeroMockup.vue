<script setup lang="ts">
import {
    BadgeCheck,
    BellRing,
    CalendarClock,
    FilePen,
    Send,
    ShieldCheck,
    TrendingUp,
} from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import type { StatusTone } from '@/components/common/StatusBadge.vue';
import BorderBeam from '@/components/fx/BorderBeam.vue';
import NumberTicker from '@/components/fx/NumberTicker.vue';
import { useFormat } from '@/composables/useFormat';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { t } from '@/lib/i18n';

const fmt = useFormat();
const reduced = useReducedMotion();

const stages = [
    { key: 'draft', tone: 'neutral', icon: FilePen },
    { key: 'sent', tone: 'info', icon: Send },
    { key: 'reminded', tone: 'warning', icon: BellRing },
    { key: 'paid', tone: 'success', icon: BadgeCheck },
] as const satisfies ReadonlyArray<{
    key: string;
    tone: StatusTone;
    icon: unknown;
}>;

const stage = ref(0);
const baseCollected = 182_400;
const invoiceAmount = 4_820;
const collected = ref(baseCollected);

const { pause, resume } = useIntervalFn(
    () => {
        stage.value = (stage.value + 1) % stages.length;
    },
    2200,
    { immediate: false },
);

onMounted(() => {
    if (reduced.value) {
        stage.value = stages.length - 1;
    } else {
        resume();
    }
});

watch(reduced, (value) => {
    if (value) {
        pause();
        stage.value = stages.length - 1;
    }
});

const MAX_LOOPS = 12;

watch(stage, (value) => {
    if (value !== stages.length - 1) {
        return;
    }

    collected.value =
        collected.value >= baseCollected + invoiceAmount * MAX_LOOPS
            ? baseCollected
            : collected.value + invoiceAmount;
});

const current = computed(() => stages[stage.value]);
const paid = computed(() => current.value.key === 'paid');
const money = (n: number) => fmt.value.money(n, 'USD', { fractionDigits: 0 });

const bars = [38, 52, 44, 61, 57, 72, 66, 84];
</script>

<template>
    <div class="relative mx-auto w-full max-w-xl" aria-hidden="true">
        <div
            class="bg-brand-gradient absolute -inset-8 rounded-[2.5rem] opacity-20 blur-3xl"
        />

        <div
            class="relative overflow-hidden rounded-3xl border bg-card/90 shadow-lifted backdrop-blur-xl"
        >
            <BorderBeam :duration="14" :size="220" />
            <div class="flex items-center gap-2 border-b px-4 py-3">
                <AppLogoMark class="size-6" />
                <span class="text-sm font-semibold">{{
                    t('marketing.hero.mock.title')
                }}</span>
                <span class="ml-auto flex gap-1.5">
                    <span
                        class="size-2.5 rounded-full bg-muted-foreground/25"
                    />
                    <span
                        class="size-2.5 rounded-full bg-muted-foreground/25"
                    />
                    <span
                        class="size-2.5 rounded-full bg-muted-foreground/25"
                    />
                </span>
            </div>

            <div
                class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-[1.15fr_1fr] sm:p-5"
            >
                <div class="rounded-2xl border bg-background/70 p-4">
                    <p class="text-xs font-medium text-muted-foreground">
                        {{ t('marketing.hero.mock.collected') }}
                    </p>
                    <p
                        class="mt-1 font-display text-3xl font-extrabold tracking-tight"
                    >
                        <NumberTicker
                            :value="collected"
                            :duration="900"
                            :format="money"
                        />
                    </p>
                    <p
                        class="mt-1 inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-xs font-semibold text-success-soft-foreground"
                    >
                        <TrendingUp class="size-3" />
                        +18.4%
                    </p>
                    <div class="mt-4 flex h-16 items-end gap-1.5">
                        <span
                            v-for="(height, index) in bars"
                            :key="index"
                            class="flex-1 rounded-t-md bg-primary/25 transition-all duration-700 last:bg-primary"
                            :style="{
                                height: `${index === bars.length - 1 && paid ? 100 : height}%`,
                            }"
                        />
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="rounded-2xl border bg-background/70 p-3">
                        <p class="text-xs font-medium text-muted-foreground">
                            {{ t('marketing.hero.mock.risk') }}
                        </p>
                        <div class="mt-2 flex items-center gap-2">
                            <span
                                class="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full w-[72%] rounded-full bg-gradient-to-r from-warning to-destructive"
                                />
                            </span>
                            <span class="text-sm font-bold tabular-nums"
                                >72%</span
                            >
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ t('marketing.hero.mock.risk_client') }}
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border border-warning/40 bg-warning-soft p-3 text-warning-soft-foreground"
                    >
                        <p
                            class="flex items-start gap-1.5 text-xs font-semibold"
                        >
                            <ShieldCheck class="mt-px size-3.5 shrink-0" />
                            <span class="min-w-0">{{
                                t('marketing.hero.mock.guard')
                            }}</span>
                        </p>
                        <p class="mt-1 font-mono text-[0.6875rem] opacity-90">
                            DE89 3704 … → GB33 BUKB …
                        </p>
                    </div>
                </div>
            </div>

            <div class="border-t bg-muted/30 p-4 sm:p-5">
                <div
                    class="rounded-2xl border bg-card p-4 shadow-soft transition-all duration-500"
                    :class="paid ? 'border-success/40' : ''"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs text-muted-foreground">
                                INV-1042 · Northwind Studio
                            </p>
                            <p
                                class="mt-0.5 font-display text-xl font-bold tabular-nums"
                            >
                                {{ money(invoiceAmount) }}
                            </p>
                        </div>
                        <Transition
                            mode="out-in"
                            enter-active-class="transition duration-300"
                            enter-from-class="opacity-0 -translate-y-1"
                            leave-active-class="transition duration-200"
                            leave-to-class="opacity-0 translate-y-1"
                        >
                            <StatusBadge
                                :key="current.key"
                                :tone="current.tone"
                                :icon="current.icon"
                                :label="
                                    t(
                                        `marketing.hero.mock.status.${current.key}`,
                                    )
                                "
                            />
                        </Transition>
                    </div>
                    <ol class="mt-4 grid grid-cols-4 gap-1.5">
                        <li
                            v-for="(item, index) in stages"
                            :key="item.key"
                            class="space-y-1.5"
                        >
                            <span
                                class="block h-1.5 rounded-full transition-colors duration-500"
                                :class="
                                    index <= stage
                                        ? item.key === 'paid'
                                            ? 'bg-success'
                                            : 'bg-primary'
                                        : 'bg-muted'
                                "
                            />
                            <span
                                class="block truncate text-[0.6875rem] text-muted-foreground"
                                >{{
                                    t(`marketing.hero.mock.status.${item.key}`)
                                }}</span
                            >
                        </li>
                    </ol>
                    <p
                        class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <CalendarClock class="size-3.5" />
                        {{ t(`marketing.hero.mock.hint.${current.key}`) }}
                    </p>
                </div>
            </div>
        </div>

        <Transition
            enter-active-class="transition duration-500 ease-out"
            enter-from-class="opacity-0 translate-y-3 scale-95"
            leave-active-class="transition duration-300"
            leave-to-class="opacity-0"
        >
            <div
                v-if="paid"
                class="glass absolute -bottom-6 -left-2 flex max-w-[16rem] items-center gap-3 rounded-2xl border p-3 shadow-lifted sm:-left-10"
            >
                <span
                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-[#2AABEE] text-white"
                >
                    <Send class="size-4" />
                </span>
                <span class="min-w-0 text-xs">
                    <span class="block font-semibold">{{
                        t('marketing.hero.mock.toast_title')
                    }}</span>
                    <span class="block truncate text-muted-foreground">{{
                        t('marketing.hero.mock.toast_body', {
                            amount: money(invoiceAmount),
                        })
                    }}</span>
                </span>
            </div>
        </Transition>
    </div>
</template>
