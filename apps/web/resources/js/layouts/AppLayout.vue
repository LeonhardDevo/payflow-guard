<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import BillingAlert from '@/components/billing/BillingAlert.vue';
import AppHeader from '@/components/shell/AppHeader.vue';
import AppSidebar from '@/components/shell/AppSidebar.vue';
import CookieNotice from '@/components/legal/CookieNotice.vue';
import AppToaster from '@/components/shell/AppToaster.vue';
import CommandPalette from '@/components/shell/CommandPalette.vue';
import SkipLink from '@/components/shell/SkipLink.vue';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const page = usePage();
</script>

<template>
    <SkipLink />
    <SidebarProvider :default-open="page.props.sidebarOpen ?? true">
        <AppSidebar />
        <SidebarInset
            class="min-w-0 overflow-x-clip md:peer-data-[variant=inset]:shadow-soft"
        >
            <AppHeader :breadcrumbs="breadcrumbs" />
            <BillingAlert />
            <CookieNotice inline />
            <main
                id="main-content"
                tabindex="-1"
                class="flex min-w-0 flex-1 flex-col focus:outline-none"
            >
                <slot />
            </main>
        </SidebarInset>
    </SidebarProvider>
    <CommandPalette />
    <AppToaster />
</template>
