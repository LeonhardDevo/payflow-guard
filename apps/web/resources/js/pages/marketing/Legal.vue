<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarDays,
    History,
    ListTree,
    Printer,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { legalDocIcon } from '@/lib/legal';
import { cn } from '@/lib/utils';
import type { LegalDoc, LegalVersion } from '@/types/legal';

const props = withDefaults(
    defineProps<{
        doc: LegalDoc;
        versions?: LegalVersion[];
        reviewed?: boolean;
    }>(),
    { versions: () => [], reviewed: false },
);

const fmt = useFormat();

const icon = computed(() => legalDocIcon(props.doc.key));
const toc = computed(() =>
    props.doc.toc.filter((entry) => entry.level === 2 || entry.level === 3),
);

const sortedVersions = computed(() =>
    [...props.versions].sort((a, b) =>
        b.effective_date.localeCompare(a.effective_date),
    ),
);
const latest = computed(
    () => sortedVersions.value[0]?.version ?? props.doc.version,
);
const isArchived = computed(() => props.doc.version !== latest.value);

const selectedVersion = ref(props.doc.version);

watch(
    () => props.doc.version,
    (value) => (selectedVersion.value = value),
);

function switchVersion(value: unknown): void {
    if (typeof value !== 'string' || value === props.doc.version) {
        return;
    }

    router.visit(
        endpoints.legalVersion(
            props.doc.key,
            value === latest.value ? null : value,
        ),
        { preserveScroll: false },
    );
}

const activeId = ref<string | null>(toc.value[0]?.id ?? null);
let observer: IntersectionObserver | null = null;
const visible = new Map<string, number>();

function observeHeadings(): void {
    observer?.disconnect();
    visible.clear();

    if (typeof IntersectionObserver === 'undefined') {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    visible.set(entry.target.id, entry.boundingClientRect.top);
                } else {
                    visible.delete(entry.target.id);
                }
            }

            if (visible.size > 0) {
                const [first] = [...visible.entries()].sort(
                    (a, b) => a[1] - b[1],
                );
                activeId.value = first[0];
            }
        },
        { rootMargin: '-96px 0px -65% 0px', threshold: [0, 1] },
    );

    for (const entry of toc.value) {
        const element = document.getElementById(entry.id);

        if (element) {
            observer.observe(element);
        }
    }
}

onMounted(observeHeadings);
watch(
    () => props.doc.html,
    () => requestAnimationFrame(observeHeadings),
);
onBeforeUnmount(() => observer?.disconnect());

function jumpTo(event: MouseEvent, id: string): void {
    const element = document.getElementById(id);

    if (!element) {
        return;
    }

    event.preventDefault();
    activeId.value = id;
    element.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
        block: 'start',
    });
    history.replaceState(history.state, '', `#${id}`);
}

function print(): void {
    window.print();
}
</script>

