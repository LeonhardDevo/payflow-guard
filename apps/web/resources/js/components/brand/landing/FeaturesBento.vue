<script setup lang="ts">
import {
    BellRing,
    Bot,
    CircleCheck,
    Gauge,
    Landmark,
    ShieldAlert,
    Timer,
    UsersRound,
} from '@lucide/vue';
import BentoCard from '@/components/fx/BentoCard.vue';
import BentoGrid from '@/components/fx/BentoGrid.vue';
import Reveal from '@/components/fx/Reveal.vue';
import { t } from '@/lib/i18n';

const ladder = [
    {
        key: 'friendly',
        tone: 'bg-success-soft text-success-soft-foreground',
        day: '+1',
    },
    {
        key: 'firm',
        tone: 'bg-warning-soft text-warning-soft-foreground',
        day: '+7',
    },
    {
        key: 'final',
        tone: 'bg-destructive-soft text-destructive-soft-foreground',
        day: '+14',
    },
] as const;

const risks = [
    { name: 'Blue Fjord Ltd', value: 81, tone: 'bg-destructive' },
    { name: 'Kestrel Media', value: 46, tone: 'bg-warning' },
    { name: 'Orchid Labs', value: 12, tone: 'bg-success' },
];

const payers = [
    { name: 'Blue Fjord Ltd', days: [12, 18, 22, 25, 31] },
    { name: 'Kestrel Media', days: [4, 9, 7, 11, 14] },
];
</script>

