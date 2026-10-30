<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        value: string;
        label?: string | null;
        size?: 'icon' | 'icon-sm';
    }>(),
    { label: null, size: 'icon-sm' },
);

const { copy, copied, isSupported } = useClipboard({
    legacy: true,
    copiedDuring: 1600,
});
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <Button
                v-if="isSupported"
                type="button"
                variant="ghost"
                :size="size"
                :aria-label="
                    copied
                        ? t('ui.common.copied')
                        : (label ?? t('ui.common.copy'))
                "
                @click="copy(props.value)"
            >
                <Check v-if="copied" class="text-success" />
                <Copy v-else />
            </Button>
        </TooltipTrigger>
        <TooltipContent>{{
            copied ? t('ui.common.copied') : (label ?? t('ui.common.copy'))
        }}</TooltipContent>
    </Tooltip>
</template>
