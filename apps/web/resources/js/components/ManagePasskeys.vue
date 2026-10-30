<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Fingerprint, KeyRound } from '@lucide/vue';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import EmptyState from '@/components/common/EmptyState.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import PasskeyItem from '@/components/PasskeyItem.vue';
import PasskeyRegister from '@/components/PasskeyRegister.vue';
import { t } from '@/lib/i18n';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

withDefaults(defineProps<Props>(), {
    canManagePasskeys: false,
    passkeys: () => [],
});

const handleDelete = (id: number, onError: () => void) => {
    router.delete(destroy.url(id), {
        preserveScroll: true,
        onError,
    });
};

const handleRegisterSuccess = () => {
    router.reload();
};
</script>

<template>
    <SectionCard
        v-if="canManagePasskeys"
        :icon="Fingerprint"
        :title="t('ui.settings.passkeys.title')"
        :description="t('ui.settings.passkeys.description')"
    >
        <div class="space-y-4">
            <div class="overflow-hidden rounded-xl border">
                <template v-if="passkeys.length">
                    <PasskeyItem
                        v-for="passkey in passkeys"
                        :key="passkey.id"
                        :passkey="passkey"
                        @remove="handleDelete"
                    />
                </template>
                <EmptyState
                    v-else
                    compact
                    tone="muted"
                    :icon="KeyRound"
                    :title="t('ui.settings.passkeys.empty_title')"
                    :description="t('ui.settings.passkeys.empty_body')"
                />
            </div>
            <PasskeyRegister @success="handleRegisterSuccess" />
        </div>
    </SectionCard>
</template>
