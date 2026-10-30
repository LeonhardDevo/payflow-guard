<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    BellRing,
    CircleAlert,
    Clock,
    FileText,
    Mail,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import InvoiceStatusBadge from '@/components/pf/InvoiceStatusBadge.vue';
import ToneChip from '@/components/pf/ToneChip.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { usePf } from '@/composables/pf/usePf';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { TONES } from '@/lib/pf/status';
import { sanitizeAnswer } from '@/lib/sanitize';
import { activate, dismiss, index, show } from '@/routes/imports';
import { show as showInvoice } from '@/routes/invoices';
import type { ImportActivationProps } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.imports', href: index() }],
    },
});

const props = defineProps<ImportActivationProps>();
const pf = usePf();
const fmt = useFormat();

const form = useForm({
    allow_engine_tone: false,
    exclude_invoice_ids: [] as number[],
});

const p = computed(() => props.preview);
const maxTone = computed(() =>
    Math.max(
        1,
        ...TONES.map((tone) =>
            Math.max(
                p.value.tones_engine[tone] ?? 0,
                p.value.tones_capped[tone] ?? 0,
            ),
        ),
    ),
);
const shown = computed(() =>
    form.allow_engine_tone ? p.value.tones_engine : p.value.tones_capped,
);
const raised = computed(
    () => (p.value.tones_engine.firm ?? 0) + (p.value.tones_engine.final ?? 0),
);

function toggleExclude(id: number, included: boolean | 'indeterminate'): void {
    form.exclude_invoice_ids =
        included === true
            ? form.exclude_invoice_ids.filter((item) => item !== id)
            : [...new Set([...form.exclude_invoice_ids, id])];
}

const activeSample = ref(0);
const sample = computed(() => p.value.samples[activeSample.value] ?? null);

const confirmOpen = ref(false);
const dismissing = ref(false);

function submit(): void {
    form.post(activate(props.import.id).url, {
        preserveScroll: true,
        onFinish: () => (confirmOpen.value = false),
    });
}

function dismissImport(): void {
    dismissing.value = true;
    router.post(
        dismiss(props.import.id).url,
        {},
        { onFinish: () => (dismissing.value = false) },
    );
}

const willActivate = computed(
    () => props.invoices.length - form.exclude_invoice_ids.length,
);
</script>

