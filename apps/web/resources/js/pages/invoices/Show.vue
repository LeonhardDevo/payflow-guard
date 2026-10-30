<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Ban,
    BellRing,
    Building2,
    CalendarClock,
    CircleAlert,
    CircleCheck,
    CirclePause,
    CirclePlay,
    Download,
    Ellipsis,
    ExternalLink,
    FileText,
    Gauge,
    History,
    Link2,
    ListOrdered,
    Pencil,
    RefreshCw,
    Scale,
    Send,
    Trash2,
    Undo2,
    Wallet,
} from '@lucide/vue';
import { useDocumentVisibility, useIntervalFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import CopyButton from '@/components/common/CopyButton.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import ClientClassBadge from '@/components/pf/ClientClassBadge.vue';
import InvoiceStatusBadge from '@/components/pf/InvoiceStatusBadge.vue';
import ReasonDialog from '@/components/pf/invoice/ReasonDialog.vue';
import RecordPaymentDialog from '@/components/pf/invoice/RecordPaymentDialog.vue';
import ReminderSheet from '@/components/pf/invoice/ReminderSheet.vue';
import SendInvoiceDialog from '@/components/pf/invoice/SendInvoiceDialog.vue';
import PfTimeline from '@/components/pf/PfTimeline.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import ToneChip from '@/components/pf/ToneChip.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import { minorToDecimal } from '@/lib/pf/money';
import { PAYMENT_STATUS_TONE, REMINDER_STATUS_TONE } from '@/lib/pf/status';
import { show as showClient } from '@/routes/clients';
import {
    acknowledgeReportedPayment,
    confirmFirstContact,
    destroy,
    dispute,
    edit,
    index,
    lateFee,
    markSent,
    pause,
    paymentLink,
    resolveDispute,
    voidMethod,
    writeOff,
} from '@/routes/invoices';
import { reverse } from '@/routes/payments';
import type { InvoiceShowProps, PaymentRow } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.invoices', href: index() }],
    },
});

const props = defineProps<InvoiceShowProps>();
const pf = usePf();
const fmt = useFormat();
const page = usePage();

const inv = computed(() => props.invoice);
const isOpen = computed(() =>
    ['open', 'partially_paid'].includes(inv.value.status),
);
const isClosed = computed(() =>
    ['paid', 'void', 'uncollectible'].includes(inv.value.status),
);

const today = computed(() => {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: page.props.workspace?.timezone ?? undefined,
        }).format(new Date());
    } catch {
        return new Date().toISOString().slice(0, 10);
    }
});

const sendOpen = ref(false);
const paymentOpen = ref(false);
const reminderOpen = ref(false);
const pauseOpen = ref(false);
const lateFeeOpen = ref(false);
const deleteOpen = ref(false);
const reasonDialog = ref<null | 'void' | 'write_off' | 'dispute' | 'resolve'>(
    null,
);
const reverseTarget = ref<PaymentRow | null>(null);

