<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        name: string;
        src?: string | null;
        size?: 'xs' | 'sm' | 'md' | 'lg';
        class?: string;
    }>(),
    { src: null, size: 'sm', class: '' },
);

const { getInitials } = useInitials();

const palette = [
    'bg-primary-soft text-primary-soft-foreground',
    'bg-ai-soft text-ai-soft-foreground',
    'bg-info-soft text-info-soft-foreground',
    'bg-warning-soft text-warning-soft-foreground',
];

const tint = computed(() => {
    let hash = 0;

    for (const char of props.name) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }

    return palette[hash % palette.length];
});

const sizeClass = {
    xs: 'size-6 text-[0.625rem]',
    sm: 'size-8 text-xs',
    md: 'size-10 text-sm',
    lg: 'size-14 text-base',
} as const;
</script>

<template>
    <Avatar :class="cn('rounded-lg', sizeClass[size], props.class)">
        <AvatarImage v-if="src" :src="src" :alt="name" />
        <AvatarFallback :class="['rounded-lg font-semibold', tint]">
            {{ getInitials(name) }}
        </AvatarFallback>
    </Avatar>
</template>