<template>
    <BentoGrid>
        <Reveal class="lg:col-span-4">
            <BentoCard
                class="h-full"
                :span="4"
                :icon="BellRing"
                :title="t('marketing.features.reminders.title')"
                :description="t('marketing.features.reminders.body')"
            >
                <template #visual>
                    <div
                        class="bg-dot-pattern flex h-full flex-col justify-center gap-2.5 p-6"
                        aria-hidden="true"
                    >
                        <div
                            v-for="(step, index) in ladder"
                            :key="step.key"
                            class="flex items-center gap-3 rounded-2xl border bg-card px-4 py-2.5 shadow-soft transition-transform duration-300 group-hover/spot:translate-x-1"
                            :style="{ marginLeft: `${index * 1.5}rem` }"
                        >
                            <span
                                :class="[
                                    'rounded-full px-2 py-0.5 text-xs font-semibold',
                                    step.tone,
                                ]"
                            >
                                {{
                                    t(
                                        `marketing.features.reminders.tones.${step.key}`,
                                    )
                                }}
                            </span>
                            <span
                                class="truncate text-sm text-muted-foreground"
                                >{{
                                    t(
                                        `marketing.features.reminders.samples.${step.key}`,
                                    )
                                }}</span
                            >
                            <span
                                class="ml-auto shrink-0 font-mono text-xs text-muted-foreground"
                                >{{ step.day }}d</span
                            >
                        </div>
                    </div>
                </template>
            </BentoCard>
        </Reveal>

        <Reveal :delay="0.05" class="lg:col-span-2">
            <BentoCard
                class="h-full"
                :span="2"
                tone="warning"
                :icon="Gauge"
                :title="t('marketing.features.probability.title')"
                :description="t('marketing.features.probability.body')"
            >
                <template #visual>
                    <ul
                        class="flex h-full flex-col justify-center gap-3 p-6"
                        aria-hidden="true"
                    >
                        <li
                            v-for="risk in risks"
                            :key="risk.name"
                            class="space-y-1"
                        >
                            <div class="flex justify-between text-xs">
                                <span class="font-medium">{{ risk.name }}</span>
                                <span class="font-bold tabular-nums"
                                    >{{ risk.value }}%</span
                                >
                            </div>
                            <span
                                class="block h-1.5 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    :class="[
                                        'block h-full rounded-full',
                                        risk.tone,
                                    ]"
                                    :style="{ width: `${risk.value}%` }"
                                />
                            </span>
                        </li>
                    </ul>
                </template>
            </BentoCard>
        </Reveal>

        <Reveal :delay="0.1" class="lg:col-span-2">
            <BentoCard
                class="h-full"
                :span="2"
                tone="info"
                :icon="Timer"
                :title="t('marketing.features.chronic.title')"
                :description="t('marketing.features.chronic.body')"
            >
                <template #visual>
                    <ul
                        class="flex h-full flex-col justify-center gap-4 p-6"
                        aria-hidden="true"
                    >
                        <li
                            v-for="payer in payers"
                            :key="payer.name"
                            class="flex items-end gap-3"
                        >
                            <span class="w-24 truncate text-xs font-medium">{{
                                payer.name
                            }}</span>
                            <span class="flex h-10 flex-1 items-end gap-1">
                                <span
                                    v-for="(value, index) in payer.days"
                                    :key="index"
                                    class="flex-1 rounded-t bg-info/60"
                                    :style="{
                                        height: `${(value / 31) * 100}%`,
                                    }"
                                />
                            </span>
                        </li>
                    </ul>
                </template>
            </BentoCard>
        </Reveal>

        <Reveal :delay="0.15" class="lg:col-span-2">
            <BentoCard
                class="h-full"
                :span="2"
                tone="destructive"
                :icon="Landmark"
                :title="t('marketing.features.guard.title')"
                :description="t('marketing.features.guard.body')"
            >
                <template #visual>
                    <div
                        class="flex h-full flex-col justify-center gap-2 p-6 font-mono text-xs"
                        aria-hidden="true"
                    >
                        <p
                            class="rounded-lg bg-destructive-soft px-3 py-2 text-destructive-soft-foreground line-through decoration-destructive/40"
                        >
                            IBAN DE89 3704 0044 0532 0130 00
                        </p>
                        <p
                            class="rounded-lg bg-success-soft px-3 py-2 text-success-soft-foreground"
                        >
                            IBAN GB33 BUKB 2020 1555 5555 55
                        </p>
                        <p
                            class="mt-1 flex items-center gap-1.5 font-sans font-semibold text-warning-soft-foreground"
                        >
                            <ShieldAlert class="size-4" />
                            {{ t('marketing.features.guard.blocked') }}
                        </p>
                    </div>
                </template>
            </BentoCard>
        </Reveal>

        <Reveal :delay="0.2" class="lg:col-span-2">
            <BentoCard
                class="h-full"
                :span="2"
                tone="ai"
                :icon="UsersRound"
                :title="t('marketing.features.approval.title')"
                :description="t('marketing.features.approval.body')"
            >
                <template #visual>
                    <div
                        class="flex h-full items-center justify-center gap-4 p-6"
                        aria-hidden="true"
                    >
                        <div
                            v-for="person in ['AK', 'MV']"
                            :key="person"
                            class="flex flex-col items-center gap-2"
                        >
                            <span
                                class="relative inline-flex size-12 items-center justify-center rounded-2xl bg-ai-soft font-display font-bold text-ai-soft-foreground"
                            >
                                {{ person }}
                                <CircleCheck
                                    class="absolute -right-1.5 -bottom-1.5 size-5 rounded-full bg-card text-success"
                                />
                            </span>
                            <span class="text-xs text-muted-foreground">{{
                                t('marketing.features.approval.approved')
                            }}</span>
                        </div>
                        <span
                            class="rounded-xl border-2 border-dashed border-success/50 px-3 py-2 text-center font-display text-sm font-bold text-success-soft-foreground"
                        >
                            $24,900
                        </span>
                    </div>
                </template>
            </BentoCard>
        </Reveal>

        <Reveal :delay="0.25" class="md:col-span-2 lg:col-span-6">
            <BentoCard
                class="h-full"
                :span="6"
                tone="ai"
                :icon="Bot"
                :title="t('marketing.features.assistant.title')"
                :description="t('marketing.features.assistant.body')"
            >
                <template #visual>
                    <div
                        class="grid h-full grid-cols-1 gap-3 p-6 sm:grid-cols-2"
                        aria-hidden="true"
                    >
                        <div
                            class="self-start justify-self-end rounded-2xl rounded-tr-md bg-primary px-4 py-2.5 text-sm text-primary-foreground shadow-soft"
                        >
                            {{ t('marketing.features.assistant.question') }}
                        </div>
                        <div
                            class="rounded-2xl rounded-tl-md border bg-card px-4 py-3 text-sm shadow-soft sm:col-start-1 sm:row-start-2"
                        >
                            <p>
                                {{ t('marketing.features.assistant.answer')
                                }}<sup
                                    class="ml-0.5 rounded-full bg-ai-soft px-1.5 text-[0.625rem] font-bold text-ai-soft-foreground"
                                    >1</sup
                                >
                            </p>
                            <p
                                class="mt-2 inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs"
                            >
                                <span
                                    class="inline-flex size-3.5 items-center justify-center rounded-full bg-ai text-[0.5rem] font-bold text-ai-foreground"
                                    >1</span
                                >
                                {{ t('marketing.features.assistant.source') }}
                            </p>
                        </div>
                    </div>
                </template>
            </BentoCard>
        </Reveal>
    </BentoGrid>
</template>
