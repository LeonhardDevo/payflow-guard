<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { MailCheck, MailOpen } from '@lucide/vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'ui.auth.verify.title',
        description: 'ui.auth.verify.description',
    },
});

defineProps<{ status?: string }>();
</script>

<template>
    <Head :title="t('ui.auth.verify.title')" />

    <div class="mb-6 flex justify-center" aria-hidden="true">
        <span
            class="inline-flex size-16 items-center justify-center rounded-2xl bg-primary-soft text-primary-soft-foreground"
        >
            <MailOpen class="size-8" />
        </span>
    </div>

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-6 flex items-center gap-2 rounded-xl border border-success/30 bg-success-soft px-3 py-2.5 text-sm font-medium text-success-soft-foreground"
        role="status"
    >
        <MailCheck class="size-4 shrink-0" aria-hidden="true" />
        {{ t('ui.auth.verify.sent') }}
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary" class="h-10 w-full">
            <Spinner v-if="processing" />
            {{ t('ui.auth.verify.resend') }}
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            {{ t('ui.shell.log_out') }}
        </TextLink>
    </Form>
</template>
