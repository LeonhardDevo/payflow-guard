<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FilePlus } from '@lucide/vue';
import PageHeader from '@/components/common/PageHeader.vue';
import InvoiceForm from '@/components/pf/InvoiceForm.vue';
import { t } from '@/lib/i18n';
import { create, index } from '@/routes/invoices';
import type { InvoiceFormProps } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'pf.nav.invoices', href: index() },
            { title: 'pf.invoices.create', href: create() },
        ],
    },
});

defineProps<InvoiceFormProps>();
</script>

<template>
    <Head :title="t('pf.invoices.create')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="FilePlus"
            :title="t('pf.invoices.create')"
            :description="t('pf.invoice_form.create_description')"
        />
        <InvoiceForm v-bind="$props" />
    </div>
</template>
