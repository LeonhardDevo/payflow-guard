<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import type { NavGroup } from '@/types';

defineProps<{ groups: NavGroup[] }>();

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup v-for="group in groups" :key="group.label" class="px-2 py-1">
        <SidebarGroupLabel>{{ t(group.label) }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem
                v-for="item in group.items"
                :key="toUrl(item.href)"
            >
                <SidebarMenuButton
                    as-child
                    :is-active="
                        item.matchPrefix
                            ? isCurrentOrParentUrl(item.href)
                            : isCurrentUrl(item.href)
                    "
                    :tooltip="t(item.title)"
                    class="data-[active=true]:font-semibold data-[active=true]:[&>svg]:text-sidebar-primary"
                >
                    <Link
                        :href="item.href"
                        prefetch
                        :aria-current="
                            (
                                item.matchPrefix
                                    ? isCurrentOrParentUrl(item.href)
                                    : isCurrentUrl(item.href)
                            )
                                ? 'page'
                                : undefined
                        "
                    >
                        <component :is="item.icon" aria-hidden="true" />
                        <span>{{ t(item.title) }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge v-if="item.badge">{{
                    item.badge
                }}</SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
