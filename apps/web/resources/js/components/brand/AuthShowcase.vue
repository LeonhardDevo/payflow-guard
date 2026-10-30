<script setup lang="ts">
import { BadgeCheck, BellRing, ShieldAlert } from '@lucide/vue';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import AuroraBackground from '@/components/fx/AuroraBackground.vue';
import { t } from '@/lib/i18n';

const cards = [
    {
        icon: BadgeCheck,
        key: 'paid',
        tone: 'bg-success-soft text-success-soft-foreground',
        offset: 'lg:translate-x-6',
    },
    {
        icon: BellRing,
        key: 'reminder',
        tone: 'bg-info-soft text-info-soft-foreground',
        offset: 'lg:-translate-x-4',
    },
    {
        icon: ShieldAlert,
        key: 'guard',
        tone: 'bg-warning-soft text-warning-soft-foreground',
        offset: 'lg:translate-x-10',
    },
] as const;
</script>

<template>
    <div
        class="dark relative flex h-full flex-col overflow-hidden bg-background p-10 text-foreground"
    >
        <AuroraBackground />
        <div class="relative z-10 flex items-center gap-3">
            <AppLogoMark class="size-9" />
            <span class="font-display text-lg font-bold tracking-tight">{{
                t('marketing.brand.name')
            }}</span>
        </div>

        <div class="relative z-10 my-auto max-w-md space-y-8 py-12">
            <div class="space-y-4">
                <h2
                    class="font-display text-3xl leading-tight font-bold text-balance"
                >
                    {{ t('marketing.auth.headline') }}
                </h2>
                <p class="text-base text-muted-foreground">
                    {{ t('marketing.auth.subhead') }}
                </p>
            </div>

            <ul
                class="space-y-3"
                :aria-label="t('marketing.auth.preview_label')"
            >
                <li
                    v-for="(card, index) in cards"
                    :key="card.key"
                    :class="[
                        'glass flex items-center gap-3 rounded-2xl border p-4 shadow-lifted motion-safe:animate-float',
                        card.offset,
                    ]"
                    :style="{ animationDelay: `${index * -1.6}s` }"
                >
                    <span
                        :class="[
                            'inline-flex size-10 shrink-0 items-center justify-center rounded-xl',
                            card.tone,
                        ]"
                        aria-hidden="true"
                    >
                        <component :is="card.icon" class="size-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold">{{
                            t(`marketing.auth.cards.${card.key}.title`)
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{
                                t(`marketing.auth.cards.${card.key}.body`)
                            }}</span
                        >
                    </span>
                </li>
            </ul>
        </div>

        <figure
            class="relative z-10 max-w-md border-l-2 border-primary/60 pl-4"
        >
            <blockquote class="text-sm text-foreground/90">
                {{ t('marketing.auth.stat') }}
            </blockquote>
            <figcaption class="mt-1 text-xs text-muted-foreground">
                {{ t('marketing.auth.stat_source') }}
            </figcaption>
        </figure>
    </div>
</template>
