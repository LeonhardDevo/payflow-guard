<script setup lang="ts">
import { Link, useForm, useHttp } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    CircleAlert,
    FileText,
    ListPlus,
    Mail,
    NotebookPen,
    Save,
    Send,
    Trash2,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import UsageMeter from '@/components/common/UsageMeter.vue';
import InputError from '@/components/InputError.vue';
import ClientClassBadge from '@/components/pf/ClientClassBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import {
    bpToPercent,
    decimalToMinor,
    invoiceTotals,
    lineAmounts,
    minorToDecimal,
    percentToBp,
} from '@/lib/pf/money';
import {
    create as createClient,
    options as clientOptionsRoute,
} from '@/routes/clients';
import { index, show, store, update } from '@/routes/invoices';
import type { ClientOption, InvoiceFormProps } from '@/types/payflow';

const props = defineProps<InvoiceFormProps>();
const pf = usePf();
const invoice = props.invoice;
const d = props.defaults;
const openEdit = Boolean(invoice && invoice.status !== 'draft');

type Line = {
    key: number;
    description: string;
    quantity: string;
    unit_price: string;
    tax_rate: string;
};

let seq = 0;
const blankLine = (): Line => ({
    key: ++seq,
    description: '',
    quantity: '1',
    unit_price: '',
    tax_rate: d.tax_rate_bp ? bpToPercent(d.tax_rate_bp) : '',
});

const form = useForm({
    client_id:
        (invoice?.client.id ?? d.client_id)
            ? String(invoice?.client.id ?? d.client_id)
            : null,
    number: invoice?.status === 'draft' ? (invoice.number ?? '') : '',
    currency: invoice?.currency ?? d.currency,
    locale: invoice?.locale ?? d.locale,
    issue_date: invoice?.issue_date ?? d.issue_date,
    due_date: invoice?.due_date ?? d.due_date,
    po_number: invoice?.po_number ?? '',
    notes: invoice?.notes ?? d.notes ?? '',
    terms: invoice?.terms ?? d.terms ?? '',
    items: invoice
        ? invoice.items
              .filter((item) => item.kind === 'service')
              .map((item) => ({
                  key: ++seq,
                  description: item.description,
                  quantity: item.quantity,
                  unit_price: minorToDecimal(
                      item.unit_price.minor,
                      item.unit_price.currency,
                  ),
                  tax_rate: bpToPercent(item.tax_rate_bp),
              }))
        : [blankLine()],
    action: 'save_draft' as
        | 'save_draft'
        | 'save_and_send'
        | 'save_and_mark_sent',
});

const CLIENTS_PRELOADED = 200;
const remoteClients = props.clients.length >= CLIENTS_PRELOADED;
const fetchedClients = ref<ClientOption[]>([]);
const clientQuery = ref('');
const clientsHttp = useHttp<Record<string, never>, ClientOption[]>({});

const allClients = computed<ClientOption[]>(() => {
    const seen = new Set<number>();

    return [...props.clients, ...fetchedClients.value].filter((client) => {
        if (seen.has(client.id)) {
            return false;
        }

        seen.add(client.id);

        return true;
    });
});

const loadClients = useDebounceFn(async (q: string) => {
    try {
        const found = await clientsHttp.get(
            clientOptionsRoute({ query: { q } }).url,
        );

        if (Array.isArray(found)) {
            fetchedClients.value = [...fetchedClients.value, ...found];
        }
    } catch {
        return;
    }
}, 250);

watch(clientQuery, (value) => {
    const q = value.trim();

    if (remoteClients && q.length >= 2) {
        void loadClients(q);
    }
});

const clientOptions = computed(() => {
    const list = allClients.value.map((c) => ({
        value: String(c.id),
        label: c.name,
        hint: [c.currency, c.email].filter(Boolean).join(' · ') || null,
    }));

    if (
        invoice &&
        !list.some((option) => option.value === String(invoice.client.id))
    ) {
        list.unshift({
            value: String(invoice.client.id),
            label: invoice.client.name,
            hint: invoice.currency,
        });
    }

    return list;
});

