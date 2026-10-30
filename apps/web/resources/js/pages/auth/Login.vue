<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'ui.auth.login.title',
        description: 'ui.auth.login.description',
    },
});

withDefaults(
    defineProps<{
        status?: string;
        canResetPassword: boolean;
        canRegister?: boolean;
    }>(),
    {
        status: undefined,
        canRegister: true,
    },
);
</script>

<template>
    <Head :title="t('ui.auth.login.head')" />

    <div
        v-if="status"
        class="mb-6 flex items-center gap-2 rounded-xl border border-success/30 bg-success-soft px-3 py-2.5 text-sm font-medium text-success-soft-foreground"
        role="status"
    >
        <CircleCheck class="size-4 shrink-0" aria-hidden="true" />
        {{ status }}
    </div>

    <PasskeyVerify />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="email">{{ t('ui.fields.email') }}</Label>
                <Input
                    id="email"
                    v-focus
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                    placeholder="email@example.com"
                    class="h-10"
                    :aria-invalid="Boolean(errors.email)"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">{{ t('ui.fields.password') }}</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                    >
                        {{ t('ui.auth.login.forgot') }}
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    class="h-10"
                    :aria-invalid="Boolean(errors.password)"
                />
                <InputError :message="errors.password" />
            </div>

            <Label for="remember" class="flex items-center gap-3 font-normal">
                <Checkbox id="remember" name="remember" />
                <span>{{ t('ui.auth.login.remember') }}</span>
            </Label>

            <Button
                type="submit"
                class="mt-2 h-10 w-full"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                {{ t('ui.auth.login.submit') }}
            </Button>
        </div>

        <p v-if="canRegister" class="text-center text-sm text-muted-foreground">
            {{ t('ui.auth.login.no_account') }}
            <TextLink :href="register()">{{
                t('ui.auth.login.sign_up')
            }}</TextLink>
        </p>
    </Form>
</template>
