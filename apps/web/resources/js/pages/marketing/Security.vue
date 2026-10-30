<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Bot,
    Check,
    FileLock2,
    Fingerprint,
    Landmark,
    Network,
    ScrollText,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import AuroraBackground from '@/components/fx/AuroraBackground.vue';
import Reveal from '@/components/fx/Reveal.vue';
import SpotlightCard from '@/components/fx/SpotlightCard.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { endpoints } from '@/lib/endpoints';
import { listIndices, t } from '@/lib/i18n';

const pillars = [
    { key: 'data', icon: FileLock2 },
    { key: 'access', icon: Fingerprint },
    { key: 'payments', icon: Landmark },
    { key: 'ai', icon: Bot },
    { key: 'infra', icon: Network },
    { key: 'audit', icon: ScrollText },
] as const;

const sections = computed(() =>
    pillars.map((pillar) => ({
        ...pillar,
        points: listIndices(
            `marketing.security_page.pillars.${pillar.key}.points`,
            'text',
        ).map((index) =>
            t(
                `marketing.security_page.pillars.${pillar.key}.points.${index}.text`,
            ),
        ),
    })),
);
</script>

<template>
    <SeoHead
        :title="t('marketing.security_page.meta_title')"
        :description="t('marketing.security_page.meta_description')"
    />

    <section class="relative overflow-hidden" aria-labelledby="security-hero">
        <AuroraBackground />
        <div
            class="relative mx-auto max-w-4xl px-4 pt-20 pb-16 text-center sm:px-6"
        >
            <div
                class="motion-safe:animate-fade-up"
                style="animation-delay: 0ms"
            >
                <span
                    class="mx-auto inline-flex size-16 items-center justify-center rounded-2xl bg-primary-soft text-primary-soft-foreground shadow-soft"
                    aria-hidden="true"
                >
                    <ShieldCheck class="size-8" />
                </span>
            </div>
            <div
                class="motion-safe:animate-fade-up"
                style="animation-delay: 60ms"
            >
                <h1
                    id="security-hero"
                    class="mt-6 font-display text-4xl font-extrabold tracking-tight text-balance sm:text-5xl"
                >
                    {{ t('marketing.security_page.title') }}
                </h1>
            </div>
            <div
                class="motion-safe:animate-fade-up"
                style="animation-delay: 120ms"
            >
                <p class="mx-auto mt-4 max-w-2xl text-lg text-muted-foreground">
                    {{ t('marketing.security_page.subtitle') }}
                </p>
            </div>
        </div>
    </section>

    <section
        class="mx-auto max-w-7xl px-4 pb-20 sm:px-6 lg:px-8"
        :aria-label="t('marketing.security_page.pillars_label')"
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Reveal
                v-for="(section, index) in sections"
                :key="section.key"
                :delay="index * 0.05"
                class="h-full"
            >
                <SpotlightCard as="article" class="h-full p-6">
                    <span
                        class="inline-flex size-11 items-center justify-center rounded-xl bg-primary-soft text-primary-soft-foreground"
                        aria-hidden="true"
                    >
                        <component :is="section.icon" class="size-5" />
                    </span>
                    <h2 class="mt-4 font-display text-xl font-bold">
                        {{
                            t(
                                `marketing.security_page.pillars.${section.key}.title`,
                            )
                        }}
                    </h2>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        {{
                            t(
                                `marketing.security_page.pillars.${section.key}.body`,
                            )
                        }}
                    </p>
                    <ul class="mt-4 space-y-2">
                        <li
                            v-for="point in section.points"
                            :key="point"
                            class="flex gap-2 text-sm"
                        >
                            <Check
                                class="mt-0.5 size-4 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span>{{ point }}</span>
                        </li>
                    </ul>
                </SpotlightCard>
            </Reveal>
        </div>
    </section>

    <section class="bg-muted/30 py-20" aria-labelledby="disclosure-title">
        <div class="mx-auto max-w-3xl px-4 text-center sm:px-6">
            <SectionHeading
                id="disclosure-title"
                :title="t('marketing.security_page.disclosure_title')"
                :description="t('marketing.security_page.disclosure_body')"
            />
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <Button as-child variant="outline">
                    <Link :href="endpoints.legal('privacy')">{{
                        t('marketing.footer.privacy')
                    }}</Link>
                </Button>
                <Button as-child variant="outline">
                    <Link :href="endpoints.legal('terms')">{{
                        t('marketing.footer.terms')
                    }}</Link>
                </Button>
            </div>
        </div>
    </section>

    <CtaBand
        :title="t('marketing.final_cta.title')"
        :description="t('marketing.final_cta.subtitle')"
    >
        <Button as-child size="lg" class="h-12 px-6 text-base">
            <Link :href="endpoints.pricing()">{{
                t('marketing.final_cta.secondary')
            }}</Link>
        </Button>
    </CtaBand>
</template>
