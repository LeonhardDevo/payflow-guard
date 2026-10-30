<script setup lang="ts">
import { Head, useForm, useHttp } from '@inertiajs/vue3';
import {
    BarChart3,
    CircleCheck,
    CircleX,
    Coins,
    Cpu,
    Database,
    PlugZap,
    RefreshCw,
    Save,
    SlidersHorizontal,
    Sparkles,
    Activity,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProviderCard from '@/components/admin-ai/ProviderCard.vue';
import UsageChart from '@/components/admin-ai/UsageChart.vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { endpoints, withQuery } from '@/lib/endpoints';
import { describeHttpError } from '@/lib/http-errors';
import { t, tOr } from '@/lib/i18n';
import { normalizeModels } from '@/lib/models';
import type {
    AiSettings,
    ConnectionTestResult,
    EmbeddingsProvider,
    ModelInfo,
    ModelsResponse,
    Provider,
    UsageByModel,
    UsageSummary,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.nav.admin_ai', href: endpoints.admin.ai.index() },
        ],
    },
});

const props = withDefaults(
    defineProps<{
        providers: Provider[];
        settings: AiSettings;
        usage: UsageSummary;
        embeddingsProviders?: string[];
        serviceError?: string | null;
    }>(),
    {
        embeddingsProviders: () => ['local', 'openai-compatible', 'hash'],
        serviceError: null,
    },
);

const readOnly = computed(() => Boolean(props.serviceError));

const fmt = useFormat();

const form = useForm({
    provider: props.settings.provider,
    model: props.settings.model,
    fallback_model: props.settings.fallback_model,
    temperature: props.settings.temperature,
    embeddings: {
        provider: props.settings.embeddings.provider,
        model: props.settings.embeddings.model,
    },
});

const activeProvider = computed(
    () =>
        props.providers.find((provider) => provider.id === form.provider) ??
        null,
);

const modelsHttp = useHttp<Record<string, never>, ModelsResponse>({});
const liveModels = ref<Record<string, ModelInfo[]>>({});
const modelsError = ref<string | null>(null);

async function loadModels(providerId: string, force = false): Promise<void> {
    if (
        readOnly.value ||
        !providerId ||
        (!force && liveModels.value[providerId])
    ) {
        return;
    }

    modelsError.value = null;

    try {
        const url = endpoints.admin.ai.models(providerId).url;
        const response = await modelsHttp.get(
            force ? withQuery(url, { refresh: 1 }) : url,
        );
        const normalized = normalizeModels(response);
        liveModels.value = {
            ...liveModels.value,
            [providerId]: normalized.models,
        };
        modelsError.value = normalized.error;
    } catch (error) {
        modelsError.value =
            describeHttpError(error).message ?? t('ui.admin_ai.models_error');
    }
}

watch(
    () => form.provider,
    (id) => void loadModels(id),
    { immediate: true },
);

const modelOptions = computed(() => {
    const provider = activeProvider.value;
    const live = liveModels.value[form.provider] ?? [];
    const free = new Set(
        live.filter((model) => model.free).map((model) => model.id),
    );
    const names = new Set<string>([
        ...live.map((model) => model.id),
        ...(provider?.models ?? []),
        ...(provider?.default_model ? [provider.default_model] : []),
    ]);

    return [...names].map((name) => ({
        value: name,
        label: name,
        hint:
            name === provider?.default_model
                ? t('ui.admin_ai.default')
                : free.has(name)
                  ? t('ui.admin_ai.free')
                  : null,
    }));
});

function useProvider(provider: Provider): void {
    form.provider = provider.id;
    form.model = provider.default_model;
    saveSettings();
}

function saveSettings(): void {
    form.put(endpoints.admin.ai.settings().url, { preserveScroll: true });
}

const testHttp = useHttp<Record<string, never>, ConnectionTestResult>({});
const testResult = ref<ConnectionTestResult | null>(null);

async function runTest(): Promise<void> {
    testResult.value = null;

    try {
        testResult.value = (await testHttp.post(
            endpoints.admin.ai.test().url,
        )) ?? {
            ok: false,
            latency_ms: null,
            model: null,
            error: t('ui.admin_ai.test_unreachable'),
        };
    } catch (error) {
        testResult.value = {
            ok: false,
            latency_ms: null,
            model: null,
            error:
                describeHttpError(error).message ??
                t('ui.admin_ai.test_unreachable'),
        };
    }
}

