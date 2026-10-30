<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import { login } from '@/routes';
import { store } from '@/routes/register';
import type { RegisterLegal } from '@/types/legal';

const props = withDefaults(
    defineProps<{ passwordRules: string; legal?: RegisterLegal | null }>(),
    { legal: null },
);

const acceptTerms = ref(false);
const acceptConsentRu = ref(false);

function docUrl(key: 'terms' | 'privacy'): string {
    return props.legal?.[key]?.url ?? toUrl(endpoints.legal(key));
}

defineOptions({
    layout: {
        title: 'ui.auth.register.title',
        description: 'ui.auth.register.description',
    },
});
</script>

<template>
    <Head :title="t('ui.auth.register.head')" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="name">{{ t('ui.fields.name') }}</Label>
                <Input
                    id="name"
                    v-focus
                    type="text"
                    required
                    autocomplete="name"
                    name="name"
                    class="h-10"
                    :placeholder="t('ui.fields.name_placeholder')"
                    :aria-invalid="Boolean(errors.name)"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('ui.fields.work_email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autocomplete="email"
                    name="email"
                    class="h-10"
                    placeholder="name@company.com"
                    :aria-invalid="Boolean(errors.email)"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ t('ui.fields.password') }}</Label>
                <PasswordInput
                    id="password"
                    required
                    autocomplete="new-password"
                    name="password"
                    class="h-10"
                    :passwordrules="passwordRules"
                    :aria-invalid="Boolean(errors.password)"
                    aria-describedby="password-hint"
                />
                <p id="password-hint" class="text-xs text-muted-foreground">
                    {{ t('ui.auth.register.password_hint') }}
                </p>
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    t('ui.fields.confirm_password')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                    name="password_confirmation"
                    class="h-10"
                    :passwordrules="passwordRules"
                    :aria-invalid="Boolean(errors.password_confirmation)"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <fieldset class="grid gap-3" data-test="register-consents">
                <legend class="sr-only">
                    {{ t('ui.auth.register.consents') }}
                </legend>
                <div class="grid gap-1.5">
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="accept_terms"
                            v-model="acceptTerms"
                            name="terms"
                            value="1"
                            class="mt-0.5"
                            :aria-invalid="Boolean(errors.terms)"
                            data-test="register-accept-terms"
                        />
                        <Label
                            for="accept_terms"
                            class="block text-sm leading-snug font-normal text-muted-foreground"
                        >
                            {{ t('ui.auth.register.accept_prefix') }}
                            <a
                                :href="docUrl('terms')"
                                target="_blank"
                                rel="noopener"
                                class="font-medium text-foreground underline underline-offset-2 hover:text-primary"
                                >{{ t('ui.legal.terms') }}</a
                            >
                            {{ t('ui.auth.register.agree_and') }}
                            <a
                                :href="docUrl('privacy')"
                                target="_blank"
                                rel="noopener"
                                class="font-medium text-foreground underline underline-offset-2 hover:text-primary"
                                >{{ t('ui.legal.privacy') }}</a
                            >
                        </Label>
                    </div>
                    <InputError :message="errors.terms" />
                </div>
                <div v-if="legal?.consent_ru" class="grid gap-1.5">
                    <div class="flex items-start gap-3">
                        <Checkbox
                            id="accept_consent_ru"
                            v-model="acceptConsentRu"
                            name="consent_ru"
                            value="1"
                            class="mt-0.5"
                            :aria-invalid="Boolean(errors.consent_ru)"
                            data-test="register-accept-consent-ru"
                        />
                        <Label
                            for="accept_consent_ru"
                            class="block text-sm leading-snug font-normal text-muted-foreground"
                        >
                            {{ t('ui.auth.register.consent_ru_prefix') }}
                            <a
                                :href="legal.consent_ru.url"
                                target="_blank"
                                rel="noopener"
                                class="font-medium text-foreground underline underline-offset-2 hover:text-primary"
                                >{{ t('ui.legal.docs.consent_ru') }}</a
                            >
                        </Label>
                    </div>
                    <InputError :message="errors.consent_ru" />
                </div>
            </fieldset>

            <Button
                type="submit"
                class="mt-2 h-10 w-full"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                {{ t('ui.auth.register.submit') }}
            </Button>
        </div>

        <p class="text-center text-sm text-muted-foreground">
            {{ t('ui.auth.register.have_account') }}
            <TextLink :href="login()">{{
                t('ui.auth.register.log_in')
            }}</TextLink>
        </p>
    </Form>
</template>
