<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, Languages, Monitor, Moon, Palette, Sun } from '@lucide/vue';
import SectionCard from '@/components/common/SectionCard.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useLocale } from '@/composables/useLocale';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit } from '@/routes/appearance';
import type { Appearance } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.settings.title', href: edit() },
            { title: 'ui.settings.nav.appearance', href: edit() },
        ],
    },
});

const { appearance, updateAppearance } = useAppearance();
const { locale, locales, setLocale, switching } = useLocale();

const themes: Array<{ value: Appearance; icon: typeof Sun; key: string }> = [
    { value: 'light', icon: Sun, key: 'ui.theme.light' },
    { value: 'dark', icon: Moon, key: 'ui.theme.dark' },
    { value: 'system', icon: Monitor, key: 'ui.theme.system' },
];

function onThemeKey(event: KeyboardEvent, index: number): void {
    const delta =
        event.key === 'ArrowRight' || event.key === 'ArrowDown'
            ? 1
            : event.key === 'ArrowLeft' || event.key === 'ArrowUp'
              ? -1
              : 0;

    if (!delta) {
        return;
    }

    event.preventDefault();
    const next = themes[(index + delta + themes.length) % themes.length];
    updateAppearance(next.value);
    (
        (event.currentTarget as HTMLElement).parentElement?.children[
            (index + delta + themes.length) % themes.length
        ] as HTMLElement | undefined
    )?.focus();
}
</script>

<template>
    <Head :title="t('ui.settings.appearance.title')" />

    <SectionCard
        :icon="Palette"
        :title="t('ui.settings.appearance.title')"
        :description="t('ui.settings.appearance.description')"
    >
        <div
            role="radiogroup"
            :aria-label="t('ui.theme.label')"
            class="grid grid-cols-1 gap-4 sm:grid-cols-3"
        >
            <button
                v-for="(theme, index) in themes"
                :key="theme.value"
                type="button"
                role="radio"
                :aria-checked="appearance === theme.value"
                :tabindex="appearance === theme.value ? 0 : -1"
                :data-test="`appearance-${theme.value}`"
                :class="
                    cn(
                        'group relative overflow-hidden rounded-2xl border-2 p-1.5 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring',
                        appearance === theme.value
                            ? 'border-primary'
                            : 'border-border hover:border-primary/40',
                    )
                "
                @click="updateAppearance(theme.value)"
                @keydown="onThemeKey($event, index)"
            >
                <span
                    :class="[
                        'relative block h-24 overflow-hidden rounded-xl',
                        theme.value === 'dark'
                            ? 'dark'
                            : theme.value === 'light'
                              ? 'light'
                              : '',
                    ]"
                    aria-hidden="true"
                >
                    <span
                        v-if="theme.value === 'system'"
                        class="absolute inset-0 grid grid-cols-2"
                    >
                        <span class="block bg-[#F7F8FA]" />
                        <span class="block bg-[#070B14]" />
                    </span>
                    <span v-else class="absolute inset-0 bg-background" />
                    <span
                        class="absolute top-3 left-3 h-[calc(100%-1.5rem)] w-8 rounded-md bg-sidebar ring-1 ring-sidebar-border"
                    />
                    <span
                        class="absolute top-3 right-3 left-14 h-6 rounded-md bg-card ring-1 ring-border"
                    />
                    <span
                        class="absolute right-3 bottom-3 left-14 h-8 rounded-md bg-card ring-1 ring-border"
                    >
                        <span
                            class="absolute top-2.5 left-2 h-1.5 w-1/2 rounded-full bg-primary"
                        />
                    </span>
                </span>
                <span
                    class="flex items-center justify-between px-2 pt-2.5 pb-1"
                >
                    <span class="flex items-center gap-2 text-sm font-medium">
                        <component
                            :is="theme.icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ t(theme.key) }}
                    </span>
                    <Check
                        v-if="appearance === theme.value"
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />
                </span>
            </button>
        </div>
    </SectionCard>

    <SectionCard
        :icon="Languages"
        :title="t('ui.settings.language.title')"
        :description="t('ui.settings.language.description')"
    >
        <div
            role="radiogroup"
            :aria-label="t('ui.settings.language.title')"
            class="grid grid-cols-1 gap-3 sm:grid-cols-2"
        >
            <button
                v-for="option in locales"
                :key="option.code"
                type="button"
                role="radio"
                :lang="option.code"
                :aria-checked="locale === option.code"
                :disabled="switching"
                :data-test="`language-${option.code}`"
                :class="
                    cn(
                        'flex items-center gap-3 rounded-xl border-2 p-4 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60',
                        locale === option.code
                            ? 'border-primary bg-primary-soft/40'
                            : 'border-border hover:border-primary/40',
                    )
                "
                @click="setLocale(option.code)"
            >
                <span
                    class="inline-flex size-10 items-center justify-center rounded-lg bg-muted font-mono text-sm font-bold uppercase"
                    aria-hidden="true"
                    >{{ option.code }}</span
                >
                <span class="flex-1">
                    <span class="block font-medium">{{ option.label }}</span>
                    <span class="block text-xs text-muted-foreground">{{
                        t(`ui.settings.language.hint_${option.code}`)
                    }}</span>
                </span>
                <Check
                    v-if="locale === option.code"
                    class="size-4 text-primary"
                    aria-hidden="true"
                />
            </button>
        </div>
        <p class="mt-4 text-xs text-muted-foreground">
            {{ t('ui.settings.language.note') }}
        </p>
    </SectionCard>
</template>
