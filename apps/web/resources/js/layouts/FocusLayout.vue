<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import AuroraBackground from '@/components/fx/AuroraBackground.vue';
import AppLogo from '@/components/shell/AppLogo.vue';
import CookieNotice from '@/components/legal/CookieNotice.vue';
import AppToaster from '@/components/shell/AppToaster.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import SkipLink from '@/components/shell/SkipLink.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { logout } from '@/routes';

const page = usePage();
</script>

<template>
    <SkipLink />
    <div class="relative flex min-h-svh flex-col overflow-hidden">
        <AuroraBackground class="opacity-70" />
        <header
            class="relative z-10 mx-auto flex w-full max-w-6xl items-center justify-between gap-3 px-5 py-4 sm:px-8"
        >
            <Link
                :href="
                    page.props.workspace
                        ? endpoints.dashboard()
                        : endpoints.home()
                "
                class="rounded-lg focus-visible:ring-2 focus-visible:ring-ring"
            >
                <AppLogo />
            </Link>
            <div class="flex items-center gap-1">
                <LocaleSwitcher />
                <ThemeToggle />
                <Button
                    v-if="page.props.auth.user"
                    variant="ghost"
                    size="icon"
                    :aria-label="t('ui.shell.log_out')"
                    @click="
                        () => {
                            router.flushAll();
                            router.post(logout().url);
                        }
                    "
                >
                    <LogOut class="size-[1.1rem]" aria-hidden="true" />
                </Button>
            </div>
        </header>
        <main
            id="main-content"
            tabindex="-1"
            class="relative z-10 mx-auto flex w-full max-w-6xl flex-1 flex-col px-5 pb-12 focus:outline-none sm:px-8"
        >
            <slot />
        </main>
    </div>
    <AppToaster />
    <CookieNotice />
</template>
