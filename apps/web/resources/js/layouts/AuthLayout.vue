<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AuthShowcase from '@/components/brand/AuthShowcase.vue';
import AppLogo from '@/components/shell/AppLogo.vue';
import CookieNotice from '@/components/legal/CookieNotice.vue';
import AppToaster from '@/components/shell/AppToaster.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import SkipLink from '@/components/shell/SkipLink.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ title?: string; description?: string }>(), {
    title: '',
    description: '',
});
</script>

<template>
    <SkipLink />
    <div
        class="grid min-h-svh grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]"
    >
        <div class="flex min-h-svh flex-col px-5 py-5 sm:px-10">
            <header class="flex items-center justify-between gap-3">
                <Link
                    :href="endpoints.home()"
                    class="rounded-lg focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <AppLogo />
                </Link>
                <div class="flex items-center gap-1">
                    <LocaleSwitcher />
                    <ThemeToggle />
                </div>
            </header>

            <main
                id="main-content"
                tabindex="-1"
                class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-10 focus:outline-none"
            >
                <div class="mb-8 space-y-2">
                    <h1
                        v-if="title"
                        class="font-display text-2xl font-bold tracking-tight"
                    >
                        {{ t(title) }}
                    </h1>
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ t(description) }}
                    </p>
                </div>
                <slot />
            </main>

            <footer
                class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
            >
                <Link
                    :href="endpoints.legal('terms')"
                    class="hover:text-foreground hover:underline"
                >
                    {{ t('ui.legal.terms') }}
                </Link>
                <Link
                    :href="endpoints.legal('privacy')"
                    class="hover:text-foreground hover:underline"
                >
                    {{ t('ui.legal.privacy') }}
                </Link>
                <Link
                    :href="endpoints.legal('cookies')"
                    class="hover:text-foreground hover:underline"
                >
                    {{ t('ui.legal.docs.cookies') }}
                </Link>
                <Link
                    :href="endpoints.security()"
                    class="hover:text-foreground hover:underline"
                >
                    {{ t('ui.legal.security') }}
                </Link>
            </footer>
        </div>
        <aside class="hidden p-3 lg:block" aria-hidden="false">
            <div class="h-full overflow-hidden rounded-3xl">
                <AuthShowcase />
            </div>
        </aside>
    </div>
    <AppToaster />
    <CookieNotice />
</template>