onMounted(() => {
    const query = new URLSearchParams(page.url.split('?')[1] ?? '');

    if (
        query.get('record') === '1' &&
        props.can.record_payment &&
        (isOpen.value || inv.value.status === 'uncollectible')
    ) {
        paymentOpen.value = true;
    }

    if (query.has('record')) {
        query.delete('record');
        const rest = query.toString();
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${rest ? `?${rest}` : ''}`,
        );
    }
});
const busy = ref<string | null>(null);

const reasonOpen = computed({
    get: () => reasonDialog.value !== null,
    set: (value: boolean) => {
        if (!value) {
            reasonDialog.value = null;
        }
    },
});
const reverseOpen = computed({
    get: () => reverseTarget.value !== null,
    set: (value: boolean) => {
        if (!value) {
            reverseTarget.value = null;
        }
    },
});

const reasonConfig = computed(() => {
    const id = inv.value.id;
    const summary = `${inv.value.number ?? ''} · ${pf.money(inv.value.total)}`;

    switch (reasonDialog.value) {
        case 'void':
            return {
                url: voidMethod(id).url,
                title: t('pf.invoice.void_title'),
                description: t('pf.invoice.void_body'),
                label: t('pf.common.reason'),
                confirm: t('pf.actions.void'),
                destructive: true,
                field: 'reason',
                max: 255,
                summary,
            };
        case 'write_off':
            return {
                url: writeOff(id).url,
                title: t('pf.invoice.write_off_title'),
                description: t('pf.invoice.write_off_body'),
                label: t('pf.common.reason'),
                confirm: t('pf.actions.write_off'),
                destructive: true,
                field: 'reason',
                max: 255,
                summary,
            };
        case 'dispute':
            return {
                url: dispute(id).url,
                title: t('pf.invoice.dispute_title'),
                description: t('pf.invoice.dispute_body'),
                label: t('pf.common.reason'),
                confirm: t('pf.actions.dispute'),
                destructive: false,
                field: 'reason',
                max: 2000,
                summary,
            };
        case 'resolve':
            return {
                url: resolveDispute(id).url,
                title: t('pf.invoice.resolve_title'),
                description: t('pf.invoice.resolve_body'),
                label: t('pf.invoice.resolution_note'),
                confirm: t('pf.actions.resolve_dispute'),
                destructive: false,
                field: 'resolution_note',
                max: 2000,
                summary,
            };
        default:
            return null;
    }
});

function post(
    key: string,
    url: string,
    data: Record<string, string | number | boolean | null> = {},
): void {
    busy.value = key;
    router.post(url, data, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
        },
    });
}

const pauseForm = useForm({ until: '', reason: '' });

function togglePause(): void {
    if (inv.value.reminders_paused) {
        post('pause', pause(inv.value.id).url, { paused: false });

        return;
    }

    pauseForm.reset();
    pauseForm.clearErrors();
    pauseOpen.value = true;
}

function submitPause(): void {
    pauseForm
        .transform((data) => ({
            paused: true,
            until: data.until || null,
            reason: data.reason || null,
        }))
        .post(pause(inv.value.id).url, {
            preserveScroll: true,
            onSuccess: () => (pauseOpen.value = false),
        });
}

const lateFeeForm = useForm({ amount: '' });

function openLateFee(): void {
    lateFeeForm.reset();
    lateFeeForm.clearErrors();
    lateFeeForm.amount = props.late_fee
        ? minorToDecimal(
              props.late_fee.amount.minor,
              props.late_fee.amount.currency,
          )
        : '';
    lateFeeOpen.value = true;
}

function submitLateFee(): void {
    lateFeeForm
        .transform((data) => ({
            amount: data.amount
                ? data.amount.replace(',', '.').replace(/\s/g, '')
                : null,
        }))
        .post(lateFee(inv.value.id).url, {
            preserveScroll: true,
            onSuccess: () => (lateFeeOpen.value = false),
        });
}

const reverseForm = useForm({ reason: '' });

watch(reverseTarget, () => {
    reverseForm.reset();
    reverseForm.clearErrors();
});

function submitReverse(): void {
    if (!reverseTarget.value) {
        return;
    }

    reverseForm.post(reverse(reverseTarget.value.id).url, {
        preserveScroll: true,
        onSuccess: () => (reverseTarget.value = null),
    });
}

const resumeAfterAck = ref(true);

function confirmDelete(): void {
    busy.value = 'delete';
    router.delete(destroy(inv.value.id).url, {
        onFinish: () => {
            busy.value = null;
            deleteOpen.value = false;
        },
    });
}

const visibility = useDocumentVisibility();
const shouldPoll = computed(
    () =>
        Boolean(inv.value.payment_link?.active) &&
        !isClosed.value &&
        visibility.value === 'visible',
);
const { pause: stopPoll, resume: startPoll } = useIntervalFn(
    () => router.reload({ only: ['invoice', 'payments', 'timeline'] }),
    10_000,
    { immediate: false },
);
watch(shouldPoll, (value) => (value ? startPoll() : stopPoll()), {
    immediate: true,
});

const payableText = computed(() =>
    inv.value.payable_reason
        ? t(`pf.payable_reason.${inv.value.payable_reason}`)
        : null,
);

const maxContribution = computed(() =>
    Math.max(
        0.0001,
        ...(inv.value.risk?.factors ?? []).map((f) => f.contribution),
    ),
);

function factorWidth(contribution: number): number {
    return Math.round(
        (Math.max(0, contribution) / maxContribution.value) * 100,
    );
}

const showMoreMenu = computed(
    () =>
        props.can.edit ||
        props.can.void ||
        props.can.write_off ||
        props.can.dispute ||
        props.can.delete ||
        props.can.remind,
);
</script>

<template>
    <Head :title="inv.number ?? t('pf.invoice.draft_number')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="FileText"
            :title="inv.number ?? t('pf.invoice.draft_number')"
        >
            <template #meta>
                <div class="flex flex-wrap items-center gap-2">
                    <InvoiceStatusBadge
                        :status="inv.display_status"
                        :partial="inv.is_partially_paid"
                        :days-overdue="inv.days_overdue"
                    />
                    <Link
                        :href="showClient(inv.client.id).url"
                        class="inline-flex items-center gap-1.5 text-sm hover:underline"
                    >
                        <Building2
                            class="size-3.5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{ inv.client.name }}
                    </Link>
                    <ClientClassBadge :value="inv.client.late_payer_class" />
                    <StatusBadge
                        v-if="inv.reminders_paused"
                        tone="warning"
                        :icon="CirclePause"
                        :dot="false"
                        :label="t('pf.invoice.reminders_paused')"
                    />
                </div>
            </template>
            <template #actions>
                <Button
                    v-if="can.send && (inv.status === 'draft' || isOpen)"
                    :variant="inv.status === 'draft' ? 'default' : 'outline'"
                    data-test="invoice-send"
                    @click="sendOpen = true"
                >
                    <Send class="size-4" aria-hidden="true" />
                    {{
                        inv.sent_at
                            ? t('pf.actions.resend')
                            : t('pf.actions.send_invoice')
                    }}
                </Button>
                <Button
                    v-if="can.remind && isOpen"
                    variant="outline"
                    data-test="invoice-remind"
                    @click="reminderOpen = true"
                >
                    <BellRing class="size-4" aria-hidden="true" />
                    {{ t('pf.actions.send_reminder') }}
                </Button>
                <Button
                    v-if="
                        can.record_payment &&
                        (isOpen || inv.status === 'uncollectible')
                    "
                    data-test="invoice-record-payment"
                    @click="paymentOpen = true"
                >
                    <Wallet class="size-4" aria-hidden="true" />
                    {{ t('pf.actions.record_payment') }}
                </Button>
                <Button variant="outline" size="icon" as-child>
                    <a
                        :href="inv.pdf_url"
                        target="_blank"
                        rel="noopener"
                        :aria-label="t('pf.actions.download_pdf')"
                    >
                        <Download class="size-4" aria-hidden="true" />
                    </a>
                </Button>
                <DropdownMenu v-if="showMoreMenu">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="icon"
                            :aria-label="t('ui.common.actions')"
                            data-test="invoice-more"
                        >
                            <Ellipsis class="size-4" aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuItem
                            v-if="
                                can.edit && (inv.status === 'draft' || isOpen)
                            "
                            as-child
                        >
                            <Link :href="edit(inv.id).url"
                                ><Pencil class="size-4" aria-hidden="true" />{{
                                    t('pf.actions.edit')
                                }}</Link
                            >
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="can.send && inv.status === 'draft'"
                            @select="post('mark_sent', markSent(inv.id).url)"
                        >
                            <Send class="size-4" aria-hidden="true" />{{
                                t('pf.actions.mark_sent')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="can.remind && isOpen"
                            @select="togglePause"
                        >
                            <component
                                :is="
                                    inv.reminders_paused
                                        ? CirclePlay
                                        : CirclePause
                                "
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{
                                inv.reminders_paused
                                    ? t('pf.actions.resume_reminders')
                                    : t('pf.actions.pause_reminders')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="can.edit && isOpen"
                            @select="post('link', paymentLink(inv.id).url)"
                        >
                            <RefreshCw class="size-4" aria-hidden="true" />{{
                                t('pf.actions.recreate_link')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="can.apply_late_fee && isOpen"
                            @select="openLateFee"
                        >
                            <Gauge class="size-4" aria-hidden="true" />{{
                                t('pf.actions.apply_late_fee')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator
                            v-if="
                                can.dispute ||
                                can.void ||
                                can.write_off ||
                                can.delete
                            "
                        />
                        <DropdownMenuItem
                            v-if="can.dispute && isOpen"
                            @select="reasonDialog = 'dispute'"
                        >
                            <Scale class="size-4" aria-hidden="true" />{{
                                t('pf.actions.dispute')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="can.dispute && inv.status === 'disputed'"
                            @select="reasonDialog = 'resolve'"
                        >
                            <Scale class="size-4" aria-hidden="true" />{{
                                t('pf.actions.resolve_dispute')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="
                                can.write_off &&
                                (isOpen || inv.status === 'disputed')
                            "
                            class="text-destructive"
                            @select="reasonDialog = 'write_off'"
                        >
                            <Ban class="size-4" aria-hidden="true" />{{
                                t('pf.actions.write_off')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="
                                can.void &&
                                (inv.status === 'draft' ||
                                    inv.status === 'open') &&
                                inv.amount_paid.minor === 0
                            "
                            class="text-destructive"
                            data-test="invoice-void"
                            @select="reasonDialog = 'void'"
                        >
                            <Ban class="size-4" aria-hidden="true" />{{
                                t('pf.actions.void')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="can.delete && inv.status === 'draft'"
                            class="text-destructive"
                            @select="deleteOpen = true"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />{{
                                t('pf.actions.delete_draft')
                            }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <div
            v-if="inv.client_reported_paid_at && isOpen"
            class="flex flex-col gap-3 rounded-2xl border border-success/40 bg-success-soft p-4 text-sm text-success-soft-foreground sm:flex-row sm:items-center sm:justify-between"
            data-test="client-reported-paid"
        >
            <div class="flex items-start gap-3">
                <CircleCheck
                    class="mt-0.5 size-5 shrink-0"
                    aria-hidden="true"
                />
                <div>
                    <p class="font-medium">
                        {{
                            t('pf.invoice.client_reports_paid_title', {
                                date: fmt.dateTime(
                                    inv.client_reported_paid_at,
                                    'medium',
                                ),
                            })
                        }}
                    </p>
                    <p v-if="inv.client_reported_reference">
                        {{
                            t('pf.invoice.reference', {
                                reference: inv.client_reported_reference,
                            })
                        }}
                    </p>
                    <p class="opacity-80">
                        {{ t('pf.invoice.client_reports_paid_hint') }}
                    </p>
                </div>
            </div>
            <div
                v-if="can.record_payment"
                class="flex flex-wrap items-center gap-3"
            >
                <Label class="flex items-center gap-2 text-sm font-normal">
                    <Checkbox v-model="resumeAfterAck" />
                    {{ t('pf.invoice.resume_after_ack') }}
                </Label>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="busy === 'ack'"
                    @click="
                        post('ack', acknowledgeReportedPayment(inv.id).url, {
                            resume_reminders: resumeAfterAck,
                        })
                    "
                >
                    <Spinner v-if="busy === 'ack'" class="size-4" />
                    {{ t('pf.actions.acknowledge') }}
                </Button>
                <Button size="sm" @click="paymentOpen = true">{{
                    t('pf.actions.record_payment')
                }}</Button>
            </div>
        </div>

        <div
            v-if="
                inv.source === 'import' &&
                !inv.first_contact_confirmed_at &&
                isOpen
            "
            class="flex flex-col gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-3">
                <CircleAlert
                    class="mt-0.5 size-5 shrink-0"
                    aria-hidden="true"
                />
                <p>{{ t('pf.invoice.first_contact_hint') }}</p>
            </div>
            <Button
                v-if="pf.can('reminders.configure')"
                size="sm"
                variant="outline"
                :disabled="busy === 'first'"
                @click="post('first', confirmFirstContact(inv.id).url)"
            >
                <Spinner v-if="busy === 'first'" class="size-4" />
                {{ t('pf.actions.confirm_first_contact') }}
            </Button>
        </div>

        <div
            v-if="inv.status === 'disputed'"
            class="flex items-start gap-3 rounded-2xl border border-warning/40 bg-warning-soft p-4 text-sm text-warning-soft-foreground"
        >
            <Scale class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-medium">
                    {{
                        t('pf.invoice.disputed_since', {
                            date: fmt.date(inv.disputed_at),
                        })
                    }}
                </p>
                <p v-if="inv.dispute_reason">{{ inv.dispute_reason }}</p>
            </div>
        </div>
        <div
            v-if="inv.status === 'void' || inv.status === 'uncollectible'"
            class="flex items-start gap-3 rounded-2xl border bg-muted/40 p-4 text-sm"
        >
            <Ban
                class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div>
                <p class="font-medium">
                    {{
                        inv.status === 'void'
                            ? t('pf.invoice.voided_on', {
                                  date: fmt.date(inv.voided_at),
                              })
                            : t('pf.invoice.written_off_on', {
                                  date: fmt.date(inv.written_off_at),
                              })
                    }}
                </p>
                <p class="text-muted-foreground">
                    {{ inv.void_reason ?? inv.write_off_reason }}
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <SectionCard
                    :icon="ListOrdered"
                    :title="t('pf.invoice.items')"
                    flush
                >
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-test="invoice-items">
                            <caption class="sr-only">
                                {{
                                    t('pf.invoice.items')
                                }}
                            </caption>
                            <thead
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-5 py-2 font-medium">
                                        {{ t('pf.invoice_form.description') }}
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        {{ t('pf.invoice_form.quantity') }}
                                    </th>
                                    <th
                                        class="hidden px-3 py-2 text-right font-medium sm:table-cell"
                                    >
                                        {{
                                            t('pf.invoice_form.unit_price', {
                                                currency: inv.currency,
                                            })
                                        }}
                                    </th>
                                    <th
                                        class="hidden px-3 py-2 text-right font-medium sm:table-cell"
                                    >
                                        {{ t('pf.invoice_form.tax_rate') }}
                                    </th>
                                    <th
                                        class="px-5 py-2 text-right font-medium"
                                    >
                                        {{ t('pf.invoice.amount') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in inv.items"
                                    :key="item.id"
                                    class="border-b last:border-0"
                                >
                                    <td class="px-5 py-2.5">
                                        {{ item.description }}
                                        <StatusBadge
                                            v-if="item.kind === 'late_fee'"
                                            class="ml-1"
                                            tone="warning"
                                            :dot="false"
                                            :label="t('pf.late_fee.title')"
                                        />
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-right tabular-nums"
                                    >
                                        {{ item.quantity }}
                                    </td>
                                    <td
                                        class="hidden px-3 py-2.5 text-right tabular-nums sm:table-cell"
                                    >
                                        {{ pf.money(item.unit_price) }}
                                    </td>
                                    <td
                                        class="hidden px-3 py-2.5 text-right tabular-nums sm:table-cell"
                                    >
                                        {{
                                            item.tax_rate_bp
                                                ? fmt.percent(
                                                      item.tax_rate_bp / 10000,
                                                      2,
                                                  )
                                                : '—'
                                        }}
                                    </td>
                                    <td
                                        class="px-5 py-2.5 text-right font-medium tabular-nums"
                                    >
                                        {{ pf.money(item.amount) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <dl
                        class="ml-auto w-full max-w-xs space-y-1.5 border-t px-5 py-4 text-sm"
                    >
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('pf.invoice.subtotal') }}
                            </dt>
                            <dd class="tabular-nums">
                                {{ pf.money(inv.subtotal) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                {{ t('pf.invoice.tax') }}
                            </dt>
                            <dd class="tabular-nums">
                                {{ pf.money(inv.tax) }}
                            </dd>
                        </div>
                        <div
                            v-if="inv.late_fee_applied.minor"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('pf.late_fee.title') }}
                            </dt>
                            <dd class="tabular-nums">
                                {{ pf.money(inv.late_fee_applied) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between border-t pt-1.5 font-semibold"
                        >
                            <dt>{{ t('pf.invoice.total') }}</dt>
                            <dd class="tabular-nums" data-test="invoice-total">
                                {{ pf.money(inv.total) }}
                            </dd>
                        </div>
                        <div
                            v-if="inv.amount_paid.minor"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('pf.invoice.paid') }}
                            </dt>
                            <dd class="tabular-nums">
                                − {{ pf.money(inv.amount_paid) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between text-base font-semibold"
                        >
                            <dt>{{ t('pf.invoice.balance_due') }}</dt>
                            <dd
                                class="tabular-nums"
                                data-test="invoice-balance"
                            >
                                {{ pf.money(inv.balance_due) }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="inv.notes || inv.terms"
                        class="grid gap-4 border-t px-5 py-4 text-sm sm:grid-cols-2"
                    >
                        <div v-if="inv.notes">
                            <p class="text-xs text-muted-foreground">
                                {{ t('pf.invoice.notes') }}
                            </p>
                            <p class="whitespace-pre-line">{{ inv.notes }}</p>
                        </div>
                        <div v-if="inv.terms">
                            <p class="text-xs text-muted-foreground">
                                {{ t('pf.invoice.terms') }}
                            </p>
                            <p class="whitespace-pre-line">{{ inv.terms }}</p>
                        </div>
                    </div>
                </SectionCard>

                <SectionCard
                    :icon="Wallet"
                    :title="t('pf.invoice.payments')"
                    flush
                >
                    <EmptyState
                        v-if="!payments.length"
                        compact
                        :icon="Wallet"
                        tone="muted"
                        :title="t('pf.invoice.no_payments')"
                    />
                    <ul v-else class="divide-y">
                        <li
                            v-for="payment in payments"
                            :key="payment.id"
                            class="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                            data-test="payment-row"
                            :data-status="payment.status"
                        >
                            <div class="min-w-0">
                                <p
                                    class="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <span class="font-medium tabular-nums">{{
                                        pf.money(payment.amount)
                                    }}</span>
                                    <StatusBadge
                                        :tone="
                                            PAYMENT_STATUS_TONE[payment.status]
                                        "
                                        :label="
                                            t(
                                                `pf.payment_status.${payment.status}`,
                                            )
                                        "
                                    />
                                    <span class="text-xs text-muted-foreground"
                                        >{{
                                            t(
                                                `pf.payment_method.${payment.method}`,
                                            )
                                        }}
                                        ·
                                        {{
                                            t(`pf.provider.${payment.provider}`)
                                        }}</span
                                    >
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        fmt.dateTime(payment.paid_at, 'medium')
                                    }}
                                    <template v-if="payment.reference">
                                        · {{ payment.reference }}</template
                                    >
                                    <template v-if="payment.recorded_by">
                                        ·
                                        {{ payment.recorded_by.name }}</template
                                    >
                                    <template v-if="payment.refunded.minor">
                                        ·
                                        {{
                                            t('pf.payment.refunded', {
                                                amount: pf.money(
                                                    payment.refunded,
                                                ),
                                            })
                                        }}</template
                                    >
                                </p>
                                <p
                                    v-if="payment.note"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ payment.note }}
                                </p>
                            </div>
                            <Button
                                v-if="payment.can_reverse"
                                size="sm"
                                variant="ghost"
                                @click="reverseTarget = payment"
                            >
                                <Undo2 class="size-4" aria-hidden="true" />{{
                                    t('pf.actions.reverse')
                                }}
                            </Button>
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    :icon="BellRing"
                    :title="t('pf.invoice.reminders')"
                    flush
                >
                    <template v-if="can.remind && isOpen" #actions>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="reminderOpen = true"
                            >{{ t('pf.actions.send_reminder') }}</Button
                        >
                    </template>
                    <div
                        v-if="next_reminder"
                        class="flex flex-wrap items-center gap-2 border-b bg-muted/30 px-5 py-3 text-sm"
                        data-test="next-reminder"
                    >
                        <CalendarClock
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="text-muted-foreground">{{
                            t('pf.invoice.next_reminder')
                        }}</span>
                        <ToneChip :tone="next_reminder.tone" />
                        <span>{{
                            fmt.dateTime(next_reminder.scheduled_for, 'medium')
                        }}</span>
                        <span class="text-xs text-muted-foreground"
                            >· {{ next_reminder.policy_name }}</span
                        >
                    </div>
                    <EmptyState
                        v-if="!reminders.length"
                        compact
                        :icon="BellRing"
                        tone="muted"
                        :title="t('pf.invoice.no_reminders')"
                    />
                    <ul v-else class="divide-y">
                        <li
                            v-for="reminder in reminders"
                            :key="reminder.id"
                            class="px-5 py-3"
                            data-test="reminder-row"
                            :data-status="reminder.status"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <ToneChip :tone="reminder.tone" />
                                    <span class="truncate text-sm">{{
                                        reminder.subject ??
                                        t(`pf.reminder.kind.${reminder.kind}`)
                                    }}</span>
                                </span>
                                <StatusBadge
                                    :tone="
                                        REMINDER_STATUS_TONE[reminder.status]
                                    "
                                    :label="
                                        t(
                                            `pf.reminder_status.${reminder.status}`,
                                        )
                                    "
                                />
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{
                                    fmt.dateTime(
                                        reminder.sent_at ??
                                            reminder.failed_at ??
                                            reminder.scheduled_for,
                                        'medium',
                                    )
                                }}
                                <template v-if="reminder.recipient_email">
                                    · {{ reminder.recipient_email }}</template
                                >
                                <template v-if="reminder.skip_reason">
                                    ·
                                    {{
                                        t(
                                            `pf.skip_reason.${reminder.skip_reason}`,
                                        )
                                    }}</template
                                >
                                <template v-if="reminder.error">
                                    · {{ reminder.error }}</template
                                >
                                <template v-if="reminder.created_by">
                                    · {{ reminder.created_by.name }}</template
                                >
                            </p>
                            <ul
                                v-if="reminder.tone_reasons.length"
                                class="mt-1 flex flex-wrap gap-1"
                            >
                                <li
                                    v-for="reason in reminder.tone_reasons"
                                    :key="reason.code"
                                    class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                                >
                                    {{ pf.reason(reason) }}
                                </li>
                            </ul>
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard :icon="History" :title="t('pf.invoice.timeline')">
                    <EmptyState
                        v-if="!timeline.length"
                        compact
                        :icon="History"
                        tone="muted"
                        :title="t('pf.common.no_activity')"
                    />
                    <div v-else class="pl-4">
                        <PfTimeline
                            :events="timeline"
                            :currency="inv.currency"
                        />
                    </div>
                </SectionCard>
            </div>

            <div class="flex flex-col gap-6">
                <section
                    class="rounded-2xl border bg-card p-5 shadow-soft"
                    :aria-label="t('pf.invoice.summary')"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.invoice.balance_due') }}
                    </p>
                    <p
                        class="text-3xl font-semibold tracking-tight tabular-nums"
                    >
                        {{ pf.money(inv.balance_due) }}
                    </p>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.invoice.issue_date') }}
                            </dt>
                            <dd>{{ fmt.date(inv.issue_date) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.invoice.due_date') }}
                            </dt>
                            <dd
                                :class="
                                    inv.days_overdue > 0 && !isClosed
                                        ? 'font-medium text-destructive'
                                        : ''
                                "
                            >
                                {{ fmt.date(inv.due_date) }}
                            </dd>
                        </div>
                        <div v-if="inv.sent_at">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.invoice.sent') }}
                            </dt>
                            <dd>{{ fmt.date(inv.sent_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.invoice.views') }}
                            </dt>
                            <dd>
                                {{
                                    inv.view_count
                                        ? t('pf.invoice.viewed', {
                                              count: inv.view_count,
                                              date: fmt.relative(
                                                  inv.last_viewed_at,
                                              ),
                                          })
                                        : t('pf.invoice.not_viewed')
                                }}
                            </dd>
                        </div>
                        <div v-if="inv.paid_at">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.invoice.paid_at') }}
                            </dt>
                            <dd>{{ fmt.date(inv.paid_at) }}</dd>
                        </div>
                        <div v-if="inv.po_number">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.invoice.po_number') }}
                            </dt>
                            <dd class="font-mono text-xs">
                                {{ inv.po_number }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="inv.status !== 'draft'"
                        class="mt-4 flex items-center gap-2 rounded-xl border bg-muted/30 px-3 py-2 text-xs"
                    >
                        <ExternalLink
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <a
                            :href="inv.public_url"
                            target="_blank"
                            rel="noopener"
                            class="min-w-0 flex-1 truncate hover:underline"
                            >{{ t('pf.invoice.public_page') }}</a
                        >
                        <CopyButton
                            :value="inv.public_url"
                            :label="t('pf.invoice.copy_link')"
                        />
                    </div>
                </section>

                <SectionCard
                    :icon="Link2"
                    :title="t('pf.invoice.payment_link')"
                >
                    <div
                        v-if="inv.payment_link"
                        class="space-y-2 text-sm"
                        data-test="payment-link"
                    >
                        <p class="flex items-center justify-between gap-2">
                            <StatusBadge
                                :tone="
                                    inv.payment_link.active
                                        ? 'success'
                                        : 'neutral'
                                "
                                :label="
                                    inv.payment_link.active
                                        ? t('pf.invoice.link_active')
                                        : t('pf.invoice.link_inactive')
                                "
                            />
                            <span class="text-xs text-muted-foreground">{{
                                t(`pf.provider.${inv.payment_link.driver}`)
                            }}</span>
                        </p>
                        <p class="tabular-nums">
                            {{ pf.money(inv.payment_link.amount) }}
                        </p>
                        <div
                            class="flex items-center gap-2 rounded-xl border bg-muted/30 px-3 py-2 text-xs"
                        >
                            <span class="min-w-0 flex-1 truncate font-mono">{{
                                inv.payment_link.url
                            }}</span>
                            <CopyButton
                                :value="inv.payment_link.url"
                                :label="t('pf.invoice.copy_link')"
                            />
                        </div>
                        <p
                            v-if="inv.payment_link.active"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t('pf.invoice.link_polling') }}
                        </p>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ payableText ?? t('pf.invoice.no_link') }}
                    </p>
                </SectionCard>

                <SectionCard
                    v-if="tone"
                    :icon="BellRing"
                    :title="t('pf.invoice.suggested_tone')"
                >
                    <ToneChip :tone="tone.tone" class="text-sm" />
                    <ul
                        class="mt-3 space-y-1.5 text-sm text-muted-foreground"
                        data-test="tone-reasons"
                    >
                        <li
                            v-for="reason in tone.reasons"
                            :key="reason.code"
                            class="flex gap-2"
                        >
                            <span aria-hidden="true">•</span
                            >{{ pf.reason(reason) }}
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    v-if="inv.risk"
                    :icon="Gauge"
                    :title="t('pf.invoice.risk')"
                >
                    <div class="flex items-center justify-between gap-2">
                        <RiskBadge
                            :band="inv.risk.band"
                            :score="inv.risk.score"
                        />
                        <span
                            v-if="inv.risk.expected_paid_on"
                            class="text-xs text-muted-foreground"
                            >{{
                                t('pf.invoice.expected_paid', {
                                    date: fmt.date(inv.risk.expected_paid_on),
                                })
                            }}</span
                        >
                    </div>
                    <ul class="mt-3 space-y-2" data-test="risk-factors">
                        <li
                            v-for="factor in inv.risk.factors"
                            :key="factor.code"
                            class="text-sm"
                        >
                            <div class="flex justify-between gap-2">
                                <span>{{ pf.reason(factor) }}</span>
                            </div>
                            <div
                                class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full rounded-full bg-warning"
                                    :style="{
                                        width: `${factorWidth(factor.contribution)}%`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    v-if="late_fee"
                    :icon="Gauge"
                    :title="t('pf.late_fee.title')"
                >
                    <p
                        class="text-2xl font-semibold tabular-nums"
                        data-test="late-fee-amount"
                    >
                        {{ pf.money(late_fee.amount) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t('pf.late_fee.accrued', {
                                days: late_fee.chargeable_days,
                            })
                        }}
                        · {{ t(`pf.late_fee.types.${late_fee.terms.type}`) }} ·
                        {{ t(`pf.late_fee.source.${late_fee.source}`) }}
                    </p>
                    <p
                        v-if="late_fee.already_applied.minor"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{
                            t('pf.late_fee.already', {
                                amount: pf.money(late_fee.already_applied),
                            })
                        }}
                    </p>
                    <Button
                        v-if="can.apply_late_fee && isOpen"
                        class="mt-3 w-full"
                        variant="outline"
                        size="sm"
                        data-test="apply-late-fee"
                        @click="openLateFee"
                        >{{ t('pf.actions.apply_late_fee') }}</Button
                    >
                </SectionCard>

                <SectionCard
                    :icon="Building2"
                    :title="t('pf.invoice.client_history')"
                >
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.client.avg_days_late') }}
                            </dt>
                            <dd class="tabular-nums">
                                {{
                                    client_stats.paid_invoices_count
                                        ? pf.days(client_stats.avg_days_late)
                                        : '—'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.client.on_time_rate') }}
                            </dt>
                            <dd class="tabular-nums">
                                {{
                                    client_stats.on_time_rate === null
                                        ? '—'
                                        : fmt.percent(client_stats.on_time_rate)
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.client.open_balance') }}
                            </dt>
                            <dd class="tabular-nums">
                                {{ pf.money(client_stats.open_balance) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('pf.client.overdue_balance') }}
                            </dt>
                            <dd class="tabular-nums">
                                {{ pf.money(client_stats.overdue_balance) }}
                            </dd>
                        </div>
                    </dl>
                </SectionCard>
            </div>
        </div>
    </div>

    <SendInvoiceDialog v-model:open="sendOpen" :invoice="inv" />
    <RecordPaymentDialog
        v-model:open="paymentOpen"
        :invoice="inv"
        :today="today"
    />
    <ReminderSheet
        v-model:open="reminderOpen"
        :invoice="inv"
        :suggestion="tone"
    />
    <ReasonDialog
        v-if="reasonConfig"
        v-model:open="reasonOpen"
        :url="reasonConfig.url"
        :title="reasonConfig.title"
        :description="reasonConfig.description"
        :label="reasonConfig.label"
        :confirm-label="reasonConfig.confirm"
        :destructive="reasonConfig.destructive"
        :field="reasonConfig.field"
        :max="reasonConfig.max"
        :summary="reasonConfig.summary"
    />

    <Dialog v-model:open="pauseOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('pf.pause.title_pause') }}</DialogTitle>
                <DialogDescription>{{
                    t('pf.pause.invoice_pause_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitPause">
                <div class="grid gap-2">
                    <Label for="inv-pause-until">{{
                        t('pf.pause.until')
                    }}</Label>
                    <Input
                        id="inv-pause-until"
                        v-model="pauseForm.until"
                        type="date"
                        :min="today"
                    />
                    <InputError :message="pauseForm.errors.until" />
                </div>
                <div class="grid gap-2">
                    <Label for="inv-pause-reason">{{
                        t('pf.pause.reason')
                    }}</Label>
                    <Input
                        id="inv-pause-reason"
                        v-model="pauseForm.reason"
                        maxlength="255"
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="pauseOpen = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button type="submit" :disabled="pauseForm.processing">
                        <Spinner v-if="pauseForm.processing" class="size-4" />
                        {{ t('pf.actions.pause_reminders') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="lateFeeOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('pf.late_fee.apply_title') }}</DialogTitle>
                <DialogDescription>{{
                    t('pf.late_fee.apply_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitLateFee">
                <div class="grid gap-2">
                    <Label for="late-fee-amount">{{
                        t('pf.payment.amount', { currency: inv.currency })
                    }}</Label>
                    <Input
                        id="late-fee-amount"
                        v-model="lateFeeForm.amount"
                        inputmode="decimal"
                        class="text-right tabular-nums"
                    />
                    <p v-if="late_fee" class="text-xs text-muted-foreground">
                        {{
                            t('pf.late_fee.suggested', {
                                amount: pf.money(late_fee.amount),
                            })
                        }}
                    </p>
                    <InputError :message="lateFeeForm.errors.amount" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="lateFeeOpen = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="lateFeeForm.processing"
                        data-test="late-fee-confirm"
                    >
                        <Spinner v-if="lateFeeForm.processing" class="size-4" />
                        {{ t('pf.actions.apply_late_fee') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="reverseOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('pf.payment.reverse_title') }}</DialogTitle>
                <DialogDescription>{{
                    t('pf.payment.reverse_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitReverse">
                <p
                    v-if="reverseTarget"
                    class="rounded-xl border bg-muted/40 px-3 py-2 text-sm font-medium tabular-nums"
                >
                    {{ pf.money(reverseTarget.amount) }} ·
                    {{ fmt.date(reverseTarget.paid_at) }}
                </p>
                <div class="grid gap-2">
                    <Label for="reverse-reason">{{
                        t('pf.common.reason')
                    }}</Label>
                    <Input
                        id="reverse-reason"
                        v-model="reverseForm.reason"
                        maxlength="500"
                    />
                    <InputError :message="reverseForm.errors.reason" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="reverseTarget = null"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            reverseForm.processing ||
                            reverseForm.reason.trim().length < 5
                        "
                    >
                        <Spinner v-if="reverseForm.processing" class="size-4" />
                        {{ t('pf.actions.reverse') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :title="t('pf.invoice.delete_title')"
        :description="t('pf.invoice.delete_body')"
        :confirm-label="t('ui.common.delete')"
        :processing="busy === 'delete'"
        @confirm="confirmDelete"
    />
</template>