const totals = computed(() =>
    props.usage.days.reduce(
        (acc, day) => ({
            requests: acc.requests + day.requests,
            tokens: acc.tokens + day.prompt_tokens + day.completion_tokens,
        }),
        { requests: 0, tokens: 0 },
    ),
);

const hasUsage = computed(
    () =>
        totals.value.requests > 0 ||
        totals.value.tokens > 0 ||
        props.usage.total_cost_usd > 0,
);

const modelColumns = computed<DataColumn<UsageByModel>[]>(() => [
    { id: 'model', header: t('ui.admin_ai.model'), sortable: true },
    {
        id: 'provider',
        header: t('ui.admin_ai.provider'),
        sortable: true,
        hideBelow: 'sm',
    },
    {
        id: 'requests',
        header: t('ui.admin_ai.requests'),
        sortable: true,
        align: 'right',
    },
    {
        id: 'cost_usd',
        header: t('ui.admin_ai.cost'),
        sortable: true,
        align: 'right',
    },
]);

const embeddingLabel = (id: string) => tOr(`ui.admin_ai.embeddings.${id}`, id);
</script>

<template>
    <Head :title="t('ui.admin_ai.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="Cpu"
            :eyebrow="t('ui.admin_ai.eyebrow')"
            :title="t('ui.admin_ai.title')"
            :description="t('ui.admin_ai.description')"
        >
            <template #actions>
                <Button
                    variant="outline"
                    :disabled="readOnly || testHttp.processing"
                    data-test="ai-test-connection"
                    @click="runTest"
                >
                    <Spinner v-if="testHttp.processing" />
                    <PlugZap v-else class="size-4" aria-hidden="true" />
                    {{ t('ui.admin_ai.test') }}
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="serviceError"
            class="flex items-start gap-3 rounded-2xl border border-destructive/30 bg-destructive-soft px-4 py-3 text-sm text-destructive-soft-foreground"
            role="alert"
            data-test="ai-service-error"
        >
            <CircleX class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-semibold">{{ t('ui.admin_ai.service_down') }}</p>
                <p>{{ serviceError }}</p>
            </div>
        </div>

        <div
            v-if="testResult"
            :class="[
                'flex flex-wrap items-center gap-x-4 gap-y-1 rounded-2xl border px-4 py-3 text-sm',
                testResult.ok
                    ? 'border-success/30 bg-success-soft text-success-soft-foreground'
                    : 'border-destructive/30 bg-destructive-soft text-destructive-soft-foreground',
            ]"
            role="status"
            data-test="ai-test-result"
            :data-ok="testResult.ok ? 'true' : 'false'"
        >
            <span class="inline-flex items-center gap-2 font-semibold">
                <component
                    :is="testResult.ok ? CircleCheck : CircleX"
                    class="size-4"
                    aria-hidden="true"
                />
                {{
                    testResult.ok
                        ? t('ui.admin_ai.test_ok')
                        : t('ui.admin_ai.test_failed')
                }}
            </span>
            <span v-if="testResult.model" class="font-mono text-xs">
                <template v-if="testResult.provider"
                    >{{ testResult.provider }} / </template
                >{{ testResult.model }}
            </span>
            <span v-if="testResult.latency_ms !== null">{{
                fmt.duration(testResult.latency_ms)
            }}</span>
            <span
                v-if="testResult.error"
                class="w-full break-words sm:w-auto"
                >{{ testResult.error }}</span
            >
        </div>

        <section
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"
            :aria-label="t('ui.admin_ai.summary')"
        >
            <KpiCard
                :label="t('ui.admin_ai.active_model')"
                :value="settings.model"
                :hint="activeProvider?.label ?? settings.provider"
                :icon="Sparkles"
                tone="ai"
            />
            <KpiCard
                :label="t('ui.admin_ai.cost_30d')"
                :value="usage.total_cost_usd"
                :format="
                    (n: number) => fmt.money(n, 'USD', { fractionDigits: 2 })
                "
                :icon="Coins"
                tone="warning"
            />
            <KpiCard
                :label="t('ui.admin_ai.requests_30d')"
                :value="totals.requests"
                :icon="Activity"
                tone="info"
            />
            <KpiCard
                :label="t('ui.admin_ai.tokens_30d')"
                :value="totals.tokens"
                :format="(n: number) => fmt.compact(n)"
                :icon="BarChart3"
            />
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
            <section :aria-label="t('ui.admin_ai.providers')" class="space-y-3">
                <h2 class="font-display text-lg font-semibold">
                    {{ t('ui.admin_ai.providers') }}
                </h2>
                <div
                    v-if="providers.length"
                    class="grid grid-cols-1 gap-4 md:grid-cols-2"
                >
                    <ProviderCard
                        v-for="provider in providers"
                        :key="provider.id"
                        :provider="provider"
                        :active="provider.id === settings.provider"
                        :disabled="readOnly || form.processing"
                        @use="useProvider"
                    />
                </div>
                <div v-else class="rounded-2xl border border-dashed bg-card/60">
                    <EmptyState
                        compact
                        tone="muted"
                        :icon="Cpu"
                        :title="t('ui.admin_ai.providers_empty_title')"
                        :description="t('ui.admin_ai.providers_empty_body')"
                    />
                </div>
            </section>

            <SectionCard
                :icon="SlidersHorizontal"
                :title="t('ui.admin_ai.settings_title')"
                :description="t('ui.admin_ai.settings_description')"
                class="self-start xl:sticky xl:top-20"
            >
                <form class="space-y-5" @submit.prevent="saveSettings">
                    <fieldset :disabled="readOnly" class="min-w-0 space-y-5">
                        <div class="grid gap-2">
                            <Label for="ai-provider">{{
                                t('ui.admin_ai.provider')
                            }}</Label>
                            <Select v-model="form.provider">
                                <SelectTrigger id="ai-provider" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="provider in providers"
                                        :key="provider.id"
                                        :value="provider.id"
                                        :disabled="
                                            provider.needs_key &&
                                            !provider.configured
                                        "
                                    >
                                        {{ provider.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.provider" />
                        </div>

                        <div class="grid gap-2">
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <Label for="ai-model">{{
                                    t('ui.admin_ai.model')
                                }}</Label>
                                <button
                                    type="button"
                                    class="-my-1 inline-flex min-h-6 cursor-pointer items-center gap-1 rounded-md px-1 text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="modelsHttp.processing"
                                    @click="loadModels(form.provider, true)"
                                >
                                    <RefreshCw
                                        :class="[
                                            'size-3',
                                            modelsHttp.processing
                                                ? 'motion-safe:animate-spin'
                                                : '',
                                        ]"
                                        aria-hidden="true"
                                    />
                                    {{ t('ui.admin_ai.refresh_models') }}
                                </button>
                            </div>
                            <SearchableSelect
                                id="ai-model"
                                v-model="form.model"
                                :options="modelOptions"
                                allow-custom
                                :loading="modelsHttp.processing"
                                :invalid="Boolean(form.errors.model)"
                                :search-placeholder="
                                    t('ui.admin_ai.model_search')
                                "
                            />
                            <p class="text-xs text-muted-foreground">
                                {{ modelsError ?? t('ui.admin_ai.model_hint') }}
                            </p>
                            <InputError :message="form.errors.model" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="ai-fallback">{{
                                t('ui.admin_ai.fallback_model')
                            }}</Label>
                            <SearchableSelect
                                id="ai-fallback"
                                v-model="form.fallback_model"
                                :options="modelOptions"
                                allow-custom
                                clearable
                                :placeholder="t('ui.admin_ai.fallback_none')"
                                :invalid="Boolean(form.errors.fallback_model)"
                            />
                            <p class="text-xs text-muted-foreground">
                                {{ t('ui.admin_ai.fallback_hint') }}
                            </p>
                            <InputError :message="form.errors.fallback_model" />
                        </div>

                        <div class="grid gap-2">
                            <div class="flex items-center justify-between">
                                <Label for="ai-temperature">{{
                                    t('ui.admin_ai.temperature')
                                }}</Label>
                                <span class="font-mono text-sm tabular-nums">{{
                                    Number(form.temperature).toFixed(1)
                                }}</span>
                            </div>
                            <input
                                id="ai-temperature"
                                v-model.number="form.temperature"
                                type="range"
                                min="0"
                                max="2"
                                step="0.1"
                                class="h-2 w-full cursor-pointer accent-[var(--primary)]"
                                :aria-valuetext="
                                    Number(form.temperature).toFixed(1)
                                "
                            />
                            <div
                                class="flex justify-between text-xs text-muted-foreground"
                            >
                                <span>{{ t('ui.admin_ai.precise') }}</span>
                                <span>{{ t('ui.admin_ai.creative') }}</span>
                            </div>
                            <InputError :message="form.errors.temperature" />
                        </div>

                        <fieldset class="grid gap-3 rounded-xl border p-3">
                            <legend
                                class="flex items-center gap-1.5 px-1 text-sm font-medium"
                            >
                                <Database
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                {{ t('ui.admin_ai.embeddings_title') }}
                            </legend>
                            <div class="grid gap-2">
                                <Label for="ai-emb-provider" class="text-xs">{{
                                    t('ui.admin_ai.provider')
                                }}</Label>
                                <Select v-model="form.embeddings.provider">
                                    <SelectTrigger
                                        id="ai-emb-provider"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="id in embeddingsProviders"
                                            :key="id"
                                            :value="id as EmbeddingsProvider"
                                        >
                                            {{ embeddingLabel(id) }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-2">
                                <Label for="ai-emb-model" class="text-xs">{{
                                    t('ui.admin_ai.model')
                                }}</Label>
                                <Input
                                    id="ai-emb-model"
                                    v-model="form.embeddings.model"
                                    class="font-mono text-sm"
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ t('ui.admin_ai.embeddings_hint') }}
                            </p>
                            <InputError
                                :message="
                                    form.errors['embeddings.provider'] ??
                                    form.errors['embeddings.model']
                                "
                            />
                        </fieldset>

                        <div class="flex items-center gap-3">
                            <Button
                                type="submit"
                                :disabled="
                                    readOnly || form.processing || !form.isDirty
                                "
                                data-test="ai-save-settings"
                            >
                                <Spinner v-if="form.processing" />
                                <Save
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ t('ui.common.save_changes') }}
                            </Button>
                            <p
                                v-show="form.recentlySuccessful"
                                class="text-sm text-muted-foreground"
                                role="status"
                            >
                                {{ t('ui.common.saved') }}
                            </p>
                        </div>
                    </fieldset>
                </form>
            </SectionCard>
        </div>

        <SectionCard
            :icon="BarChart3"
            :title="t('ui.admin_ai.usage_title')"
            :description="t('ui.admin_ai.usage_description')"
        >
            <UsageChart v-if="hasUsage" :days="usage.days" />
            <EmptyState
                v-else
                compact
                tone="ai"
                :icon="BarChart3"
                :title="t('ui.admin_ai.usage_empty')"
            />
        </SectionCard>

        <section :aria-label="t('ui.admin_ai.by_model')" class="space-y-3">
            <h2 class="font-display text-lg font-semibold">
                {{ t('ui.admin_ai.by_model') }}
            </h2>
            <DataTable
                :columns="modelColumns"
                :rows="usage.by_model"
                :row-key="(row) => `${row.provider}:${row.model}`"
                :caption="t('ui.admin_ai.by_model')"
            >
                <template #cell-model="{ row }">
                    <span class="font-mono text-sm">{{ row.model }}</span>
                </template>
                <template #cell-provider="{ row }">
                    <span class="text-muted-foreground">{{
                        providers.find((p) => p.id === row.provider)?.label ??
                        row.provider
                    }}</span>
                </template>
                <template #cell-requests="{ row }">
                    <span class="tabular-nums">{{
                        fmt.number(row.requests)
                    }}</span>
                </template>
                <template #cell-cost_usd="{ row }">
                    <span class="font-medium tabular-nums">{{
                        fmt.money(row.cost_usd, 'USD', { fractionDigits: 4 })
                    }}</span>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        tone="muted"
                        :icon="Cpu"
                        :title="t('ui.admin_ai.usage_empty')"
                    />
                </template>
            </DataTable>
        </section>
    </div>
</template>
