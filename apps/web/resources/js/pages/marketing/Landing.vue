<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    Bot,
    Check,
    FileLock2,
    Fingerprint,
    Globe2,
    KeyRound,
    Languages,
    Lock,
    PlayCircle,
    ScrollText,
    ShieldCheck,
    Sparkles,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import IntervalToggle from '@/components/billing/IntervalToggle.vue';
import PlanCard from '@/components/billing/PlanCard.vue';
import FeaturesBento from '@/components/brand/landing/FeaturesBento.vue';
import HeroMockup from '@/components/brand/landing/HeroMockup.vue';
import HowItWorks from '@/components/brand/landing/HowItWorks.vue';
import TelegramDemo from '@/components/brand/landing/TelegramDemo.vue';
import AuroraBackground from '@/components/fx/AuroraBackground.vue';
import Marquee from '@/components/fx/Marquee.vue';
import NumberTicker from '@/components/fx/NumberTicker.vue';
import Reveal from '@/components/fx/Reveal.vue';
import SpotlightCard from '@/components/fx/SpotlightCard.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import FaqList from '@/components/marketing/FaqList.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { maxYearlySavings } from '@/lib/billing';
import { endpoints } from '@/lib/endpoints';
import { listIndices, t } from '@/lib/i18n';
import { register } from '@/routes';
import type { BillingInterval, Plan } from '@/types';

const props = withDefaults(
    defineProps<{ canRegister?: boolean; plans?: Plan[] }>(),
    {
        canRegister: true,
        plans: () => [],
    },
);

const page = usePage();
const fmt = useFormat();
const authed = computed(() => Boolean(page.props.auth?.user));

const primaryCta = computed(() =>
    authed.value
        ? {
              href: endpoints.dashboard().url,
              label: t('marketing.nav.dashboard'),
          }
        : {
              href: props.canRegister
                  ? register().url
                  : endpoints.pricing().url,
              label: t('marketing.hero.cta_primary'),
          },
);

const capabilities = [
    'reminders',
    'risk',
    'guard',
    'approvals',
    'telegram',
    'assistant',
    'audit',
    'bilingual',
] as const;
const capabilityIcons = {
    reminders: Sparkles,
    risk: BadgeCheck,
    guard: ShieldCheck,
    approvals: UsersRound,
    telegram: Bot,
    assistant: Bot,
    audit: ScrollText,
    bilingual: Languages,
};

const stats = computed(() => [
    { key: 'overdue', value: 59, format: (n: number) => `${Math.round(n)}%` },
    {
        key: 'owed',
        value: 17.7,
        format: (n: number) =>
            fmt.value.money(n * 1000, 'USD', { compact: true }),
    },
    { key: 'payroll', value: 39, format: (n: number) => `${Math.round(n)}%` },
]);

const securityItems = [
    { key: 'encryption', icon: FileLock2 },
    { key: 'audit', icon: ScrollText },
    { key: 'approvals', icon: UsersRound },
    { key: 'auth', icon: Fingerprint },
    { key: 'keys', icon: KeyRound },
    { key: 'cdn', icon: Globe2 },
] as const;

const faq = computed(() =>
    listIndices('marketing.faq.items', 'q').map((index) => ({
        id: `faq-${index}`,
        question: t(`marketing.faq.items.${index}.q`),
        answer: t(`marketing.faq.items.${index}.a`),
    })),
);

const interval = ref<BillingInterval>('year');
const teaserPlans = computed(() => props.plans.slice(0, 3));
</script>

