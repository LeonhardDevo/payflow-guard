<script setup lang="ts">
import {
    BellRing,
    FileSpreadsheet,
    Landmark,
    MessageCircle,
    ShieldCheck,
    Wallet,
} from '@lucide/vue';
import { ref } from 'vue';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import AnimatedBeam from '@/components/fx/AnimatedBeam.vue';
import Reveal from '@/components/fx/Reveal.vue';
import { t } from '@/lib/i18n';

const steps = ['connect', 'remind', 'predict', 'protect'] as const;

const container = ref<HTMLElement | null>(null);
const hub = ref<HTMLElement | null>(null);
const inInvoices = ref<HTMLElement | null>(null);
const inBank = ref<HTMLElement | null>(null);
const outReminders = ref<HTMLElement | null>(null);
const outTelegram = ref<HTMLElement | null>(null);
const outGuard = ref<HTMLElement | null>(null);
const outCash = ref<HTMLElement | null>(null);

const node =
    'relative z-10 flex size-14 items-center justify-center rounded-2xl border bg-card shadow-lifted';
</script>

<template>
    <div class="grid grid-cols-1 items-center gap-12 lg:grid-cols-2">
        <ol class="relative space-y-6">
            <li v-for="(step, index) in steps" :key="step">
                <Reveal :delay="index * 0.08">
                    <div class="flex gap-4">
                        <span
                            class="bg-brand-gradient inline-flex size-10 shrink-0 items-center justify-center rounded-xl font-display text-lg font-extrabold text-white shadow-soft"
                        >
                            {{ index + 1 }}
                        </span>
                        <div class="space-y-1 pt-0.5">
                            <h3 class="font-display text-lg font-bold">
                                {{ t(`marketing.how.steps.${step}.title`) }}
                            </h3>
                            <p class="text-muted-foreground">
                                {{ t(`marketing.how.steps.${step}.body`) }}
                            </p>
                        </div>
                    </div>
                </Reveal>
            </li>
        </ol>

        <div
            ref="container"
            class="relative mx-auto flex h-80 w-full max-w-lg items-center justify-between overflow-hidden rounded-3xl border bg-card/60 px-6 py-8 shadow-soft sm:px-10"
            role="img"
            :aria-label="t('marketing.how.diagram_label')"
        >
            <div
                class="bg-grid-pattern mask-radial absolute inset-0 opacity-60"
                aria-hidden="true"
            />
            <div class="flex flex-col gap-10">
                <div
                    ref="inInvoices"
                    :class="node"
                    :title="t('marketing.how.nodes.invoices')"
                >
                    <FileSpreadsheet
                        class="size-6 text-info"
                        aria-hidden="true"
                    />
                </div>
                <div
                    ref="inBank"
                    :class="node"
                    :title="t('marketing.how.nodes.bank')"
                >
                    <Landmark
                        class="size-6 text-muted-foreground"
                        aria-hidden="true"
                    />
                </div>
            </div>

            <div
                ref="hub"
                class="relative z-10 flex size-20 items-center justify-center rounded-3xl border bg-card shadow-lifted ring-4 ring-primary/15"
            >
                <AppLogoMark class="size-11" />
            </div>

            <div class="flex flex-col gap-4">
                <div
                    ref="outReminders"
                    :class="node"
                    :title="t('marketing.how.nodes.reminders')"
                >
                    <BellRing class="size-6 text-warning" aria-hidden="true" />
                </div>
                <div
                    ref="outTelegram"
                    :class="node"
                    :title="t('marketing.how.nodes.telegram')"
                >
                    <MessageCircle
                        class="size-6 text-[#2AABEE]"
                        aria-hidden="true"
                    />
                </div>
                <div
                    ref="outGuard"
                    :class="node"
                    :title="t('marketing.how.nodes.guard')"
                >
                    <ShieldCheck class="size-6 text-ai" aria-hidden="true" />
                </div>
                <div
                    ref="outCash"
                    :class="node"
                    :title="t('marketing.how.nodes.cash')"
                >
                    <Wallet class="size-6 text-primary" aria-hidden="true" />
                </div>
            </div>

            <AnimatedBeam
                :container-ref="container"
                :from-ref="inInvoices"
                :to-ref="hub"
                :curvature="-30"
                :duration="4"
            />
            <AnimatedBeam
                :container-ref="container"
                :from-ref="inBank"
                :to-ref="hub"
                :curvature="30"
                :duration="4.5"
                :delay="0.6"
            />
            <AnimatedBeam
                :container-ref="container"
                :from-ref="hub"
                :to-ref="outReminders"
                :curvature="-40"
                :duration="5"
            />
            <AnimatedBeam
                :container-ref="container"
                :from-ref="hub"
                :to-ref="outTelegram"
                :curvature="-12"
                :duration="5.5"
                :delay="0.4"
            />
            <AnimatedBeam
                :container-ref="container"
                :from-ref="hub"
                :to-ref="outGuard"
                :curvature="12"
                :duration="5"
                :delay="0.8"
            />
            <AnimatedBeam
                :container-ref="container"
                :from-ref="hub"
                :to-ref="outCash"
                :curvature="40"
                :duration="4.5"
                :delay="1.2"
            />
        </div>
    </div>
</template>