<template>
    <SeoHead
        :title="doc.title"
        :description="
            t('marketing.legal.meta_description', { title: doc.title })
        "
    />

    <div
        class="legal-page mx-auto grid max-w-6xl grid-cols-1 gap-10 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-14 lg:px-8"
        data-test="legal-page"
        :data-doc="doc.key"
    >
        <aside class="hidden lg:block print:hidden">
            <nav
                :aria-label="t('marketing.legal.toc')"
                class="sticky top-24 max-h-[calc(100svh-8rem)] space-y-1 overflow-y-auto pr-2 text-sm"
                data-test="legal-toc"
            >
                <p
                    class="mb-3 flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    <ListTree class="size-3.5" aria-hidden="true" />
                    {{ t('marketing.legal.toc') }}
                </p>
                <a
                    v-for="entry in toc"
                    :key="entry.id"
                    :href="`#${entry.id}`"
                    :aria-current="
                        activeId === entry.id ? 'location' : undefined
                    "
                    :class="
                        cn(
                            'block rounded-md border-l-2 py-1.5 pr-2 leading-snug transition-colors',
                            entry.level === 3 ? 'pl-6 text-xs' : 'pl-3',
                            activeId === entry.id
                                ? 'border-primary bg-primary-soft/50 font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:bg-muted hover:text-foreground',
                        )
                    "
                    @click="jumpTo($event, entry.id)"
                    >{{ entry.text }}</a
                >
                <Link
                    :href="endpoints.legalPages.index()"
                    class="mt-5 flex items-center gap-1.5 rounded-md px-3 py-1.5 font-medium text-primary hover:bg-primary-soft"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    {{ t('marketing.legal.all_documents') }}
                </Link>
            </nav>
        </aside>

        <article class="min-w-0">
            <header class="border-b pb-8">
                <Link
                    :href="endpoints.legalPages.index()"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline lg:hidden print:hidden"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    {{ t('marketing.legal.all_documents') }}
                </Link>
                <p
                    class="mt-4 flex items-center gap-2 text-sm font-semibold text-primary lg:mt-0"
                >
                    <component :is="icon" class="size-4" aria-hidden="true" />
                    {{ t('marketing.legal.eyebrow') }}
                </p>
                <h1
                    class="mt-2 font-display text-3xl font-extrabold tracking-tight text-balance break-words hyphens-auto sm:text-4xl"
                    data-test="legal-title"
                >
                    {{ doc.title }}
                </h1>

                <dl
                    class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted-foreground"
                >
                    <div class="flex items-center gap-1.5">
                        <dt class="flex items-center gap-1.5">
                            <CalendarDays class="size-4" aria-hidden="true" />
                            {{ t('marketing.legal.effective') }}
                        </dt>
                        <dd class="font-medium text-foreground">
                            <time :datetime="doc.effective_date">{{
                                fmt.date(doc.effective_date, 'long')
                            }}</time>
                        </dd>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <dt>{{ t('marketing.legal.version') }}</dt>
                        <dd
                            class="font-mono font-medium text-foreground"
                            data-test="legal-version"
                        >
                            {{ doc.version }}
                        </dd>
                    </div>
                </dl>

                <div
                    class="mt-5 flex flex-wrap items-center gap-2 print:hidden"
                >
                    <div
                        v-if="sortedVersions.length > 1"
                        class="flex items-center gap-2"
                    >
                        <label
                            for="legal-version"
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <History class="size-4" aria-hidden="true" />
                            {{ t('marketing.legal.versions') }}
                        </label>
                        <Select
                            v-model="selectedVersion"
                            @update:model-value="switchVersion"
                        >
                            <SelectTrigger
                                id="legal-version"
                                class="h-9 w-auto min-w-44"
                                data-test="legal-version-switcher"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="(item, index) in sortedVersions"
                                    :key="item.version"
                                    :value="item.version"
                                >
                                    {{
                                        t(
                                            index === 0
                                                ? 'marketing.legal.version_current'
                                                : 'marketing.legal.version_option',
                                            {
                                                version: item.version,
                                                date: fmt.date(
                                                    item.effective_date,
                                                ),
                                            },
                                        )
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-9"
                        data-test="legal-print"
                        @click="print"
                    >
                        <Printer class="size-4" aria-hidden="true" />
                        {{ t('marketing.legal.print') }}
                    </Button>
                </div>

                <p
                    v-if="isArchived"
                    class="mt-6 flex flex-wrap items-center gap-2 rounded-xl border border-warning/40 bg-warning-soft p-3 text-sm text-warning-soft-foreground"
                    role="status"
                    data-test="legal-archived"
                >
                    <History class="size-4 shrink-0" aria-hidden="true" />
                    {{ t('marketing.legal.archived', { version: latest }) }}
                    <Link
                        :href="endpoints.legal(doc.key)"
                        class="font-semibold underline underline-offset-2"
                        >{{ t('marketing.legal.view_current') }}</Link
                    >
                </p>

                <p
                    v-if="!reviewed"
                    class="mt-6 flex gap-2 rounded-xl border border-warning/40 bg-warning-soft p-3 text-sm text-warning-soft-foreground"
                    role="note"
                    data-test="legal-template-banner"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{ t('marketing.legal.template_note') }}
                </p>
            </header>

            <div
                class="legal-prose pt-8"
                data-test="legal-body"
                v-html="doc.html"
            />
        </article>
    </div>
</template>