<template>
    <Head :title="t('pf.activation.title')" />

    <div
        class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="BellRing"
            :title="t('pf.activation.title')"
            :description="
                t('pf.activation.description', {
                    name: props.import.original_name,
                })
            "
        />

        <div
            class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
        >
            <ShieldCheck class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>{{ t('pf.activation.safety') }}</p>
        </div>

        <div
            class="grid grid-cols-1 gap-3 sm:grid-cols-3"
            data-test="activation-stats"
        >
            <KpiCard
                :label="t('pf.activation.invoices')"
                :value="p.invoices"
                :icon="FileText"
            />
            <KpiCard
                :label="t('pf.activation.overdue')"
                :value="p.overdue"
                :icon="Clock"
                tone="warning"
            />
            <KpiCard
                :label="t('pf.activation.beyond_escalation')"
                :value="p.beyond_escalation"
                :icon="TriangleAlert"
                tone="ai"
                :hint="t('pf.activation.beyond_escalation_hint')"
            />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <SectionCard
                :icon="BellRing"
                :title="t('pf.activation.tones')"
                :description="t('pf.activation.tones_hint')"
            >
                <table class="w-full text-sm" data-test="activation-tones">
                    <caption class="sr-only">
                        {{
                            t('pf.activation.tones')
                        }}
                    </caption>
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-2 font-medium">
                                {{ t('pf.reminder.tone') }}
                            </th>
                            <th class="py-2 text-right font-medium">
                                {{ t('pf.activation.engine') }}
                            </th>
                            <th class="py-2 text-right font-medium">
                                {{ t('pf.activation.capped') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="tone in TONES"
                            :key="tone"
                            class="border-t"
                            :data-tone="tone"
                        >
                            <td class="py-2"><ToneChip :tone="tone" /></td>
                            <td class="py-2 text-right tabular-nums">
                                {{ p.tones_engine[tone] ?? 0 }}
                            </td>
                            <td
                                class="py-2 text-right font-medium tabular-nums"
                            >
                                {{ p.tones_capped[tone] ?? 0 }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-4 space-y-2" aria-hidden="true">
                    <div
                        v-for="tone in TONES"
                        :key="tone"
                        class="flex items-center gap-2"
                    >
                        <span
                            class="w-24 shrink-0 text-xs text-muted-foreground"
                            >{{ t(`pf.tone.${tone}`) }}</span
                        >
                        <div
                            class="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                :class="[
                                    'h-full rounded-full transition-all motion-reduce:transition-none',
                                    tone === 'final'
                                        ? 'bg-destructive'
                                        : tone === 'firm'
                                          ? 'bg-warning'
                                          : tone === 'neutral'
                                            ? 'bg-info'
                                            : 'bg-success',
                                ]"
                                :style="{
                                    width: `${((shown[tone] ?? 0) / maxTone) * 100}%`,
                                }"
                            />
                        </div>
                        <span class="w-8 text-right text-xs tabular-nums">{{
                            shown[tone] ?? 0
                        }}</span>
                    </div>
                </div>
            </SectionCard>

            <SectionCard
                :icon="Clock"
                :title="t('pf.activation.slots')"
                :description="t('pf.activation.slots_hint')"
            >
                <p
                    v-if="!p.first_slots.length"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('pf.activation.no_slots') }}
                </p>
                <ol v-else class="space-y-2" data-test="activation-slots">
                    <li
                        v-for="slot in p.first_slots"
                        :key="slot.hour_start"
                        class="flex items-center justify-between gap-3 rounded-xl border px-3 py-2 text-sm"
                    >
                        <time :datetime="slot.hour_start">{{
                            fmt.dateTime(slot.hour_start, 'medium')
                        }}</time>
                        <span class="text-muted-foreground tabular-nums">{{
                            t('pf.activation.emails', { count: slot.count })
                        }}</span>
                    </li>
                </ol>
            </SectionCard>
        </div>

        <SectionCard
            v-if="p.samples.length"
            :icon="Mail"
            :title="t('pf.activation.samples')"
        >
            <div class="mb-3 flex flex-wrap gap-2" role="tablist">
                <button
                    v-for="(item, i) in p.samples"
                    :key="`${item.tone}-${item.locale}`"
                    type="button"
                    role="tab"
                    :aria-selected="activeSample === i"
                    :class="[
                        'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        activeSample === i
                            ? 'border-primary bg-primary/5'
                            : 'hover:bg-muted/50',
                    ]"
                    @click="activeSample = i"
                >
                    <ToneChip :tone="item.tone" />
                    {{ item.locale.toUpperCase() }}
                </button>
            </div>
            <div
                v-if="sample"
                class="overflow-hidden rounded-xl border"
                role="tabpanel"
            >
                <p class="border-b bg-muted/30 px-4 py-2 text-sm font-medium">
                    {{ sample.subject }}
                </p>
                <div
                    class="answer-prose px-4 py-3 text-sm"
                    v-html="sanitizeAnswer(sample.body_html)"
                />
            </div>
        </SectionCard>

        <SectionCard
            :icon="FileText"
            :title="t('pf.activation.invoice_list')"
            :description="t('pf.activation.invoice_list_hint')"
            flush
        >
            <ul
                class="max-h-[28rem] divide-y overflow-y-auto"
                data-test="activation-invoices"
            >
                <li
                    v-for="invoice in invoices"
                    :key="invoice.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-5 py-2.5"
                >
                    <Label class="flex min-w-0 items-center gap-3 font-normal">
                        <Checkbox
                            :model-value="
                                !form.exclude_invoice_ids.includes(invoice.id)
                            "
                            :aria-label="
                                t('pf.activation.include', {
                                    number: invoice.number ?? '',
                                })
                            "
                            @update:model-value="
                                (v) => toggleExclude(invoice.id, v)
                            "
                        />
                        <span class="min-w-0">
                            <Link
                                :href="showInvoice(invoice.id).url"
                                class="font-mono text-sm hover:underline"
                                @click.stop
                                >{{ invoice.number }}</Link
                            >
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ invoice.client.name }}</span
                            >
                        </span>
                    </Label>
                    <span class="flex items-center gap-3">
                        <InvoiceStatusBadge
                            :status="invoice.display_status"
                            :partial="invoice.is_partially_paid"
                            :days-overdue="invoice.days_overdue"
                        />
                        <span class="text-sm font-medium tabular-nums">{{
                            pf.money(invoice.balance_due)
                        }}</span>
                    </span>
                </li>
            </ul>
        </SectionCard>

        <div
            class="sticky bottom-0 z-10 -mx-4 flex flex-col gap-3 border-t bg-background/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border"
        >
            <Label class="flex items-start gap-3 text-sm font-normal">
                <Checkbox
                    v-model="form.allow_engine_tone"
                    class="mt-0.5"
                    data-test="allow-engine-tone"
                />
                <span>
                    {{ t('pf.activation.allow_engine_tone') }}
                    <span
                        v-if="raised"
                        class="mt-0.5 flex items-center gap-1 text-xs text-warning-soft-foreground"
                    >
                        <CircleAlert class="size-3.5" aria-hidden="true" />
                        {{
                            t('pf.activation.allow_engine_warning', {
                                count: raised,
                            })
                        }}
                    </span>
                </span>
            </Label>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Button variant="ghost" as-child>
                    <Link :href="show(props.import.id).url">{{
                        t('ui.common.back')
                    }}</Link>
                </Button>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        :disabled="dismissing || form.processing"
                        data-test="activation-dismiss"
                        @click="dismissImport"
                    >
                        <Spinner v-if="dismissing" class="size-4" />
                        {{ t('pf.activation.keep_paused') }}
                    </Button>
                    <Button
                        :disabled="form.processing || willActivate <= 0"
                        data-test="activation-submit"
                        @click="confirmOpen = true"
                    >
                        {{
                            t('pf.activation.activate', { count: willActivate })
                        }}
                    </Button>
                </div>
            </div>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="t('pf.activation.confirm_title')"
        :description="
            form.allow_engine_tone
                ? t('pf.activation.confirm_engine')
                : t('pf.activation.confirm_capped')
        "
        :confirm-label="t('pf.activation.activate', { count: willActivate })"
        :processing="form.processing"
        @confirm="submit"
    />
</template>
