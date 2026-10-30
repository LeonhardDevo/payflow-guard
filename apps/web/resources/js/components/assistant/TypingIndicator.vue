<script setup lang="ts">
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ label?: string | null }>(), { label: null });
</script>

<template>
    <div
        class="inline-flex items-center gap-2.5"
        role="status"
        aria-live="polite"
    >
        <span class="inline-flex items-center gap-1" aria-hidden="true">
            <span class="typing-dot size-1.5 rounded-full bg-ai" />
            <span
                class="typing-dot size-1.5 rounded-full bg-ai [animation-delay:160ms]"
            />
            <span
                class="typing-dot size-1.5 rounded-full bg-ai [animation-delay:320ms]"
            />
        </span>
        <span class="text-sm text-muted-foreground">{{
            label ?? t('ui.assistant.thinking')
        }}</span>
    </div>
</template>

<style scoped>
.typing-dot {
    animation: typing-bounce 1.1s ease-in-out infinite;
}

@keyframes typing-bounce {
    0%,
    80%,
    100% {
        transform: translateY(0);
        opacity: 0.45;
    }
    40% {
        transform: translateY(-4px);
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .typing-dot {
        animation: none;
        opacity: 0.8;
    }
}
</style>
