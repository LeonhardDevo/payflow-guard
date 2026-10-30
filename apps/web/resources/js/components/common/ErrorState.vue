<script setup lang="ts">
import { RefreshCw, TriangleAlert } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

withDefaults(
    defineProps<{
        title?: string | null;
        message?: string | null;
        retrying?: boolean;
        retryable?: boolean;
    }>(),
    { title: null, message: null, retrying: false, retryable: false },
);

defineEmits<{ retry: [] }>();
</script>

<template>
    <div
        role="alert"
        class="flex flex-col items-center gap-3 rounded-xl border border-destructive/30 bg-destructive-soft px-6 py-8 text-center"
    >
        <span
            class="inline-flex size-10 items-center justify-center rounded-full bg-destructive/15 text-destructive-soft-foreground"
            aria-hidden="true"
        >
            <TriangleAlert class="size-5" />
        </span>
        <div class="space-y-1">
            <p class="font-semibold text-destructive-soft-foreground">
                {{ title ?? t('ui.errors.title') }}
            </p>
            <p class="text-sm text-destructive-soft-foreground/90">
                {{ message ?? t('ui.errors.generic') }}
            </p>
        </div>
        <Button
            v-if="retryable"
            variant="outline"
            size="sm"
            :disabled="retrying"
            @click="$emit('retry')"
        >
            <RefreshCw :class="['size-4', retrying ? 'animate-spin' : '']" />
            {{ t('ui.common.retry') }}
        </Button>
    </div>
</template>
