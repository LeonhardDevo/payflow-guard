<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ShieldCheck, ShieldOff } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { t } from '@/lib/i18n';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <SectionCard
        v-if="canManageTwoFactor"
        :icon="ShieldCheck"
        :title="t('ui.settings.two_factor.title')"
        :description="t('ui.settings.two_factor.description')"
    >
        <template #actions>
            <StatusBadge
                :tone="twoFactorEnabled ? 'success' : 'neutral'"
                :label="
                    twoFactorEnabled
                        ? t('ui.settings.two_factor.enabled')
                        : t('ui.settings.two_factor.disabled')
                "
            />
        </template>

        <div v-if="!twoFactorEnabled" class="flex flex-col items-start gap-4">
            <p class="text-sm text-muted-foreground">
                {{ t('ui.settings.two_factor.intro') }}
            </p>
            <Button v-if="hasSetupData" @click="showSetupModal = true">
                <ShieldCheck class="size-4" aria-hidden="true" />
                {{ t('ui.settings.two_factor.continue_setup') }}
            </Button>
            <Form
                v-else
                v-bind="enable.form()"
                @success="showSetupModal = true"
                #default="{ processing }"
            >
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    {{ t('ui.settings.two_factor.enable') }}
                </Button>
            </Form>
        </div>

        <div v-else class="flex flex-col items-start gap-4">
            <p class="text-sm text-muted-foreground">
                {{ t('ui.settings.two_factor.active_intro') }}
            </p>
            <Form v-bind="disable.form()" #default="{ processing }">
                <Button
                    variant="destructive"
                    type="submit"
                    :disabled="processing"
                >
                    <ShieldOff class="size-4" aria-hidden="true" />
                    {{ t('ui.settings.two_factor.disable') }}
                </Button>
            </Form>
            <TwoFactorRecoveryCodes />
        </div>

        <TwoFactorSetupModal
            v-model:is-open="showSetupModal"
            :requires-confirmation="requiresConfirmation"
            :two-factor-enabled="twoFactorEnabled"
        />
    </SectionCard>
</template>
