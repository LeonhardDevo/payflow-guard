<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus, Settings2 } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCan } from '@/composables/useCan';
import { endpoints } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';

const page = usePage();
const { isMobile } = useSidebar();
const { can } = useCan();

const current = computed(() => page.props.workspace);
const workspaces = computed(() => page.props.workspaces ?? []);

function switchTo(id: number): void {
    if (id === current.value?.id) {
        return;
    }

    router.post(
        endpoints.workspaces.switch(id).url,
        {},
        { preserveScroll: false },
    );
}

const planLabel = computed(() =>
    current.value
        ? tOr(`ui.plans.${current.value.plan}`, current.value.plan)
        : '',
);
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="workspace-switcher"
                    >
                        <AppLogoMark class="size-8 shrink-0" />
                        <span
                            class="grid min-w-0 flex-1 text-left leading-tight"
                        >
                            <span
                                class="truncate font-display text-sm font-bold"
                            >
                                {{ current?.name ?? page.props.name }}
                            </span>
                            <span
                                class="truncate text-xs text-sidebar-foreground/70"
                            >
                                {{
                                    current
                                        ? `${tOr(`roles.${current.role}`, current.role)} · ${planLabel}`
                                        : t('ui.workspace.none')
                                }}
                            </span>
                        </span>
                        <span class="sr-only"
                            >, {{ t('ui.workspace.switcher_label') }}</span
                        >
                        <ChevronsUpDown
                            class="ml-auto size-4 opacity-60"
                            aria-hidden="true"
                        />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-64 rounded-xl"
                    :side="isMobile ? 'bottom' : 'right'"
                    align="start"
                    :side-offset="6"
                >
                    <DropdownMenuLabel class="text-xs text-muted-foreground">
                        {{ t('ui.workspace.your_workspaces') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="workspace in workspaces"
                        :key="workspace.id"
                        class="gap-2.5 py-2"
                        @select="switchTo(workspace.id)"
                    >
                        <span
                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border bg-muted font-display text-xs font-bold"
                            aria-hidden="true"
                        >
                            {{ workspace.name.charAt(0).toUpperCase() }}
                        </span>
                        <span class="grid min-w-0 flex-1 leading-tight">
                            <span class="truncate text-sm font-medium">{{
                                workspace.name
                            }}</span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{
                                    tOr(
                                        `roles.${workspace.role}`,
                                        workspace.role,
                                    )
                                }}
                            </span>
                        </span>
                        <Check
                            v-if="workspace.id === current?.id"
                            class="size-4 text-primary"
                            :aria-label="t('ui.workspace.current')"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        v-if="current && can('workspace.manage')"
                        as-child
                    >
                        <Link
                            :href="endpoints.settings.workspace.show()"
                            class="gap-2.5"
                        >
                            <Settings2 class="size-4" aria-hidden="true" />
                            {{ t('ui.workspace.settings') }}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <Link
                            :href="endpoints.onboarding.create()"
                            class="gap-2.5"
                            data-test="create-workspace"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            {{ t('ui.workspace.create') }}
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
