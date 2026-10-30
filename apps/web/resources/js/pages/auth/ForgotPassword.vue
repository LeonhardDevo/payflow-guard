<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { MailCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'ui.auth.forgot.title',
        description: 'ui.auth.forgot.description',
    },
});

defineProps<{ status?: string }>();
</script>

<template>
    <Head :title="t('ui.auth.forgot.title')" />

    <div
        v-if="status"
        class="mb-6 flex items-center gap-2 rounded-xl border border-success/30 bg-success-soft px-3 py-2.5 text-sm font-medium text-success-soft-foreground"
        role="status"
    >
        <MailCheck class="size-4 shrink-0" aria-hidden="true" />
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form
            v-bind="email.form()"
            v-slot="{ errors, processing }"
            class="space-y-6"
        >
            <div class="grid gap-2">
                <Label for="email">{{ t('ui.fields.email') }}</Label>
                <Input
                    id="email"
                    v-focus
                    type="email"
                    name="email"
                    autocomplete="email"
                    placeholder="email@example.com"
                    class="h-10"
                    :aria-invalid="Boolean(errors.email)"
                />
                <InputError :message="errors.email" />
            </div>

            <Button
                class="h-10 w-full"
                :disabled="processing"
                data-test="email-password-reset-link-button"
            >
                <Spinner v-if="processing" />
                {{ t('ui.auth.forgot.submit') }}
            </Button>
        </Form>

        <p class="text-center text-sm text-muted-foreground">
            {{ t('ui.auth.forgot.return_to') }}
            <TextLink :href="login()">{{
                t('ui.auth.register.log_in')
            }}</TextLink>
        </p>
    </div>
</template>
