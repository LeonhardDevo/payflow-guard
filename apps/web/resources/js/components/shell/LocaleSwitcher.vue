<script setup lang="ts">
import { Check, Languages } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useLocale } from '@/composables/useLocale';
import type { HTMLAttributes } from 'vue';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        variant?: 'ghost' | 'outline';
        compact?: boolean;
        testId?: string | null;
        class?: HTMLAttributes['class'];
    }>(),
    { variant: 'ghost', compact: false, testId: 'locale-switcher', class: '' },
);

const { locale, locales, setLocale, switching } = useLocale();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                :variant="variant"
                :size="compact ? 'icon' : 'default'"
                :class="
                    cn(
                        compact ? '' : 'gap-1.5 px-2.5 max-sm:w-9 max-sm:px-0',
                        $props.class,
                    )
                "
                :aria-label="
                    compact
                        ? t('ui.locale.label')
                        : `${t('ui.locale.label')}: ${locale.toUpperCase()}`
                "
                :disabled="switching"
                :data-test="testId ?? undefined"
            >
                <Languages class="size-[1.1rem]" aria-hidden="true" />
                <span
                    v-if="!compact"
                    class="hidden font-mono text-xs font-semibold uppercase sm:inline"
                    >{{ locale }}</span
                >
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-44">
            <DropdownMenuLabel class="text-xs text-muted-foreground">
                {{ t('ui.locale.label') }}
            </DropdownMenuLabel>
            <DropdownMenuItem
                v-for="option in locales"
                :key="option.code"
                :lang="option.code"
                :data-test="testId ? `locale-option-${option.code}` : undefined"
                @select="setLocale(option.code)"
            >
                <span
                    class="inline-flex w-7 font-mono text-xs font-semibold text-muted-foreground uppercase"
                    aria-hidden="true"
                    >{{ option.code }}</span
                >
                <span class="flex-1">{{ option.label }}</span>
                <Check
                    v-if="locale === option.code"
                    class="size-4 text-primary"
                    aria-hidden="true"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
