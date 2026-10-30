<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Ban,
    CircleX,
    Hourglass,
    Mail,
    Monitor,
    Send,
    Wallet,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useCountdown } from '@/composables/pf/useCountdown';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { approvalSlots, initials } from '@/lib/pf/guard';
import type { ApprovalChannel, PayoutDetail } from '@/types/payflow-guard';

const props = defineProps<{ payout: PayoutDetail }>();
const page = usePage();
const fmt = useFormat();

type NodeState = 'done' | 'failed' | 'current' | 'waiting' | 'cooling';
type Node = {
    key: string;
    state: NodeState;
    label: string;
    sub: string | null;
    meta: string | null;
    badge: string | null;
    icon: Component | null;
    channel: ApprovalChannel | null;
    mine: boolean;
};

const CHANNEL_ICON: Record<ApprovalChannel, Component> = {
    web: Monitor,
    telegram: Send,
    trusted_contact: Mail,
};

const p = computed(() => props.payout);
const viewerId = computed(() => page.props.auth?.user?.id ?? null);
const live = computed(
    () => !['released', 'rejected', 'canceled'].includes(p.value.status),
);

const cooling = useCountdown(() =>
    live.value ? (p.value.bank_account?.cooling_off_until ?? null) : null,
);
const releaseDelay = useCountdown(() =>
    p.value.status === 'approved' ? p.value.release_not_before : null,
);

const time = (value: string | null) =>
    value ? fmt.value.dateTime(value, 'short') : null;

const nodes = computed<Node[]>(() => {
    const payout = p.value;
    const list: Node[] = [
        {
            key: 'requested',
            state: payout.status === 'draft' ? 'current' : 'done',
            label: t(
                payout.status === 'draft'
                    ? 'guard.stepper.draft'
                    : 'guard.stepper.requested',
            ),
            sub: payout.requested_by.name,
            meta: time(payout.submitted_at ?? payout.created_at),
            badge: initials(payout.requested_by.name),
            icon: null,
            channel: null,
            mine: payout.requested_by.id === viewerId.value,
        },
    ];

    const slots = approvalSlots(payout, viewerId.value);

    if (!slots.length && payout.status !== 'draft') {
        list.push({
            key: 'auto',
            state: payout.auto_approved ? 'done' : 'waiting',
            label: t(
                payout.auto_approved
                    ? 'guard.stepper.auto'
                    : 'guard.stepper.no_approval_needed',
            ),
            sub: null,
            meta: time(payout.approved_at),
            badge: null,
            icon: Zap,
            channel: null,
            mine: false,
        });
    }

    slots.forEach((slot, index) => {
        const approval = slot.approval;
        const name =
            approval?.user?.name ?? approval?.trusted_contact?.name ?? null;

        list.push({
            key: slot.key,
            state:
                slot.state === 'approved'
                    ? 'done'
                    : slot.state === 'rejected'
                      ? 'failed'
                      : slot.next
                        ? 'current'
                        : 'waiting',
            label: slot.mine
                ? approval
                    ? t('guard.stepper.you')
                    : t('guard.stepper.your_turn')
                : t('guard.stepper.approval', { n: index + 1 }),
            sub: approval
                ? `${name ?? '—'} · ${t(`guard.payout.decision.${approval.decision}`)}`
                : t('guard.stepper.waiting'),
            meta: approval ? time(approval.created_at) : null,
            badge: approval
                ? initials(name)
                : slot.mine
                  ? initials(page.props.auth?.user?.name)
                  : null,
            icon: approval ? null : slot.mine ? null : BadgeCheck,
            channel: approval?.channel ?? null,
            mine: slot.mine,
        });
    });

    const confirmation = payout.trusted_confirmation;

    if (confirmation) {
        list.push({
            key: 'trusted',
            state:
                confirmation.status === 'confirmed'
                    ? 'done'
                    : confirmation.status === 'pending'
                      ? 'current'
                      : 'failed',
            label: t('guard.stepper.trusted'),
            sub: confirmation.contact_name,
            meta: t(`guard.trusted_status.${confirmation.status}`),
            badge: null,
            icon: Mail,
            channel: null,
            mine: false,
        });
    }

    if (cooling.label.value) {
        list.push({
            key: 'cooling',
            state: 'cooling',
            label: t('guard.stepper.cooling'),
            sub: t('guard.stepper.cooling_left', {
                time: cooling.label.value,
            }),
            meta: time(payout.bank_account?.cooling_off_until ?? null),
            badge: null,
            icon: Hourglass,
            channel: null,
            mine: false,
        });
    }

    list.push(releaseNode());

    return list;
});

