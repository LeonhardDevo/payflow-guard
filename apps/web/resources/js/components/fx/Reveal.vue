<script setup lang="ts">
import { motion } from 'motion-v';
import { computed } from 'vue';
import { useReducedMotion } from '@/composables/useReducedMotion';

const props = withDefaults(
    defineProps<{
        delay?: number;
        duration?: number;
        y?: number;
        blur?: boolean;
    }>(),
    { delay: 0, duration: 0.6, y: 16, blur: false },
);

const reduced = useReducedMotion();

const initial = computed(() =>
    reduced.value
        ? { opacity: 1 }
        : {
              opacity: 0,
              y: props.y,
              filter: props.blur ? 'blur(8px)' : 'blur(0px)',
          },
);
</script>

<template>
    <motion.div
        :initial="initial"
        :while-in-view="{ opacity: 1, y: 0, filter: 'blur(0px)' }"
        :in-view-options="{ once: true, margin: '0px 0px -10% 0px' }"
        :transition="{
            duration: reduced ? 0 : duration,
            delay: reduced ? 0 : delay,
            ease: [0.16, 1, 0.3, 1],
        }"
    >
        <slot />
    </motion.div>
</template>
