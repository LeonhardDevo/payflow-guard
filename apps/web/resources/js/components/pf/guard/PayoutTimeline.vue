<script setup lang="ts">
import {
    BadgeCheck,
    Ban,
    CircleDot,
    CirclePause,
    CircleX,
    FilePen,
    FilePlus,
    RotateCcw,
    Send,
    ShieldAlert,
    Wallet,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { humanize, t, tOr } from '@/lib/i18n';
import type { TimelineEvent } from '@/types/payflow-guard';

const props = defineProps<{ events: TimelineEvent[]; currency: string }>();
const fmt = useFormat();
const pf = usePf();

const ICONS: Record<string, Component> = {
    created: FilePlus,
    updated: FilePen,
    submitted: Send,
    resubmitted: RotateCcw,
    auto_approved: Zap,
    approved: BadgeCheck,
    rejected: CircleX,
    held: CirclePause,
    released: Wallet,
    canceled: Ban,
    approvals_invalidated: ShieldAlert,
};

const TONES: Record<string, string> = {
    approved: 'bg-success-soft text-success-soft-foreground',
    auto_approved: 'bg-success-soft text-success-soft-foreground',
    released: 'bg-success-soft text-success-soft-foreground',
    rejected: 'bg-destructive-soft text-destructive-soft-foreground',
    canceled: 'bg-destructive-soft text-destructive-soft-foreground',
    held: 'bg-warning-soft text-warning-soft-foreground',
    approvals_invalidated: 'bg-warning-soft text-warning-soft-foreground',
    submitted: 'bg-info-soft text-info-soft-foreground',
    resubmitted: 'bg-info-soft text-info-soft-foreground',
};

function kind(event: TimelineEvent): string {
    return event.type === 'created' && event.data.updated === true
        ? 'updated'
        : event.type;
}

function text(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function details(event: TimelineEvent): string[] {
    const data = event.data ?? {};
    const out: string[] = [];
    const currency = text(data.currency) ?? props.currency;

    if (typeof data.amount_minor === 'number' && event.type !== 'released') {
        out.push(pf.money({ minor: data.amount_minor, currency }));
    }

    const channel = text(data.channel);

    if (channel) {
        out.push(tOr(`guard.payout.channel.${channel}`, humanize(channel)));
    }

    const contact = text(data.contact);

    if (contact) {
        out.push(contact);
    }

    const approvals = text(data.approvals);

    if (approvals) {
        out.push(t('guard.timeline.approvals', { progress: approvals }));
    }

    const method = text(data.step_up_method);

    if (method && method !== 'none') {
        out.push(tOr(`guard.stepup.methods.${method}`, humanize(method)));
    }

    if (event.type === 'held' && text(data.reason)) {
        out.push(
            tOr(
                `guard.hold_reason.${String(data.reason)}`,
                humanize(String(data.reason)),
            ),
        );
    }

    if (event.type === 'resubmitted' && data.automatic === true) {
        out.push(t('guard.timeline.automatic'));
    }

    if (
        (event.type === 'resubmitted' ||
            event.type === 'approvals_invalidated') &&
        typeof data.round === 'number'
    ) {
        out.push(t('guard.payout.round', { round: data.round }));
    }

    if (event.type === 'released') {
        const paidOn = text(data.paid_on);

        if (paidOn) {
            out.push(
                t('guard.timeline.paid_on', { date: fmt.value.date(paidOn) }),
            );
        }

        const reference = text(data.paid_reference);

        if (reference) {
            out.push(reference);
        }
    }

    const note =
        event.type === 'held'
            ? null
            : (text(data.reason) ?? text(data.comment));

    if (note) {
        out.push(note);
    }

    return out;
}
</script>

<template>
    <ol class="relative space-y-4" data-test="payout-timeline">
        <li
            v-for="event in events"
            :key="event.id"
            class="relative flex gap-3"
            :data-type="kind(event)"
        >
            <span
                :class="[
                    'inline-flex size-8 shrink-0 items-center justify-center rounded-full',
                    TONES[kind(event)] ?? 'bg-muted text-muted-foreground',
                ]"
            >
                <component
                    :is="ICONS[kind(event)] ?? CircleDot"
                    class="size-4"
                    aria-hidden="true"
                />
            </span>
            <div class="min-w-0 text-sm">
                <p class="font-medium">
                    {{
                        tOr(
                            `guard.timeline.events.${kind(event)}`,
                            humanize(kind(event)),
                        )
                    }}
                    <span
                        v-for="(item, index) in details(event)"
                        :key="index"
                        class="font-normal text-muted-foreground"
                    >
                        · {{ item }}</span
                    >
                </p>
                <p class="text-xs text-muted-foreground">
                    <time :datetime="event.occurred_at">{{
                        fmt.dateTime(event.occurred_at, 'medium')
                    }}</time>
                    ·
                    {{ event.actor.user?.name ?? t('guard.timeline.system') }}
                </p>
            </div>
        </li>
    </ol>
</template>