function releaseNode(): Node {
    const payout = p.value;
    const base = {
        key: 'release',
        label: t('guard.stepper.release'),
        meta: null,
        badge: null,
        channel: null,
        mine: false,
    };

    switch (payout.status) {
        case 'released':
            return {
                ...base,
                state: 'done',
                sub: t('guard.stepper.released', {
                    date: fmt.value.date(payout.paid_on ?? payout.released_at),
                }),
                meta: payout.released_by?.name ?? null,
                icon: Wallet,
            };
        case 'approved':
            return {
                ...base,
                state: 'current',
                sub: releaseDelay.label.value
                    ? t('guard.stepper.release_in', {
                          time: releaseDelay.label.value,
                      })
                    : t('guard.stepper.release_ready'),
                icon: Wallet,
            };
        case 'rejected':
        case 'canceled':
            return {
                ...base,
                state: 'failed',
                sub: t('guard.stepper.stopped'),
                icon: Ban,
            };
        case 'on_hold':
            return {
                ...base,
                state: 'waiting',
                sub: t('guard.stepper.on_hold'),
                icon: Wallet,
            };
        default:
            return {
                ...base,
                state: 'waiting',
                sub: t('guard.stepper.release_waiting'),
                icon: Wallet,
            };
    }
}

const circle: Record<NodeState, string> = {
    done: 'border-primary bg-primary text-primary-foreground',
    failed: 'border-destructive bg-destructive-soft text-destructive-soft-foreground',
    current: 'border-primary bg-card text-primary ring-4 ring-primary/15',
    waiting: 'border-dashed border-border bg-card text-muted-foreground',
    cooling:
        'border-warning bg-warning-soft text-warning-soft-foreground [background-image:repeating-linear-gradient(135deg,transparent_0_4px,color-mix(in_oklch,var(--warning)_25%,transparent)_4px_8px)]',
};

const line: Record<NodeState, string> = {
    done: 'bg-primary',
    failed: 'bg-destructive/60',
    current: 'bg-primary/40',
    waiting: 'bg-border',
    cooling:
        'bg-warning/30 [background-image:repeating-linear-gradient(90deg,var(--warning)_0_6px,transparent_6px_10px)]',
};
</script>

<template>
    <section
        class="rounded-2xl border bg-card p-4 shadow-soft sm:p-5"
        :aria-label="t('guard.stepper.title')"
        data-test="approval-stepper"
    >
        <p class="mb-4 text-xs font-medium text-muted-foreground">
            {{ t('guard.stepper.title') }}
        </p>
        <ol class="flex flex-col gap-4 sm:flex-row sm:gap-0">
            <li
                v-for="(node, index) in nodes"
                :key="node.key"
                class="relative flex min-w-0 items-start gap-3 sm:flex-1 sm:flex-col sm:items-center sm:gap-2 sm:text-center"
                :data-step="node.key"
                :data-state="node.state"
                :data-mine="node.mine || undefined"
                :aria-current="node.state === 'current' ? 'step' : undefined"
            >
                <span
                    v-if="index > 0"
                    :class="[
                        'absolute top-[1.125rem] right-1/2 hidden h-0.5 w-full sm:block',
                        line[node.state],
                    ]"
                    aria-hidden="true"
                />
                <span
                    :class="[
                        'relative z-10 inline-flex size-9 shrink-0 items-center justify-center rounded-full border-2 text-xs font-semibold transition-colors duration-300 motion-reduce:transition-none',
                        circle[node.state],
                        node.mine
                            ? 'outline-2 outline-offset-2 outline-ai'
                            : '',
                    ]"
                >
                    <component
                        :is="node.icon"
                        v-if="node.icon && !node.badge"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <template v-else-if="node.badge">{{ node.badge }}</template>
                    <CircleX v-else class="size-4" aria-hidden="true" />
                    <span
                        v-if="node.channel"
                        class="absolute -right-1.5 -bottom-1.5 inline-flex size-5 items-center justify-center rounded-full border bg-card text-muted-foreground"
                    >
                        <component
                            :is="CHANNEL_ICON[node.channel]"
                            class="size-3"
                            :aria-label="
                                t(`guard.payout.channel.${node.channel}`)
                            "
                        />
                    </span>
                </span>
                <span class="min-w-0 text-sm sm:px-1">
                    <span
                        :class="[
                            'block font-medium',
                            node.mine ? 'text-ai-soft-foreground' : '',
                        ]"
                        >{{ node.label }}</span
                    >
                    <span
                        v-if="node.sub"
                        class="block text-xs break-words text-muted-foreground"
                        >{{ node.sub }}</span
                    >
                    <span
                        v-if="node.meta"
                        class="block text-xs text-muted-foreground tabular-nums"
                        >{{ node.meta }}</span
                    >
                </span>
            </li>
        </ol>
    </section>
</template>
