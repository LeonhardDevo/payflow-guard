<script setup lang="ts">
import {
    Ban,
    BellRing,
    CircleAlert,
    CircleCheck,
    CirclePause,
    CirclePlay,
    Eye,
    FilePen,
    FilePlus,
    Gauge,
    Link2,
    Mail,
    Scale,
    Send,
    Upload,
    Wallet,
} from '@lucide/vue';
import type { Component } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { humanize, t, tOr } from '@/lib/i18n';
import type { CurrencyCode, TimelineEvent } from '@/types/payflow';

const props = defineProps<{
    events: TimelineEvent[];
    showInvoice?: boolean;
    currency?: CurrencyCode | null;
}>();

const fmt = useFormat();
const pf = usePf();

const icons: Record<string, Component> = {
    created: FilePlus,
    updated: FilePen,
    issued: Send,
    sent: Mail,
    marked_sent: Send,
    viewed: Eye,
    reminder_sent: BellRing,
    reminder_deferred: BellRing,
    reminder_failed: CircleAlert,
    reminder_skipped: BellRing,
    payment_received: Wallet,
    payment_failed: CircleAlert,
    payment_refunded: Wallet,
    payment_reversed: Wallet,
    link_created: Link2,
    link_deactivated: Link2,
    overpaid: Wallet,
    paid: CircleCheck,
    disputed: Scale,
    dispute_resolved: Scale,
    late_fee_applied: Gauge,
    voided: Ban,
    written_off: Ban,
    reminders_paused: CirclePause,
    reminders_resumed: CirclePlay,
    reminders_activated: CirclePlay,
    recipient_opted_out: CirclePause,
    client_reported_paid: CircleCheck,
    imported: Upload,
    risk_band_changed: Gauge,
};

const tones: Record<string, string> = {
    payment_received: 'bg-success-soft text-success-soft-foreground',
    paid: 'bg-success-soft text-success-soft-foreground',
    client_reported_paid: 'bg-success-soft text-success-soft-foreground',
    reminder_failed: 'bg-destructive-soft text-destructive-soft-foreground',
    payment_failed: 'bg-destructive-soft text-destructive-soft-foreground',
    voided: 'bg-destructive-soft text-destructive-soft-foreground',
    written_off: 'bg-destructive-soft text-destructive-soft-foreground',
    disputed: 'bg-warning-soft text-warning-soft-foreground',
    recipient_opted_out: 'bg-warning-soft text-warning-soft-foreground',
    late_fee_applied: 'bg-warning-soft text-warning-soft-foreground',
    reminder_sent: 'bg-info-soft text-info-soft-foreground',
    sent: 'bg-info-soft text-info-soft-foreground',
    issued: 'bg-info-soft text-info-soft-foreground',
};

function amount(data: TimelineEvent['data'], key: string): string | null {
    const value = data[key];
    const currency =
        typeof data.currency === 'string' ? data.currency : props.currency;

    if (typeof value !== 'number' || !currency) {
        return null;
    }

    return pf.money({ minor: value, currency });
}

function reasonText(event: TimelineEvent, reason: string): string {
    if (event.type === 'link_deactivated') {
        return tOr(`pf.timeline.link_reason.${reason}`, humanize(reason));
    }

    if (event.type === 'reminder_skipped') {
        return tOr(`pf.skip_reason.${reason}`, humanize(reason));
    }

    return reason;
}

function details(event: TimelineEvent): string[] {
    const data = event.data ?? {};
    const out: string[] = [];

    if (event.type === 'overpaid') {
        const over = amount(data, 'amount_minor');

        if (over) {
            out.push(t('pf.timeline.overpaid_by', { amount: over }));
        }
    } else {
        const main =
            amount(data, 'amount_minor') ??
            amount(data, 'refunded_minor') ??
            amount(data, 'balance_minor');

        if (main) {
            out.push(main);
        }
    }

    if (event.type === 'late_fee_applied' && typeof data.days === 'number') {
        out.push(t('pf.timeline.late_fee_days', { days: pf.days(data.days) }));
    }

    if (typeof data.tone === 'string') {
        out.push(t(`pf.tone.${data.tone}`));
    }

    if (typeof data.method === 'string') {
        out.push(
            tOr(`pf.payment_method.${data.method}`, humanize(data.method)),
        );
    }

    if (typeof data.band === 'string') {
        out.push(t(`pf.risk.${data.band}`));
    }

    if (
        event.type === 'risk_band_changed' &&
        typeof data.from === 'string' &&
        typeof data.to === 'string'
    ) {
        out.push(
            t('pf.timeline.band_change', {
                from: pf.label('risk', data.from),
                to: pf.label('risk', data.to),
            }),
        );
    }

    if (event.type === 'dispute_resolved' && typeof data.status === 'string') {
        out.push(pf.label('status', data.status));
    }

    if (event.type === 'created' && data.source === 'import') {
        out.push(t('pf.timeline.from_import'));
    }

    if (typeof data.paid_on === 'string' && data.paid_on) {
        out.push(
            t('pf.timeline.paid_on', { date: fmt.value.date(data.paid_on) }),
        );
    }

    if (typeof data.reference === 'string' && data.reference) {
        out.push(data.reference);
    }

    if (
        event.type === 'reminders_paused' &&
        typeof data.until === 'string' &&
        data.until
    ) {
        out.push(t('pf.timeline.until', { date: fmt.value.date(data.until) }));
    }

    if (typeof data.reason === 'string' && data.reason) {
        out.push(reasonText(event, data.reason));
    }

    return out;
}

function actorName(event: TimelineEvent): string {
    if (event.actor.user) {
        return event.actor.user.name;
    }

    return t(`pf.actor.${event.actor.type}`);
}
</script>

<template>
    <ol class="relative space-y-1 border-l pl-6" data-test="pf-timeline">
        <li
            v-for="event in events"
            :key="event.id"
            class="relative py-1.5"
            :data-type="event.type"
        >
            <span
                :class="[
                    'absolute top-2 -left-[2.3rem] inline-flex size-7 items-center justify-center rounded-full ring-4 ring-background',
                    tones[event.type] ?? 'bg-muted text-muted-foreground',
                ]"
                aria-hidden="true"
            >
                <component
                    :is="icons[event.type] ?? FilePen"
                    class="size-3.5"
                />
            </span>
            <p class="text-sm leading-snug">
                <span class="font-medium">{{
                    tOr(`pf.events.${event.type}`, humanize(event.type))
                }}</span>
                <template v-if="showInvoice && event.data?.number">
                    <span class="text-muted-foreground"> · </span>
                    <span class="font-mono text-xs">{{
                        event.data.number
                    }}</span>
                </template>
                <template v-for="item in details(event)" :key="item">
                    <span class="text-muted-foreground"> · </span>
                    <span class="tabular-nums">{{ item }}</span>
                </template>
            </p>
            <p class="text-xs text-muted-foreground">
                <time :datetime="event.occurred_at">{{
                    fmt.dateTime(event.occurred_at, 'medium')
                }}</time>
                · {{ actorName(event) }}
            </p>
        </li>
    </ol>
</template>
