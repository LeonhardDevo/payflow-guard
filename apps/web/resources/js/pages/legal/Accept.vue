<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ExternalLink, FileCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { legalDocIcon } from '@/lib/legal';
import { toUrl } from '@/lib/utils';
import type { PendingAcceptance } from '@/types/legal';

withDefaults(defineProps<{ pending?: PendingAcceptance[] }>(), {
    pending: () => [],
});

const form = useForm({ accept: false });

function submit(): void {
    if (!form.accept) {
        return;
    }

    form.submit(endpoints.legalPages.acceptStore());
}
</script>

<template>
    <Head :title="t('ui.legal.accept.title')" />

    <div class="mx-auto w-full max-w-2xl flex-1 py-8 sm:py-12">
        <div
            class="rounded-3xl border bg-card/95 p-6 shadow-lifted backdrop-blur sm:p-8"
            data-test="legal-accept"
        >
            <span
                class="inline-flex size-12 items-center justify-center rounded-2xl bg-primary-soft text-primary-soft-foreground"
            >
                <FileCheck class="size-6" aria-hidden="true" />
            </span>
            <h1
                class="mt-4 font-display text-2xl font-bold tracking-tight sm:text-3xl"
            >
                {{ t('ui.legal.accept.title') }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ t('ui.legal.accept.description') }}
            </p>

            <ul class="mt-6 space-y-3">
                <li
                    v-for="doc in pending"
                    :key="doc.key"
                    class="rounded-2xl border p-4"
                    :data-test="`legal-accept-doc-${doc.key}`"
                >
                    <div class="flex items-start gap-3">
                        <component
                            :is="legalDocIcon(doc.key)"
                            class="mt-0.5 size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1">
                            <div
                                class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                            >
                                <p class="font-semibold">{{ doc.title }}</p>
                                <span
                                    class="font-mono text-xs text-muted-foreground"
                                    >{{
                                        t('ui.legal.accept.version', {
                                            version: doc.version,
                                        })
                                    }}</span
                                >
                            </div>
                            <p
                                v-if="doc.summary_of_changes"
                                class="mt-2 text-sm text-muted-foreground"
                            >
                                <span class="font-medium text-foreground"
                                    >{{ t('ui.legal.accept.changes') }}
                                </span>
                                {{ doc.summary_of_changes }}
                            </p>
                            <a
                                :href="
                                    doc.url ?? toUrl(endpoints.legal(doc.key))
                                "
                                target="_blank"
                                rel="noopener"
                                class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary underline-offset-2 hover:underline"
                            >
                                {{ t('ui.legal.accept.read') }}
                                <ExternalLink
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">{{
                                    t('ui.common.opens_new_tab')
                                }}</span>
                            </a>
                        </div>
                    </div>
                </li>
            </ul>

            <form class="mt-6 space-y-4" @submit.prevent="submit">
                <Label
                    for="legal-accept-agree"
                    class="flex items-start gap-3 rounded-xl border bg-muted/40 p-3 leading-snug font-normal"
                >
                    <Checkbox
                        id="legal-accept-agree"
                        v-model="form.accept"
                        class="mt-0.5"
                        data-test="legal-accept-checkbox"
                    />
                    <span>{{ t('ui.legal.accept.agree') }}</span>
                </Label>
                <InputError :message="form.errors.accept" />
                <Button
                    type="submit"
                    class="h-10 w-full"
                    :disabled="!form.accept || form.processing"
                    data-test="legal-accept-submit"
                >
                    <Spinner v-if="form.processing" />
                    {{ t('ui.legal.accept.submit') }}
                </Button>
                <p class="text-center text-xs text-muted-foreground">
                    {{ t('ui.legal.accept.decline_hint') }}
                </p>
            </form>
        </div>
    </div>
</template>
