<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    Banknote,
    Building2,
    FilePlus2,
    FileUp,
    HandCoins,
    Landmark,
    Languages,
    LogOut,
    MessageSquarePlus,
    Monitor,
    Moon,
    Plus,
    Sun,
    Upload,
    UserPlus,
} from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, ref } from 'vue';
import PfPaletteSearch from '@/components/pf/PfPaletteSearch.vue';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { useAppearance } from '@/composables/useAppearance';
import { useCan } from '@/composables/useCan';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useLocale } from '@/composables/useLocale';
import { endpoints, withQuery } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { appNavigation, settingsNavigation } from '@/lib/navigation';
import { pfPaletteActions } from '@/lib/pf/palette';
import type { PfPaletteActionId } from '@/lib/pf/palette';
import { toUrl } from '@/lib/utils';
import { logout } from '@/routes';
import type { Appearance } from '@/types';

const page = usePage();
const { open, hide, toggle } = useCommandPalette();
const { can } = useCan();
const { updateAppearance } = useAppearance();
const { locales, setLocale } = useLocale();

useEventListener('keydown', (event: KeyboardEvent) => {
    if (
        (event.metaKey || event.ctrlKey) &&
        !event.altKey &&
        !event.shiftKey &&
        (event.code === 'KeyK' || event.key.toLowerCase() === 'k')
    ) {
        event.preventDefault();
        toggle();
    }
});

const hasWorkspace = computed(() => Boolean(page.props.workspace));

const navigation = computed(() => {
    const seen = new Set<string>();

    return [
        ...appNavigation(can, hasWorkspace.value),
        ...settingsNavigation(can, hasWorkspace.value),
    ]
        .flatMap((group) => group.items)
        .filter((item) => {
            const url = toUrl(item.href);

            if (seen.has(url)) {
                return false;
            }

            seen.add(url);

            return true;
        });
});

const searchHasHits = ref(false);

const pfActionIcons: Record<PfPaletteActionId, typeof Plus> = {
    create_invoice: FilePlus2,
    record_payment: Banknote,
    new_payout: HandCoins,
    request_bank_change: Landmark,
    import_csv: Upload,
};

type PaletteAction = {
    id: string;
    label: string;
    icon: typeof Plus;
    run: () => void;
};

const actions = computed<PaletteAction[]>(() => {
    const list: PaletteAction[] = [];

    if (hasWorkspace.value) {
        for (const action of pfPaletteActions(
            (ability) => page.props.can?.[ability] === true,
            {
                component: page.component ?? null,
                props: page.props as Record<string, unknown>,
            },
            (id) => t(`pf.palette.actions.${id}`),
        )) {
            list.push({
                id: `pf-${action.id}`,
                label: action.label,
                icon: pfActionIcons[action.id],
                run: () => router.visit(action.href),
            });
        }

        list.push({
            id: 'ask',
            label: t('ui.command.actions.ask_assistant'),
            icon: MessageSquarePlus,
            run: () => router.visit(endpoints.assistant.index().url),
        });

        if (can('knowledge.manage', 'workspace.manage')) {
            list.push({
                id: 'upload',
                label: t('ui.command.actions.upload_document'),
                icon: FileUp,
                run: () =>
                    router.visit(
                        withQuery(endpoints.knowledge.index().url, {
                            upload: 1,
                        }),
                    ),
            });
        }

        if (can('members.manage', 'workspace.manage')) {
            list.push({
                id: 'invite',
                label: t('ui.command.actions.invite_member'),
                icon: UserPlus,
                run: () =>
                    router.visit(
                        withQuery(endpoints.settings.members.index().url, {
                            invite: 1,
                        }),
                    ),
            });
        }
    }

    list.push({
        id: 'create-workspace',
        label: t('ui.command.actions.create_workspace'),
        icon: Plus,
        run: () => router.visit(endpoints.onboarding.create().url),
    });

    return list;
});

const workspaces = computed(() =>
    (page.props.workspaces ?? []).filter(
        (workspace) => workspace.id !== page.props.workspace?.id,
    ),
);

