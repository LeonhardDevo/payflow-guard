<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import SectionCard from '@/components/common/SectionCard.vue';
import InputError from '@/components/InputError.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.settings.title', href: edit() },
            { title: 'ui.settings.nav.security', href: edit() },
        ],
    },
});
</script>

<template>
    <Head :title="t('ui.settings.security.title')" />

    <SectionCard
        :icon="KeyRound"
        :title="t('ui.settings.security.password_title')"
        :description="t('ui.settings.security.password_description')"
    >
        <Form
            v-bind="SecurityController.update.form()"
            :options="{ preserveScroll: true }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-5"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <div class="grid gap-2">
                <Label for="current_password">{{
                    t('ui.fields.current_password')
                }}</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    autocomplete="current-password"
                    :aria-invalid="Boolean(errors.current_password)"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="password">{{
                        t('ui.fields.new_password')
                    }}</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        autocomplete="new-password"
                        :passwordrules="props.passwordRules"
                        :aria-invalid="Boolean(errors.password)"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{
                        t('ui.fields.confirm_password')
                    }}</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        autocomplete="new-password"
                        :passwordrules="props.passwordRules"
                        :aria-invalid="Boolean(errors.password_confirmation)"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('ui.settings.security.update_password') }}
                </Button>
                <p
                    v-show="recentlySuccessful"
                    class="text-sm text-muted-foreground"
                    role="status"
                >
                    {{ t('ui.common.saved') }}
                </p>
            </div>
        </Form>
    </SectionCard>

    <ManageTwoFactor
        :can-manage-two-factor="canManageTwoFactor"
        :requires-confirmation="requiresConfirmation"
        :two-factor-enabled="twoFactorEnabled"
    />

    <ManagePasskeys
        :can-manage-passkeys="canManagePasskeys"
        :passkeys="passkeys"
    />
</template>
