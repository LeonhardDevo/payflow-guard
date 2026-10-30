<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
import PageHeader from '@/components/common/PageHeader.vue';
import ClientForm from '@/components/pf/ClientForm.vue';
import { t } from '@/lib/i18n';
import { index } from '@/routes/clients';
import type { ClientFormProps } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.clients', href: index() }],
    },
});

defineProps<ClientFormProps>();
</script>

<template>
    <Head :title="t('pf.clients.edit_title', { name: client?.name ?? '' })" />

    <div
        class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="Building2"
            :eyebrow="t('pf.clients.edit')"
            :title="client?.name ?? ''"
        />
        <ClientForm v-bind="$props" />
    </div>
</template>
