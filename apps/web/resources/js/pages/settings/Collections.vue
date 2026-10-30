<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import StepUpDialog from '@/components/pf/guard/StepUpDialog.vue';
import CollectionsForm from '@/components/pf/settings/CollectionsForm.vue';
import PolicyEditor from '@/components/pf/settings/PolicyEditor.vue';
import ReceivingDetailsCard from '@/components/pf/settings/ReceivingDetailsCard.vue';
import TemplatesEditor from '@/components/pf/settings/TemplatesEditor.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { t } from '@/lib/i18n';
import { collections, workspace } from '@/routes/settings';
import type {
    CollectionsSettings,
    CurrencyCode,
    MessageTemplate,
    ReminderPolicy,
} from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.settings.title', href: workspace() },
            { title: 'pf.settings.nav.collections', href: collections() },
        ],
    },
});

defineProps<{
    settings: CollectionsSettings;
    policies: ReminderPolicy[];
    templates: MessageTemplate[];
    template_variables: string[];
    timezone: string;
    can_edit_templates: boolean;
    can_change_receiving_details: boolean;
    can_confirm_receiving_details: boolean;
    currencies: CurrencyCode[];
}>();
</script>

<template>
    <Head :title="t('pf.settings.nav.collections')" />

    <Tabs default-value="general" class="flex flex-col gap-6">
        <TabsList class="w-full justify-start overflow-x-auto sm:w-auto">
            <TabsTrigger value="general">{{
                t('pf.settings.collections.tab_general')
            }}</TabsTrigger>
            <TabsTrigger value="policies" data-test="tab-policies">{{
                t('pf.settings.policies.title')
            }}</TabsTrigger>
            <TabsTrigger value="templates" data-test="tab-templates">{{
                t('pf.settings.templates.title')
            }}</TabsTrigger>
            <TabsTrigger value="receiving">{{
                t('pf.settings.receiving.title')
            }}</TabsTrigger>
        </TabsList>
        <TabsContent value="general">
            <CollectionsForm
                :settings="settings"
                :policies="policies"
                :timezone="timezone"
            />
        </TabsContent>
        <TabsContent value="policies">
            <PolicyEditor :policies="policies" />
        </TabsContent>
        <TabsContent value="templates">
            <TemplatesEditor
                :templates="templates"
                :variables="template_variables"
                :can-edit="can_edit_templates"
            />
        </TabsContent>
        <TabsContent value="receiving">
            <ReceivingDetailsCard
                :details="settings.receiving_details"
                :can-change="can_change_receiving_details"
                :can-confirm="can_confirm_receiving_details"
            />
        </TabsContent>
    </Tabs>

    <StepUpDialog />
    <GuardErrorDialog />
</template>
