<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Menu } from '@lucide/vue';
import { useWindowScroll } from '@vueuse/core';
import { computed, ref } from 'vue';
import type { MarketingLink } from '@/components/marketing/types';
import AppLogo from '@/components/shell/AppLogo.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import { login, register } from '@/routes';

const props = withDefaults(
    defineProps<{ links: MarketingLink[]; canRegister?: boolean }>(),
    {
        canRegister: true,
    },
);

const page = usePage();
const { y } = useWindowScroll();
const scrolled = computed(() => y.value > 8);
const mobileOpen = ref(false);
const { isCurrentUrl } = useCurrentUrl();

const isAuthed = computed(() => Boolean(page.props.auth?.user));

function hrefOf(link: MarketingLink): string {
    return typeof link.href === 'string' ? link.href : toUrl(link.href);
}

const items = computed(() =>
    props.links.map((link) => ({
        ...link,
        url: hrefOf(link),
        active: !link.anchor && isCurrentUrl(hrefOf(link)),
    })),
);
</script>

<template>
    <header
        :class="
            cn(
                'sticky top-0 z-40 transition-[background-color,border-color,box-shadow] duration-300',
                scrolled
                    ? 'glass border-b shadow-soft'
                    : 'border-b border-transparent',
            )
        "
    >
        <div
            class="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 sm:px-6 lg:px-8"
        >
            <Link
                :href="endpoints.home()"
                class="shrink-0 rounded-lg focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="t('marketing.nav.home')"
            >
                <AppLogo />
            </Link>

            <nav
                :aria-label="t('marketing.nav.label')"
                class="mx-auto hidden min-w-0 items-center gap-1 xl:flex"
            >
                <component
                    :is="item.anchor ? 'a' : Link"
                    v-for="item in items"
                    :key="item.label"
                    :href="item.url"
                    :aria-current="item.active ? 'page' : undefined"
                    :class="
                        cn(
                            'rounded-full px-3.5 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                            item.active
                                ? 'bg-primary-soft text-primary-soft-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )
                    "
                >
                    {{ t(item.label) }}
                </component>
            </nav>

            <div class="ml-auto flex items-center gap-1 xl:ml-0">
                <LocaleSwitcher />
                <ThemeToggle />
                <template v-if="isAuthed">
                    <Button
                        as-child
                        size="sm"
                        class="ml-1 hidden h-9 sm:inline-flex"
                    >
                        <Link :href="endpoints.dashboard()">
                            {{ t('marketing.nav.dashboard') }}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </template>
                <template v-else>
                    <Button
                        as-child
                        variant="ghost"
                        size="sm"
                        class="hidden h-9 sm:inline-flex"
                    >
                        <Link :href="login()">{{
                            t('marketing.nav.login')
                        }}</Link>
                    </Button>
                    <Button
                        v-if="canRegister"
                        as-child
                        size="sm"
                        class="ml-1 hidden h-9 sm:inline-flex"
                    >
                        <Link :href="register()" data-test="nav-get-started">
                            {{ t('marketing.nav.get_started') }}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </template>

                <Sheet v-model:open="mobileOpen">
                    <SheetTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="xl:hidden"
                            :aria-label="t('marketing.nav.open_menu')"
                        >
                            <Menu class="size-5" aria-hidden="true" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="w-[min(20rem,85vw)]">
                        <SheetHeader>
                            <SheetTitle><AppLogo /></SheetTitle>
                            <SheetDescription class="sr-only">{{
                                t('marketing.nav.label')
                            }}</SheetDescription>
                        </SheetHeader>
                        <nav
                            :aria-label="t('marketing.nav.label')"
                            class="flex flex-col gap-1 px-4"
                        >
                            <SheetClose
                                v-for="item in items"
                                :key="item.label"
                                as-child
                            >
                                <component
                                    :is="item.anchor ? 'a' : Link"
                                    :href="item.url"
                                    class="rounded-lg px-3 py-3 text-base font-medium hover:bg-muted"
                                >
                                    {{ t(item.label) }}
                                </component>
                            </SheetClose>
                        </nav>
                        <div class="mt-auto flex flex-col gap-2 p-4">
                            <LocaleSwitcher
                                variant="outline"
                                class="w-full justify-center"
                                :test-id="null"
                            />
                            <template v-if="isAuthed">
                                <Button as-child class="h-11">
                                    <Link :href="endpoints.dashboard()">{{
                                        t('marketing.nav.dashboard')
                                    }}</Link>
                                </Button>
                            </template>
                            <template v-else>
                                <Button as-child variant="outline" class="h-11">
                                    <Link :href="login()">{{
                                        t('marketing.nav.login')
                                    }}</Link>
                                </Button>
                                <Button
                                    v-if="canRegister"
                                    as-child
                                    class="h-11"
                                >
                                    <Link :href="register()">{{
                                        t('marketing.nav.get_started')
                                    }}</Link>
                                </Button>
                            </template>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </div>
    </header>
</template>