const themes: Array<{ value: Appearance; icon: typeof Sun; key: string }> = [
    { value: 'light', icon: Sun, key: 'ui.theme.light' },
    { value: 'dark', icon: Moon, key: 'ui.theme.dark' },
    { value: 'system', icon: Monitor, key: 'ui.theme.system' },
];

function run(callback: () => void): void {
    hide();
    callback();
}
</script>

<template>
    <CommandDialog
        v-model:open="open"
        :title="t('ui.command.title')"
        :description="t('ui.command.description')"
    >
        <CommandInput :placeholder="t('ui.command.placeholder')" />
        <CommandList
            class="max-h-[min(26rem,60vh)]"
            :aria-label="t('ui.command.title')"
        >
            <CommandEmpty v-if="!searchHasHits">{{
                t('ui.command.empty')
            }}</CommandEmpty>

            <PfPaletteSearch
                v-if="open && hasWorkspace"
                v-model:has-hits="searchHasHits"
                @select="(href: string) => run(() => router.visit(href))"
            />

            <CommandGroup :heading="t('ui.command.groups.navigation')">
                <CommandItem
                    v-for="item in navigation"
                    :key="toUrl(item.href)"
                    :value="`nav ${t(item.title)} ${toUrl(item.href)}`"
                    @select="run(() => router.visit(toUrl(item.href)))"
                >
                    <component
                        :is="item.icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ t(item.title) }}
                </CommandItem>
            </CommandGroup>

            <CommandSeparator />

            <CommandGroup :heading="t('ui.command.groups.actions')">
                <CommandItem
                    v-for="action in actions"
                    :key="action.id"
                    :value="`action ${action.label}`"
                    @select="run(action.run)"
                >
                    <component
                        :is="action.icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ action.label }}
                </CommandItem>
            </CommandGroup>

            <template v-if="workspaces.length">
                <CommandSeparator />
                <CommandGroup :heading="t('ui.command.groups.workspaces')">
                    <CommandItem
                        v-for="workspace in workspaces"
                        :key="workspace.id"
                        :value="`workspace ${workspace.name}`"
                        @select="
                            run(() =>
                                router.post(
                                    endpoints.workspaces.switch(workspace.id)
                                        .url,
                                ),
                            )
                        "
                    >
                        <Building2 class="size-4" aria-hidden="true" />
                        {{
                            t('ui.command.actions.switch_workspace', {
                                name: workspace.name,
                            })
                        }}
                    </CommandItem>
                </CommandGroup>
            </template>

            <CommandSeparator />

            <CommandGroup :heading="t('ui.command.groups.preferences')">
                <CommandItem
                    v-for="theme in themes"
                    :key="theme.value"
                    :value="`theme ${t(theme.key)}`"
                    @select="run(() => updateAppearance(theme.value))"
                >
                    <component
                        :is="theme.icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ t('ui.command.actions.theme', { theme: t(theme.key) }) }}
                </CommandItem>
                <CommandItem
                    v-for="option in locales"
                    :key="option.code"
                    :value="`language ${option.label} ${option.code}`"
                    @select="run(() => setLocale(option.code))"
                >
                    <Languages class="size-4" aria-hidden="true" />
                    {{
                        t('ui.command.actions.language', {
                            language: option.label,
                        })
                    }}
                </CommandItem>
            </CommandGroup>

            <template v-if="page.props.auth.user">
                <CommandSeparator />
                <CommandGroup :heading="t('ui.command.groups.account')">
                    <CommandItem
                        :value="`account ${t('ui.shell.log_out')}`"
                        @select="
                            run(() => {
                                router.flushAll();
                                router.post(logout().url);
                            })
                        "
                    >
                        <LogOut class="size-4" aria-hidden="true" />
                        {{ t('ui.shell.log_out') }}
                    </CommandItem>
                </CommandGroup>
            </template>
        </CommandList>
    </CommandDialog>
</template>