const selectedClient = computed(
    () => allClients.value.find((c) => String(c.id) === form.client_id) ?? null,
);

function addDays(date: string, days: number): string {
    const parsed = new Date(`${date}T00:00:00Z`);

    if (Number.isNaN(parsed.getTime())) {
        return date;
    }

    parsed.setUTCDate(parsed.getUTCDate() + days);

    return parsed.toISOString().slice(0, 10);
}

watch(
    () => form.client_id,
    () => {
        const client = selectedClient.value;

        if (!client || openEdit) {
            return;
        }

        form.currency = client.currency;
        form.locale = client.locale;
        form.due_date = addDays(form.issue_date, client.payment_terms_days);
    },
);

watch(
    () => form.issue_date,
    (value, old) => {
        if (openEdit || !value || !old) {
            return;
        }

        const terms =
            selectedClient.value?.payment_terms_days ?? d.payment_terms_days;
        form.due_date = addDays(value, terms);
    },
);

const lines = computed(() =>
    form.items.map((item) => {
        const unit = decimalToMinor(item.unit_price, form.currency) ?? 0;

        return lineAmounts(
            item.quantity || '0',
            unit,
            percentToBp(item.tax_rate),
        );
    }),
);

const totals = computed(() => invoiceTotals(lines.value));
const m = (minor: number) => pf.money({ minor, currency: form.currency });

function addLine(): void {
    if (form.items.length < 50) {
        form.items.push(blankLine());
    }
}

function removeLine(index: number): void {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
}

function moveLine(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= form.items.length) {
        return;
    }

    const [item] = form.items.splice(index, 1);
    form.items.splice(target, 0, item);
}

const err = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

const itemErrors = computed(
    () =>
        Object.keys(form.errors).filter((key) => key.startsWith('items'))
            .length,
);

const overLimit = computed(() => {
    const entry = props.limits.invoices_per_month;

    return !invoice && entry.limit !== null && entry.used >= entry.limit;
});

function submit(action: typeof form.action): void {
    form.action = action;

    if (invoice && openEdit) {
        form.transform((data) => ({
            notes: data.notes || null,
            terms: data.terms || null,
            po_number: data.po_number || null,
            due_date: data.due_date,
        })).put(update(invoice.id).url, { preserveScroll: true });

        return;
    }

    const options = { preserveScroll: true };
    const payload = form.transform((data) => ({
        client_id: data.client_id ? Number(data.client_id) : null,
        number: data.number || null,
        currency: data.currency,
        locale: data.locale,
        issue_date: data.issue_date,
        due_date: data.due_date,
        po_number: data.po_number || null,
        notes: data.notes || null,
        terms: data.terms || null,
        action: data.action,
        items: data.items.map((item) => ({
            description: item.description,
            quantity: item.quantity.replace(',', '.').trim(),
            unit_price: item.unit_price.replace(',', '.').replace(/\s/g, ''),
            tax_rate: item.tax_rate
                ? item.tax_rate.replace(',', '.').trim()
                : null,
        })),
    }));

    if (invoice) {
        payload.put(update(invoice.id).url, options);
    } else {
        payload.post(store().url, options);
    }
}
</script>

