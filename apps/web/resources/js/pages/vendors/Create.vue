<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Store } from '@lucide/vue';
import PageHeader from '@/components/common/PageHeader.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import VendorForm from '@/components/pf/guard/VendorForm.vue';
import { t } from '@/lib/i18n';
import { index } from '@/routes/vendors';
import type { VendorFormProps } from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.vendors', href: index() }],
    },
});

defineProps<VendorFormProps>();
</script>

<template>
    <Head :title="t('guard.vendors.create')" />

    <div
        class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <GuardBand />
        <PageHeader
            :icon="Store"
            :title="t('guard.vendors.create')"
            :description="t('guard.vendor_form.create_description')"
        />
        <VendorForm v-bind="$props" />
    </div>
</template>
