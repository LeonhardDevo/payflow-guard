<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: {
        title: 'ui.auth.confirm.title',
        description: 'ui.auth.confirm.description',
    },
});
</script>

<template>
    <Head :title="t('ui.auth.confirm.title')" />

    <PasskeyVerify
        :routes="{ options: confirmOptions(), submit: confirmStore() }"
        :label="t('ui.auth.confirm.passkey')"
        :loading-label="t('ui.auth.confirm.confirming')"
        :separator="t('ui.auth.confirm.or_password')"
    />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-5">
            <div class="grid gap-2">
                <Label for="password">{{ t('ui.fields.password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="h-10"
                    required
                    autocomplete="current-password"
                    autofocus
                    :aria-invalid="Boolean(errors.password)"
                />
                <InputError :message="errors.password" />
            </div>

            <Button
                class="h-10 w-full"
                :disabled="processing"
                data-test="confirm-password-button"
            >
                <Spinner v-if="processing" />
                {{ t('ui.auth.confirm.submit') }}
            </Button>
        </div>
    </Form>
</template>