<template>
    <form
        class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_20rem]"
        novalidate
        data-test="invoice-form"
        @submit.prevent="submit('save_draft')"
    >
        <div class="flex min-w-0 flex-col gap-6">
            <UsageMeter
                v-if="!invoice && limits.invoices_per_month.limit !== null"
                :label="t('pf.limits.invoices_per_month')"
                :entry="limits.invoices_per_month"
            />

            <div
                v-if="openEdit"
                class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
            >
                <CircleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <p>{{ t('pf.invoice_form.open_edit_hint') }}</p>
            </div>

            <SectionCard :icon="FileText" :title="t('pf.invoice_form.details')">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="inv-client">{{
                            t('pf.invoice.client')
                        }}</Label>
                        <div
                            class="flex flex-col gap-2 sm:flex-row sm:items-center"
                        >
                            <SearchableSelect
                                id="inv-client"
                                v-model="form.client_id"
                                v-model:query="clientQuery"
                                class="min-w-0 flex-1"
                                :options="clientOptions"
                                :loading="clientsHttp.processing"
                                :disabled="openEdit"
                                :placeholder="
                                    t('pf.invoice_form.choose_client')
                                "
                                :invalid="Boolean(form.errors.client_id)"
                                data-test="invoice-client"
                            />
                            <Button
                                v-if="!openEdit && pf.can('clients.manage')"
                                type="button"
                                variant="ghost"
                                size="sm"
                                as-child
                            >
                                <Link :href="createClient().url">{{
                                    t('pf.invoice_form.new_client')
                                }}</Link>
                            </Button>
                        </div>
                        <p
                            v-if="selectedClient"
                            class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                        >
                            <ClientClassBadge
                                :value="selectedClient.late_payer_class"
                            />
                            <span
                                v-if="selectedClient.email"
                                class="inline-flex items-center gap-1"
                            >
                                <Mail class="size-3" aria-hidden="true" />{{
                                    selectedClient.email
                                }}
                            </span>
                            <span v-else class="text-warning-soft-foreground">{{
                                t('pf.invoice_form.client_no_email')
                            }}</span>
                        </p>
                        <InputError :message="form.errors.client_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="inv-number">{{
                            t('pf.invoice.number')
                        }}</Label>
                        <Input
                            id="inv-number"
                            v-model="form.number"
                            :disabled="openEdit"
                            maxlength="40"
                            class="font-mono"
                            :placeholder="
                                openEdit
                                    ? (invoice?.number ?? '')
                                    : next_number_preview
                            "
                        />
                        <p
                            v-if="!openEdit"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t('pf.invoice_form.number_hint', {
                                    number: next_number_preview,
                                })
                            }}
                        </p>
                        <InputError :message="form.errors.number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="inv-po">{{
                            t('pf.invoice.po_number')
                        }}</Label>
                        <Input
                            id="inv-po"
                            v-model="form.po_number"
                            maxlength="64"
                        />
                        <InputError :message="form.errors.po_number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="inv-issue">{{
                            t('pf.invoice.issue_date')
                        }}</Label>
                        <Input
                            id="inv-issue"
                            v-model="form.issue_date"
                            type="date"
                            :disabled="openEdit"
                        />
                        <InputError :message="form.errors.issue_date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="inv-due">{{
                            t('pf.invoice.due_date')
                        }}</Label>
                        <Input
                            id="inv-due"
                            v-model="form.due_date"
                            type="date"
                            :min="form.issue_date"
                        />
                        <InputError :message="form.errors.due_date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="inv-currency">{{
                            t('pf.client.currency')
                        }}</Label>
                        <Select v-model="form.currency" :disabled="openEdit">
                            <SelectTrigger id="inv-currency" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="code in currencies"
                                    :key="code"
                                    :value="code"
                                    >{{ code }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.currency" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="inv-locale">{{
                            t('pf.invoice.locale')
                        }}</Label>
                        <Select v-model="form.locale" :disabled="openEdit">
                            <SelectTrigger id="inv-locale" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="en">English</SelectItem>
                                <SelectItem value="ru">Русский</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </SectionCard>

            <SectionCard :icon="ListPlus" :title="t('pf.invoice_form.items')">
                <template v-if="!openEdit" #actions>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="form.items.length >= 50"
                        data-test="add-line"
                        @click="addLine"
                    >
                        <ListPlus class="size-4" aria-hidden="true" />
                        {{ t('pf.invoice_form.add_line') }}
                    </Button>
                </template>
                <p v-if="openEdit" class="text-sm text-muted-foreground">
                    {{ t('pf.invoice_form.items_locked') }}
                </p>
                <ol v-else class="flex flex-col gap-3">
                    <li
                        v-for="(item, i) in form.items"
                        :key="item.key"
                        class="grid grid-cols-2 gap-3 rounded-xl border bg-muted/20 p-3 sm:grid-cols-[1fr_5.5rem_8rem_5.5rem_auto]"
                        data-test="invoice-line"
                    >
                        <div class="col-span-2 grid gap-1.5 sm:col-span-1">
                            <Label
                                :for="`line-desc-${item.key}`"
                                class="text-xs text-muted-foreground"
                                >{{ t('pf.invoice_form.description') }}</Label
                            >
                            <Input
                                :id="`line-desc-${item.key}`"
                                v-model="item.description"
                                maxlength="500"
                                :aria-invalid="
                                    Boolean(err(`items.${i}.description`))
                                "
                            />
                            <InputError
                                :message="err(`items.${i}.description`)"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label
                                :for="`line-qty-${item.key}`"
                                class="text-xs text-muted-foreground"
                                >{{ t('pf.invoice_form.quantity') }}</Label
                            >
                            <Input
                                :id="`line-qty-${item.key}`"
                                v-model="item.quantity"
                                inputmode="decimal"
                                class="text-right tabular-nums"
                                :aria-invalid="
                                    Boolean(err(`items.${i}.quantity`))
                                "
                            />
                            <InputError :message="err(`items.${i}.quantity`)" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label
                                :for="`line-price-${item.key}`"
                                class="text-xs text-muted-foreground"
                                >{{
                                    t('pf.invoice_form.unit_price', {
                                        currency: form.currency,
                                    })
                                }}</Label
                            >
                            <Input
                                :id="`line-price-${item.key}`"
                                v-model="item.unit_price"
                                inputmode="decimal"
                                placeholder="0.00"
                                class="text-right tabular-nums"
                                :aria-invalid="
                                    Boolean(err(`items.${i}.unit_price`))
                                "
                            />
                            <InputError
                                :message="err(`items.${i}.unit_price`)"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label
                                :for="`line-tax-${item.key}`"
                                class="text-xs text-muted-foreground"
                                >{{ t('pf.invoice_form.tax_rate') }}</Label
                            >
                            <Input
                                :id="`line-tax-${item.key}`"
                                v-model="item.tax_rate"
                                inputmode="decimal"
                                placeholder="0"
                                class="text-right tabular-nums"
                                :aria-invalid="
                                    Boolean(err(`items.${i}.tax_rate`))
                                "
                            />
                            <InputError :message="err(`items.${i}.tax_rate`)" />
                        </div>
                        <div
                            class="col-span-2 flex items-end justify-between gap-2 sm:col-span-1 sm:flex-col sm:items-end sm:justify-between"
                        >
                            <span
                                class="text-sm font-medium tabular-nums"
                                data-test="line-amount"
                                >{{ m(lines[i]?.amount ?? 0) }}</span
                            >
                            <span class="flex gap-0.5">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-8"
                                    :disabled="i === 0"
                                    :aria-label="t('pf.invoice_form.move_up')"
                                    @click="moveLine(i, -1)"
                                >
                                    <ArrowUp
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-8"
                                    :disabled="i === form.items.length - 1"
                                    :aria-label="t('pf.invoice_form.move_down')"
                                    @click="moveLine(i, 1)"
                                >
                                    <ArrowDown
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-8 text-destructive"
                                    :disabled="form.items.length === 1"
                                    :aria-label="
                                        t('pf.invoice_form.remove_line')
                                    "
                                    @click="removeLine(i)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </Button>
                            </span>
                        </div>
                    </li>
                </ol>
                <InputError :message="form.errors.items" />
            </SectionCard>

            <SectionCard
                :icon="NotebookPen"
                :title="t('pf.invoice_form.notes_terms')"
            >
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="inv-notes">{{
                            t('pf.invoice.notes')
                        }}</Label>
                        <Textarea
                            id="inv-notes"
                            v-model="form.notes"
                            rows="4"
                            maxlength="2000"
                            :placeholder="
                                t('pf.invoice_form.notes_placeholder')
                            "
                        />
                        <InputError :message="form.errors.notes" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="inv-terms">{{
                            t('pf.invoice.terms')
                        }}</Label>
                        <Textarea
                            id="inv-terms"
                            v-model="form.terms"
                            rows="4"
                            maxlength="2000"
                            :placeholder="
                                t('pf.invoice_form.terms_placeholder')
                            "
                        />
                        <InputError :message="form.errors.terms" />
                    </div>
                </div>
            </SectionCard>
        </div>

        <aside class="lg:sticky lg:top-20 lg:self-start">
            <div
                class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-soft"
                data-test="invoice-totals-preview"
            >
                <h2 class="text-sm font-semibold">
                    {{ t('pf.invoice_form.summary') }}
                </h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ t('pf.invoice.subtotal') }}
                        </dt>
                        <dd class="tabular-nums" data-test="preview-subtotal">
                            {{
                                m(
                                    openEdit && invoice
                                        ? invoice.subtotal.minor
                                        : totals.subtotal,
                                )
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">
                            {{ t('pf.invoice.tax') }}
                        </dt>
                        <dd class="tabular-nums" data-test="preview-tax">
                            {{
                                m(
                                    openEdit && invoice
                                        ? invoice.tax.minor
                                        : totals.tax,
                                )
                            }}
                        </dd>
                    </div>
                    <div
                        class="flex justify-between gap-3 border-t pt-2 text-base font-semibold"
                    >
                        <dt>{{ t('pf.invoice.total') }}</dt>
                        <dd class="tabular-nums" data-test="preview-total">
                            {{
                                m(
                                    openEdit && invoice
                                        ? invoice.total.minor
                                        : totals.total,
                                )
                            }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="!provider_connected && !openEdit"
                    class="rounded-xl bg-muted/50 p-3 text-xs text-muted-foreground"
                >
                    {{ t('pf.invoice_form.no_provider') }}
                </p>
                <p
                    v-if="itemErrors"
                    role="alert"
                    class="text-xs font-medium text-destructive"
                >
                    {{ t('pf.invoice_form.fix_lines') }}
                </p>
                <p
                    v-if="overLimit"
                    role="alert"
                    class="text-xs font-medium text-destructive"
                >
                    {{ t('pf.invoice_form.over_limit') }}
                </p>

                <div class="flex flex-col gap-2">
                    <template v-if="openEdit">
                        <Button
                            type="button"
                            :disabled="form.processing"
                            data-test="invoice-save"
                            @click="submit('save_draft')"
                        >
                            <Spinner v-if="form.processing" class="size-4" />
                            <Save v-else class="size-4" aria-hidden="true" />
                            {{ t('pf.actions.save_changes') }}
                        </Button>
                    </template>
                    <template v-else>
                        <Button
                            type="button"
                            :disabled="form.processing"
                            data-test="invoice-save-send"
                            @click="submit('save_and_send')"
                        >
                            <Spinner
                                v-if="
                                    form.processing &&
                                    form.action === 'save_and_send'
                                "
                                class="size-4"
                            />
                            <Send v-else class="size-4" aria-hidden="true" />
                            {{ t('pf.actions.save_and_send') }}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            data-test="invoice-save-mark-sent"
                            @click="submit('save_and_mark_sent')"
                        >
                            <Spinner
                                v-if="
                                    form.processing &&
                                    form.action === 'save_and_mark_sent'
                                "
                                class="size-4"
                            />
                            {{ t('pf.actions.save_and_mark_sent') }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="form.processing"
                            data-test="invoice-save-draft"
                            @click="submit('save_draft')"
                        >
                            <Spinner
                                v-if="
                                    form.processing &&
                                    form.action === 'save_draft'
                                "
                                class="size-4"
                            />
                            <Save v-else class="size-4" aria-hidden="true" />
                            {{ t('pf.actions.save_draft') }}
                        </Button>
                    </template>
                    <Button type="button" variant="link" as-child>
                        <Link
                            :href="invoice ? show(invoice.id).url : index().url"
                            >{{ t('ui.common.cancel') }}</Link
                        >
                    </Button>
                </div>
            </div>
        </aside>
    </form>
</template>
