<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Clock3,
    MailX,
    Send,
    ShieldCheck,
    UserMinus,
    UserPlus,
    UsersRound,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import InviteMemberDialog from '@/components/members/InviteMemberDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t, tc, tOr } from '@/lib/i18n';
import type { Invitation, Member, SelectOption } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'ui.settings.title',
                href: endpoints.settings.workspace.show(),
            },
            {
                title: 'ui.settings.nav.members',
                href: endpoints.settings.members.index(),
            },
        ],
    },
});

const props = withDefaults(
    defineProps<{
        members: Member[];
        invitations: Invitation[];
        roles: SelectOption[];
        assignableRoles?: string[] | null;
    }>(),
    { assignableRoles: null },
);

const { can } = useCan();
const fmt = useFormat();
const canManage = computed(() => can('members.manage', 'workspace.manage'));

const inviteOpen = ref(false);

onMounted(() => {
    if (
        canManage.value &&
        new URLSearchParams(window.location.search).get('invite') === '1'
    ) {
        inviteOpen.value = true;
    }
});

const roleLabel = (role: string) =>
    tOr(
        `roles.${role}`,
        props.roles.find((option) => option.value === role)?.label ?? role,
    );

const memberColumns = computed<DataColumn<Member>[]>(() => [
    { id: 'name', header: t('ui.members.member'), sortable: true },
    { id: 'role', header: t('ui.members.role'), sortable: true },
    {
        id: 'joined_at',
        header: t('ui.members.joined'),
        sortable: true,
        hideBelow: 'md',
    },
    {
        id: 'telegram_linked',
        header: t('ui.members.telegram'),
        hideBelow: 'lg',
    },
    {
        id: 'actions',
        header: t('ui.common.actions'),
        align: 'right',
        srOnlyHeader: true,
    },
]);

const inviteColumns = computed<DataColumn<Invitation>[]>(() => [
    { id: 'email', header: t('ui.fields.email'), sortable: true },
    { id: 'role', header: t('ui.members.role') },
    { id: 'invited_by', header: t('ui.members.invited_by'), hideBelow: 'md' },
    {
        id: 'expires_at',
        header: t('ui.members.expires'),
        sortable: true,
        hideBelow: 'sm',
    },
    {
        id: 'actions',
        header: t('ui.common.actions'),
        align: 'right',
        srOnlyHeader: true,
    },
]);

const editable = (member: Member) =>
    canManage.value && !member.is_you && member.role !== 'owner';
const assignableRoles = computed(() =>
    props.assignableRoles
        ? props.roles.filter((role) =>
              props.assignableRoles?.includes(role.value),
          )
        : props.roles.filter((role) => role.value !== 'owner'),
);
const canLeave = (member: Member) => member.is_you && member.role !== 'owner';

const updatingId = ref<number | null>(null);

function changeRole(member: Member, role: unknown): void {
    if (typeof role !== 'string' || role === member.role) {
        return;
    }

    router.patch(
        endpoints.settings.members.updateRole(member.id).url,
        { role },
        {
            preserveScroll: true,
            onStart: () => {
                updatingId.value = member.id;
            },
            onFinish: () => {
                updatingId.value = null;
            },
        },
    );
}

const removeTarget = ref<Member | null>(null);
const revokeTarget = ref<Invitation | null>(null);
const busy = ref(false);

const removeOpen = computed({
    get: () => removeTarget.value !== null,
    set: (value) => {
        if (!value) {
            removeTarget.value = null;
        }
    },
});

const revokeOpen = computed({
    get: () => revokeTarget.value !== null,
    set: (value) => {
        if (!value) {
            revokeTarget.value = null;
        }
    },
});

function removeMember(): void {
    if (!removeTarget.value) {
        return;
    }

    router.delete(
        endpoints.settings.members.remove(removeTarget.value.id).url,
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
                removeTarget.value = null;
            },
        },
    );
}

