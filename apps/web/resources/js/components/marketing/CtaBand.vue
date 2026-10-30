<script setup lang="ts">
import AuroraBackground from '@/components/fx/AuroraBackground.vue';
import Reveal from '@/components/fx/Reveal.vue';

defineProps<{ title: string; description?: string | null }>();
</script>

<template>
    <section class="px-4 py-20 sm:px-6 lg:px-8">
        <Reveal>
            <div
                class="dark relative mx-auto max-w-6xl overflow-hidden rounded-[2rem] border bg-background px-6 py-16 text-center text-foreground shadow-lifted sm:px-12"
            >
                <AuroraBackground />
                <div class="relative z-10 mx-auto max-w-2xl space-y-5">
                    <h2
                        class="font-display text-3xl font-extrabold tracking-tight text-balance sm:text-5xl"
                    >
                        {{ title }}
                    </h2>
                    <p v-if="description" class="text-lg text-muted-foreground">
                        {{ description }}
                    </p>
                    <div
                        class="flex flex-col items-center justify-center gap-3 pt-2 sm:flex-row"
                    >
                        <slot />
                    </div>
                </div>
            </div>
        </Reveal>
    </section>
</template>
