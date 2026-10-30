<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
import PageHeader from '@/components/common/PageHeader.vue';
import ClientForm from '@/components/pf/ClientForm.vue';
import { t } from '@/lib/i18n';
import { create, index } from '@/routes/clients';
import type { ClientFormProps } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'pf.nav.clients', href: index() },
            { title: 'pf.clients.create', href: create() },
        ],
    },
});

defineProps<ClientFormProps>();
</script>

<template>
    <Head :title="t('pf.clients.create')" />

    <div
        class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="Building2"
            :title="t('pf.clients.create')"
            :description="t('pf.client_form.create_description')"
        />
        <ClientForm v-bind="$props" />
    </div>
</template>
