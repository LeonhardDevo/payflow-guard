<script setup lang="ts">
import { Check, Monitor, Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import type { HTMLAttributes } from 'vue';
import { t } from '@/lib/i18n';
import type { Appearance } from '@/types';

withDefaults(
    defineProps<{
        variant?: 'ghost' | 'outline';
        testId?: string | null;
        class?: HTMLAttributes['class'];
    }>(),
    { variant: 'ghost', testId: 'theme-toggle', class: '' },
);

const { appearance, updateAppearance } = useAppearance();

const options: Array<{ value: Appearance; icon: typeof Sun; key: string }> = [
    { value: 'light', icon: Sun, key: 'ui.theme.light' },
    { value: 'dark', icon: Moon, key: 'ui.theme.dark' },
    { value: 'system', icon: Monitor, key: 'ui.theme.system' },
];
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                :variant="variant"
                size="icon"
                :class="$props.class"
                :aria-label="t('ui.theme.toggle')"
                :data-test="testId ?? undefined"
            >
                <Sun class="size-[1.15rem] dark:hidden" aria-hidden="true" />
                <Moon
                    class="hidden size-[1.15rem] dark:block"
                    aria-hidden="true"
                />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-44">
            <DropdownMenuLabel class="text-xs text-muted-foreground">
                {{ t('ui.theme.label') }}
            </DropdownMenuLabel>
            <DropdownMenuItem
                v-for="option in options"
                :key="option.value"
                :data-test="testId ? `theme-option-${option.value}` : undefined"
                @select="updateAppearance(option.value)"
            >
                <component
                    :is="option.icon"
                    class="size-4"
                    aria-hidden="true"
                />
                <span class="flex-1">{{ t(option.key) }}</span>
                <Check
                    v-if="appearance === option.value"
                    class="size-4 text-primary"
                    aria-hidden="true"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
