<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <div
        :class="
            cn(
                'grid auto-rows-[minmax(14rem,auto)] grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-6',
                $props.class,
            )
        "
    >
        <slot />
    </div>
</template>
