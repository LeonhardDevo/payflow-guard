<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Scale, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { has, t } from '@/lib/i18n';
import { legalDocIcon } from '@/lib/legal';
import type { LegalDocSummary } from '@/types/legal';

const props = withDefaults(defineProps<{ docs?: LegalDocSummary[] }>(), {
    docs: () => [],
});

const fmt = useFormat();

function blurb(key: string): string | null {
    const id = `marketing.legal.blurbs.${key.replace(/-/g, '_')}`;

    return has(id) ? t(id) : null;
}

const items = computed(() =>
    props.docs.map((doc) => ({
        ...doc,
        icon: legalDocIcon(doc.key),
        blurb: blurb(doc.key),
    })),
);
</script>

<template>
    <SeoHead
        :title="t('marketing.legal.index_title')"
        :description="t('marketing.legal.index_description')"
    />

    <div class="mx-auto max-w-5xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <header class="max-w-2xl">
            <p
                class="flex items-center gap-2 text-sm font-semibold text-primary"
            >
                <Scale class="size-4" aria-hidden="true" />
                {{ t('marketing.legal.eyebrow') }}
            </p>
            <h1
                class="mt-2 font-display text-3xl font-extrabold tracking-tight text-balance sm:text-4xl"
            >
                {{ t('marketing.legal.index_title') }}
            </h1>
            <p class="mt-3 text-muted-foreground">
                {{ t('marketing.legal.index_description') }}
            </p>
        </header>

        <ul
            class="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2"
            data-test="legal-index"
        >
            <li v-for="doc in items" :key="doc.key">
                <Link
                    :href="endpoints.legal(doc.key)"
                    class="group flex h-full gap-4 rounded-2xl border bg-card p-5 shadow-soft transition-colors hover:border-primary/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :data-test="`legal-doc-${doc.key}`"
                >
                    <span
                        class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-soft-foreground"
                    >
                        <component
                            :is="doc.icon"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span
                            class="flex items-start justify-between gap-2 font-display font-bold"
                        >
                            {{ doc.title }}
                            <ArrowRight
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-primary"
                                aria-hidden="true"
                            />
                        </span>
                        <span
                            v-if="doc.blurb"
                            class="mt-1 block text-sm text-muted-foreground"
                            >{{ doc.blurb }}</span
                        >
                        <span
                            class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        >
                            <span
                                >{{ t('marketing.legal.version') }}
                                <span class="font-mono text-foreground">{{
                                    doc.version
                                }}</span></span
                            >
                            <span
                                >{{ t('marketing.legal.effective') }}
                                <time
                                    :datetime="doc.effective_date"
                                    class="text-foreground"
                                    >{{ fmt.date(doc.effective_date) }}</time
                                ></span
                            >
                        </span>
                    </span>
                </Link>
            </li>
        </ul>

        <p
            class="mt-10 flex items-start gap-2 rounded-2xl border bg-muted/40 p-4 text-sm text-muted-foreground"
        >
            <ShieldCheck class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                {{ t('marketing.legal.security_hint') }}
                <Link
                    :href="endpoints.security()"
                    class="font-medium text-primary underline underline-offset-2"
                    >{{ t('ui.legal.security') }}</Link
                >
            </span>
        </p>
    </div>
</template>