<template>
    <SeoHead
        :title="t('marketing.meta.title')"
        :description="t('marketing.meta.description')"
    />

    <section
        class="relative overflow-hidden"
        aria-labelledby="hero-title"
        data-test="landing-hero"
    >
        <AuroraBackground />
        <div
            class="relative mx-auto grid max-w-7xl grid-cols-1 items-center gap-14 px-4 pt-14 pb-24 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:px-8 lg:pt-20"
        >
            <div class="space-y-8">
                <div
                    class="motion-safe:animate-fade-up"
                    style="animation-delay: 0ms"
                >
                    <p
                        class="inline-flex items-center gap-2 rounded-full border bg-card/70 px-3 py-1.5 text-sm font-medium shadow-soft backdrop-blur"
                    >
                        <span
                            class="relative inline-flex size-2 rounded-full bg-primary"
                        >
                            <span
                                class="absolute inset-0 rounded-full bg-primary opacity-75 motion-safe:animate-ping"
                            />
                        </span>
                        {{ t('marketing.hero.eyebrow') }}
                    </p>
                </div>
                <div
                    class="motion-safe:animate-fade-up"
                    style="animation-delay: 60ms"
                >
                    <h1
                        id="hero-title"
                        class="font-display text-4xl leading-[1.05] font-extrabold tracking-tight text-balance sm:text-6xl"
                    >
                        {{ t('marketing.hero.title_start') }}
                        <span class="text-brand-gradient">{{
                            t('marketing.hero.title_highlight')
                        }}</span>
                        {{ t('marketing.hero.title_end') }}
                    </h1>
                </div>
                <div
                    class="motion-safe:animate-fade-up"
                    style="animation-delay: 120ms"
                >
                    <p
                        class="max-w-xl text-lg text-pretty text-muted-foreground"
                    >
                        {{ t('marketing.hero.subtitle') }}
                    </p>
                </div>
                <div
                    class="motion-safe:animate-fade-up"
                    style="animation-delay: 180ms"
                >
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <Button
                            as-child
                            size="lg"
                            class="h-12 px-6 text-base shadow-lifted"
                        >
                            <Link
                                :href="primaryCta.href"
                                :data-test="
                                    authed || !canRegister
                                        ? 'hero-cta'
                                        : 'cta-register'
                                "
                            >
                                {{ primaryCta.label }}
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </Button>
                        <Button
                            as-child
                            size="lg"
                            variant="outline"
                            class="h-12 bg-card/60 px-6 text-base backdrop-blur"
                        >
                            <a href="#how-it-works">
                                <PlayCircle class="size-4" aria-hidden="true" />
                                {{ t('marketing.hero.cta_secondary') }}
                            </a>
                        </Button>
                    </div>
                </div>
                <div
                    class="motion-safe:animate-fade-up"
                    style="animation-delay: 240ms"
                >
                    <ul
                        class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground"
                    >
                        <li
                            v-for="item in ['free', 'no_card', 'bilingual']"
                            :key="item"
                            class="inline-flex items-center gap-1.5"
                        >
                            <Check
                                class="size-4 text-primary"
                                aria-hidden="true"
                            />
                            {{ t(`marketing.hero.trust.${item}`) }}
                        </li>
                    </ul>
                </div>
            </div>
            <div
                class="motion-safe:animate-fade-up"
                style="animation-delay: 240ms"
            >
                <HeroMockup />
            </div>
        </div>
    </section>

    <section
        class="border-y bg-card/50 py-4"
        :aria-label="t('marketing.capabilities_label')"
    >
        <Marquee duration="45s" gap="0.75rem">
            <span
                v-for="capability in capabilities"
                :key="capability"
                class="inline-flex items-center gap-2 rounded-full border bg-background px-4 py-2 text-sm font-medium whitespace-nowrap"
            >
                <component
                    :is="capabilityIcons[capability]"
                    class="size-4 text-primary"
                    aria-hidden="true"
                />
                {{ t(`marketing.capabilities.${capability}`) }}
            </span>
        </Marquee>
    </section>

    <section
        class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8"
        aria-labelledby="problem-title"
    >
        <SectionHeading
            id="problem-title"
            :eyebrow="t('marketing.problem.eyebrow')"
            :title="t('marketing.problem.title')"
            :description="t('marketing.problem.subtitle')"
        />
        <div class="mt-14 grid grid-cols-1 gap-4 md:grid-cols-3">
            <Reveal
                v-for="(stat, index) in stats"
                :key="stat.key"
                :delay="index * 0.08"
            >
                <SpotlightCard class="h-full p-8">
                    <p
                        class="text-brand-gradient font-display text-4xl font-extrabold tracking-tight whitespace-nowrap sm:text-5xl lg:text-6xl"
                    >
                        <NumberTicker
                            :value="stat.value"
                            :decimals="stat.key === 'owed' ? 1 : 0"
                            :format="stat.format"
                        />
                    </p>
                    <p class="mt-4 text-base font-medium text-pretty">
                        {{ t(`marketing.problem.stats.${stat.key}`) }}
                    </p>
                </SpotlightCard>
            </Reveal>
        </div>
        <p class="mt-6 text-center text-xs text-muted-foreground">
            {{ t('marketing.problem.source') }}
        </p>
    </section>

    <section
        id="features"
        class="scroll-mt-20 bg-muted/30 py-24"
        aria-labelledby="features-title"
    >
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <SectionHeading
                id="features-title"
                :eyebrow="t('marketing.features.eyebrow')"
                :title="t('marketing.features.title')"
                :description="t('marketing.features.subtitle')"
            />
            <div class="mt-14">
                <FeaturesBento />
            </div>
        </div>
    </section>

    <section
        id="how-it-works"
        class="mx-auto max-w-7xl scroll-mt-20 px-4 py-24 sm:px-6 lg:px-8"
        aria-labelledby="how-title"
    >
        <SectionHeading
            id="how-title"
            :eyebrow="t('marketing.how.eyebrow')"
            :title="t('marketing.how.title')"
            :description="t('marketing.how.subtitle')"
        />
        <div class="mt-14">
            <HowItWorks />
        </div>
    </section>

    <section
        class="relative overflow-hidden bg-muted/30 py-24"
        aria-labelledby="telegram-title"
    >
        <div
            class="mx-auto grid max-w-7xl grid-cols-1 items-center gap-14 px-4 sm:px-6 lg:grid-cols-2 lg:px-8"
        >
            <div class="space-y-6">
                <SectionHeading
                    id="telegram-title"
                    align="left"
                    :eyebrow="t('marketing.telegram.eyebrow')"
                    :title="t('marketing.telegram.title')"
                    :description="t('marketing.telegram.subtitle')"
                />
                <ul class="space-y-3">
                    <li
                        v-for="point in ['alerts', 'actions', 'ask', 'private']"
                        :key="point"
                        class="flex gap-3"
                    >
                        <span
                            class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground"
                            aria-hidden="true"
                        >
                            <Check class="size-3.5" />
                        </span>
                        <span class="text-muted-foreground">{{
                            t(`marketing.telegram.points.${point}`)
                        }}</span>
                    </li>
                </ul>
            </div>
            <TelegramDemo />
        </div>
    </section>

    <section
        class="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8"
        aria-labelledby="security-title"
    >
        <SectionHeading
            id="security-title"
            :eyebrow="t('marketing.security.eyebrow')"
            :title="t('marketing.security.title')"
            :description="t('marketing.security.subtitle')"
        />
        <div class="mt-14 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Reveal
                v-for="(item, index) in securityItems"
                :key="item.key"
                :delay="index * 0.05"
            >
                <SpotlightCard class="h-full p-6">
                    <span
                        class="inline-flex size-10 items-center justify-center rounded-xl bg-primary-soft text-primary-soft-foreground"
                        aria-hidden="true"
                    >
                        <component :is="item.icon" class="size-5" />
                    </span>
                    <h3 class="mt-4 font-display text-lg font-bold">
                        {{ t(`marketing.security.items.${item.key}.title`) }}
                    </h3>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        {{ t(`marketing.security.items.${item.key}.body`) }}
                    </p>
                </SpotlightCard>
            </Reveal>
        </div>
        <div class="mt-10 text-center">
            <Button as-child variant="outline">
                <Link :href="endpoints.security()">
                    <Lock class="size-4" aria-hidden="true" />
                    {{ t('marketing.security.cta') }}
                </Link>
            </Button>
        </div>
    </section>

    <section class="bg-muted/30 py-24" aria-labelledby="pricing-title">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <SectionHeading
                id="pricing-title"
                :eyebrow="t('marketing.pricing_teaser.eyebrow')"
                :title="t('marketing.pricing_teaser.title')"
                :description="t('marketing.pricing_teaser.subtitle')"
            />
            <ul class="mt-8 flex flex-wrap justify-center gap-3">
                <li
                    v-for="point in ['free', 'trial', 'cancel']"
                    :key="point"
                    class="inline-flex items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm font-medium shadow-soft"
                >
                    <Check class="size-4 text-primary" aria-hidden="true" />
                    {{ t(`marketing.pricing_teaser.points.${point}`) }}
                </li>
            </ul>
            <template v-if="teaserPlans.length">
                <div class="mt-10 flex justify-center">
                    <IntervalToggle
                        v-model="interval"
                        :savings="maxYearlySavings(plans)"
                    />
                </div>
                <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <PlanCard
                        v-for="plan in teaserPlans"
                        :key="plan.key"
                        :plan="plan"
                        :interval="interval"
                    >
                        <template #cta>
                            <Button
                                as-child
                                class="mt-6 h-10 w-full"
                                :variant="
                                    plan.highlighted ? 'default' : 'secondary'
                                "
                            >
                                <Link
                                    :href="
                                        canRegister
                                            ? register()
                                            : endpoints.pricing()
                                    "
                                    >{{ t('ui.billing.start_free') }}</Link
                                >
                            </Button>
                        </template>
                    </PlanCard>
                </div>
            </template>
            <div class="mt-10 flex justify-center">
                <Button as-child size="lg" variant="outline" class="h-12">
                    <Link :href="endpoints.pricing()">
                        {{ t('marketing.pricing_teaser.cta') }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </div>
    </section>

    <section
        id="faq"
        class="mx-auto max-w-3xl scroll-mt-20 px-4 py-24 sm:px-6"
        aria-labelledby="faq-title"
    >
        <SectionHeading
            id="faq-title"
            :eyebrow="t('marketing.faq.eyebrow')"
            :title="t('marketing.faq.title')"
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
            <Link :href="primaryCta.href">
                {{ primaryCta.label }}
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </Button>
        <Button
            as-child
            size="lg"
            variant="outline"
            class="h-12 px-6 text-base"
        >
            <Link :href="endpoints.pricing()">{{
                t('marketing.final_cta.secondary')
            }}</Link>
        </Button>
    </CtaBand>
</template>
