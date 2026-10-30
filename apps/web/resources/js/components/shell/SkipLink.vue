<script setup lang="ts">
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ target?: string }>(), { target: '#main-content' });
</script>

<template>
    <a
        :href="target"
        class="fixed top-3 left-3 z-[100] -translate-y-24 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-lifted transition-transform focus:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
        {{ t('ui.shell.skip_to_content') }}
    </a>
</template>