function revokeInvitation(): void {
    if (!revokeTarget.value) {
        return;
    }

    router.delete(
        endpoints.settings.members.revokeInvitation(revokeTarget.value.id).url,
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
                revokeTarget.value = null;
            },
        },
    );
}

const isExpired = (invitation: Invitation) =>
    new Date(invitation.expires_at).getTime() < Date.now();

const linkedCount = computed(
    () => props.members.filter((member) => member.telegram_linked).length,
);
</script>

<template>
    <Head :title="t('ui.members.title')" />

    <SectionCard
        :icon="UsersRound"
        :title="t('ui.members.title')"
        :description="t('ui.members.description')"
        flush
    >
        <template #actions>
            <Button
                v-if="canManage"
                data-test="invite-member-button"
                @click="inviteOpen = true"
            >
                <UserPlus class="size-4" aria-hidden="true" />
                {{ t('ui.members.invite') }}
            </Button>
        </template>

        <dl class="grid grid-cols-3 divide-x border-b text-center">
            <div class="px-3 py-4">
                <dt class="text-xs text-muted-foreground">
                    {{ t('ui.members.stat_members') }}
                </dt>
                <dd class="font-display text-xl font-bold tabular-nums">
                    {{ fmt.number(members.length) }}
                </dd>
            </div>
            <div class="px-3 py-4">
                <dt class="text-xs text-muted-foreground">
                    {{ t('ui.members.stat_pending') }}
                </dt>
                <dd class="font-display text-xl font-bold tabular-nums">
                    {{ fmt.number(invitations.length) }}
                </dd>
            </div>
            <div class="px-3 py-4">
                <dt class="text-xs text-muted-foreground">
                    {{ t('ui.members.stat_telegram') }}
                </dt>
                <dd class="font-display text-xl font-bold tabular-nums">
                    {{ fmt.number(linkedCount) }}
                </dd>
            </div>
        </dl>

        <div class="p-4 sm:p-5">
            <DataTable
                :columns="memberColumns"
                :rows="members"
                :row-key="(member) => member.id"
                :row-attrs="
                    (member) => ({
                        'data-test': 'member-row',
                        'data-email': member.email,
                    })
                "
                :caption="tc('ui.members.count', members.length)"
                class="shadow-none"
            >
                <template #cell-name="{ row }">
                    <div class="flex min-w-0 items-center gap-3">
                        <UserAvatar :name="row.name" />
                        <div class="max-w-[9.5rem] min-w-0 sm:max-w-none">
                            <p
                                class="flex items-center gap-2 truncate font-medium"
                            >
                                {{ row.name }}
                                <span
                                    v-if="row.is_you"
                                    class="rounded-full bg-muted px-1.5 py-0.5 text-[0.6875rem] font-semibold text-muted-foreground"
                                    >{{ t('ui.members.you') }}</span
                                >
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ row.email }}
                            </p>
                        </div>
                    </div>
                </template>
                <template #cell-role="{ row }">
                    <Select
                        v-if="editable(row)"
                        :model-value="row.role"
                        :disabled="updatingId === row.id"
                        @update:model-value="(value) => changeRole(row, value)"
                    >
                        <SelectTrigger
                            class="h-8 w-32 sm:w-36"
                            :aria-label="
                                t('ui.members.change_role_for', {
                                    name: row.name,
                                })
                            "
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="role in assignableRoles"
                                :key="role.value"
                                :value="role.value"
                            >
                                {{ roleLabel(role.value) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <StatusBadge
                        v-else
                        :tone="
                            row.role === 'owner'
                                ? 'ai'
                                : row.role === 'admin'
                                  ? 'info'
                                  : 'neutral'
                        "
                        :icon="row.role === 'owner' ? ShieldCheck : undefined"
                        :label="roleLabel(row.role)"
                    />
                </template>
                <template #cell-joined_at="{ row }">
                    <span class="text-muted-foreground"
                        ><RelativeTime :value="row.joined_at"
                    /></span>
                </template>
                <template #cell-telegram_linked="{ row }">
                    <StatusBadge
                        :tone="row.telegram_linked ? 'success' : 'neutral'"
                        :label="
                            row.telegram_linked
                                ? t('ui.members.telegram_linked')
                                : t('ui.members.telegram_not_linked')
                        "
                    />
                </template>
                <template #cell-actions="{ row }">
                    <Button
                        v-if="canLeave(row)"
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground hover:text-destructive"
                        @click="removeTarget = row"
                    >
                        {{ t('ui.members.leave') }}
                    </Button>
                    <Button
                        v-else-if="editable(row)"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground hover:text-destructive"
                        :aria-label="
                            t('ui.members.remove_named', { name: row.name })
                        "
                        @click="removeTarget = row"
                    >
                        <UserMinus class="size-4" aria-hidden="true" />
                    </Button>
                </template>
            </DataTable>
        </div>
    </SectionCard>

    <SectionCard
        :icon="Send"
        :title="t('ui.members.pending_title')"
        :description="t('ui.members.pending_description')"
        flush
    >
        <div class="p-4 sm:p-5">
            <DataTable
                v-if="invitations.length"
                :columns="inviteColumns"
                :rows="invitations"
                :row-key="(invitation) => invitation.id"
                :row-attrs="
                    (invitation) => ({
                        'data-test': 'invitation-row',
                        'data-email': invitation.email,
                    })
                "
                :caption="t('ui.members.pending_title')"
                class="shadow-none"
            >
                <template #cell-email="{ row }">
                    <span class="font-medium">{{ row.email }}</span>
                </template>
                <template #cell-role="{ row }">
                    <StatusBadge tone="neutral" :label="roleLabel(row.role)" />
                </template>
                <template #cell-expires_at="{ row }">
                    <StatusBadge
                        v-if="isExpired(row)"
                        tone="warning"
                        :icon="Clock3"
                        :label="t('ui.members.expired')"
                    />
                    <span v-else class="text-muted-foreground"
                        ><RelativeTime :value="row.expires_at"
                    /></span>
                </template>
                <template #cell-actions="{ row }">
                    <Button
                        v-if="canManage"
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground hover:text-destructive"
                        :aria-label="
                            t('ui.members.revoke_named', { email: row.email })
                        "
                        @click="revokeTarget = row"
                    >
                        <MailX class="size-4" aria-hidden="true" />
                        <span class="hidden sm:inline">{{
                            t('ui.members.revoke')
                        }}</span>
                    </Button>
                </template>
            </DataTable>
            <EmptyState
                v-else
                compact
                tone="muted"
                :icon="Send"
                :title="t('ui.members.pending_empty_title')"
                :description="t('ui.members.pending_empty_body')"
            >
                <Button
                    v-if="canManage"
                    variant="outline"
                    @click="inviteOpen = true"
                >
                    <UserPlus class="size-4" aria-hidden="true" />
                    {{ t('ui.members.invite') }}
                </Button>
            </EmptyState>
        </div>
    </SectionCard>

    <InviteMemberDialog
        v-if="canManage"
        v-model:open="inviteOpen"
        :roles="assignableRoles.length ? assignableRoles : roles"
    />

    <ConfirmDialog
        v-model:open="removeOpen"
        destructive
        :processing="busy"
        :title="
            removeTarget?.is_you
                ? t('ui.members.leave_title')
                : t('ui.members.remove_title', {
                      name: removeTarget?.name ?? '',
                  })
        "
        :description="
            removeTarget?.is_you
                ? t('ui.members.leave_body')
                : t('ui.members.remove_body')
        "
        :confirm-label="
            removeTarget?.is_you
                ? t('ui.members.leave')
                : t('ui.members.remove')
        "
        @confirm="removeMember"
    />

    <ConfirmDialog
        v-model:open="revokeOpen"
        destructive
        :processing="busy"
        :title="t('ui.members.revoke_title')"
        :description="
            t('ui.members.revoke_body', { email: revokeTarget?.email ?? '' })
        "
        :confirm-label="t('ui.members.revoke')"
        @confirm="revokeInvitation"
    />
</template>
