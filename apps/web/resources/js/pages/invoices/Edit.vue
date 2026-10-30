<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FilePen } from '@lucide/vue';
import PageHeader from '@/components/common/PageHeader.vue';
import InvoiceForm from '@/components/pf/InvoiceForm.vue';
import { t } from '@/lib/i18n';
import { index } from '@/routes/invoices';
import type { InvoiceFormProps } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.invoices', href: index() }],
    },
});

defineProps<InvoiceFormProps>();
</script>

<template>
    <Head
        :title="t('pf.invoices.edit_title', { number: invoice?.number ?? '' })"
    />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="FilePen"
            :eyebrow="t('pf.invoices.edit')"
            :title="invoice?.number ?? t('pf.invoice.draft_number')"
            :description="invoice?.client.name"
        />
        <InvoiceForm v-bind="$props" />
    </div>
</template>
