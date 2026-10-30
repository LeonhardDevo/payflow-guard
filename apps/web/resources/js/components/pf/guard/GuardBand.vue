<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ caption?: string | null }>(), { caption: null });
</script>

<template>
    <div
        class="relative flex items-center gap-3 overflow-hidden rounded-2xl bg-[oklch(0.2_0.04_264)] px-4 py-2.5 text-[oklch(0.97_0.005_250)] shadow-soft ring-1 ring-black/5 dark:bg-[oklch(0.115_0.02_265)] dark:ring-white/10"
        data-test="guard-band"
    >
        <span
            class="absolute inset-y-0 left-0 w-1 bg-warning"
            aria-hidden="true"
        />
        <ShieldCheck
            class="size-4 shrink-0 text-[oklch(0.82_0.14_164)]"
            aria-hidden="true"
        />
        <span class="shrink-0 text-sm font-semibold tracking-wide">{{
            t('guard.band.module')
        }}</span>
        <span
            class="hidden min-w-0 truncate text-xs text-[oklch(0.82_0.02_255)] sm:inline"
            >{{ caption ?? t('guard.band.caption') }}</span
        >
        <span v-if="$slots.default" class="ml-auto flex shrink-0 gap-2">
            <slot />
        </span>
    </div>
</template>
