<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowUpRight, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarRail,
} from '@/components/ui/sidebar';
import NavMain from '@/components/shell/NavMain.vue';
import NavUser from '@/components/shell/NavUser.vue';
import WorkspaceSwitcher from '@/components/shell/WorkspaceSwitcher.vue';
import { useCan } from '@/composables/useCan';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { appNavigation } from '@/lib/navigation';

const page = usePage();
const { can } = useCan();

const groups = computed(() =>
    appNavigation(
        can,
        Boolean(page.props.workspace),
        page.props.pf?.nav_counts,
    ),
);

const showUpgrade = computed(
    () =>
        page.props.workspace?.plan === 'free' &&
        can('billing.manage', 'workspace.manage'),
);
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
        :aria-label="t('ui.nav.main_navigation')"
    >
        <SidebarHeader>
            <WorkspaceSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :groups="groups" />
        </SidebarContent>

        <SidebarFooter>
            <div
                v-if="showUpgrade"
                class="relative overflow-hidden rounded-xl border border-sidebar-border bg-sidebar-accent/60 p-3 group-data-[collapsible=icon]:hidden"
            >
                <div
                    class="bg-brand-gradient pointer-events-none absolute -top-10 -right-10 size-28 rounded-full opacity-25 blur-2xl"
                    aria-hidden="true"
                />
                <p class="flex items-center gap-1.5 text-sm font-semibold">
                    <Sparkles class="size-4 text-ai" aria-hidden="true" />
                    {{ t('ui.upgrade.title') }}
                </p>
                <p class="mt-1 text-xs text-sidebar-foreground/75">
                    {{ t('ui.upgrade.body') }}
                </p>
                <Link
                    :href="endpoints.settings.billing.show()"
                    class="mt-2.5 inline-flex h-8 items-center gap-1 rounded-md bg-primary px-3 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90 pointer-coarse:h-11"
                >
                    {{ t('ui.upgrade.cta') }}
                    <ArrowUpRight class="size-3.5" aria-hidden="true" />
                </Link>
            </div>
            <NavUser />
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
</template>
