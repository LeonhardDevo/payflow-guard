<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        masked?: boolean;
        pattern?: boolean;
    }>(),
    { masked: true, pattern: true },
);
</script>

<template>
    <div
        aria-hidden="true"
        :class="
            cn(
                'pointer-events-none absolute inset-0 overflow-hidden',
                $props.class,
            )
        "
    >
        <div
            :class="[
                'absolute -inset-[20%] animate-aurora blur-3xl will-change-transform',
                masked ? 'mask-radial' : '',
            ]"
        >
            <div
                class="absolute top-[8%] left-[12%] h-[46%] w-[46%] rounded-full"
                style="
                    background: radial-gradient(
                        closest-side,
                        var(--aurora-1),
                        transparent
                    );
                "
            />
            <div
                class="absolute top-[18%] right-[8%] h-[52%] w-[44%] rounded-full"
                style="
                    background: radial-gradient(
                        closest-side,
                        var(--aurora-3),
                        transparent
                    );
                "
            />
            <div
                class="absolute bottom-[6%] left-[30%] h-[44%] w-[50%] rounded-full"
                style="
                    background: radial-gradient(
                        closest-side,
                        var(--aurora-2),
                        transparent
                    );
                "
            />
            <div
                class="absolute right-[30%] bottom-[24%] h-[30%] w-[28%] rounded-full"
                style="
                    background: radial-gradient(
                        closest-side,
                        var(--aurora-4),
                        transparent
                    );
                "
            />
        </div>
        <div
            v-if="pattern"
            class="bg-dot-pattern mask-fade-b absolute inset-0 opacity-70"
        />
    </div>
</template>
