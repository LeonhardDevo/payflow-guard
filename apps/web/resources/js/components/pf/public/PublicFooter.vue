<script setup lang="ts">
import { t } from '@/lib/i18n';
import type { PublicLegal } from '@/types/payflow';

defineProps<{ legal: PublicLegal; company: string; hideChoices?: boolean }>();
</script>

<template>
    <footer
        class="mt-2 flex flex-col items-center gap-2 text-center text-xs text-muted-foreground"
        data-test="public-footer"
    >
        <p>{{ t('pf.public.sent_by', { company }) }}</p>
        <p>
            {{
                t('pf.public.privacy_contact', { email: legal.privacy_contact })
            }}
        </p>
        <nav
            class="flex flex-wrap justify-center gap-x-4 gap-y-1"
            :aria-label="t('pf.public.legal')"
        >
            <a
                v-if="!hideChoices"
                :href="legal.choices_url"
                class="underline underline-offset-2"
                data-test="choices-link"
                >{{ t('pf.public.choices') }}</a
            >
            <a
                :href="legal.reminder_recipients_url"
                target="_blank"
                rel="noopener"
                class="underline underline-offset-2"
                >{{ t('pf.public.recipients_notice') }}</a
            >
            <a
                :href="legal.terms_url"
                target="_blank"
                rel="noopener"
                class="underline underline-offset-2"
                >{{ t('pf.public.terms') }}</a
            >
            <a
                :href="legal.privacy_url"
                target="_blank"
                rel="noopener"
                class="underline underline-offset-2"
                >{{ t('pf.public.privacy') }}</a
            >
        </nav>
    </footer>
</template>
