<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    Clock,
    Coins,
    ExternalLink,
    Globe,
    Handshake,
    Info,
    Rocket,
} from '@lucide/vue';
import { useNow } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import StepIndicator from '@/components/common/StepIndicator.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/composables/useLocale';
import { endpoints } from '@/lib/endpoints';
import { detectTimeZone } from '@/lib/format';
import { INTL_LOCALE, t } from '@/lib/i18n';
import { currencyOptions, timezoneOptions } from '@/lib/regional';
import { cn } from '@/lib/utils';
import type { CountryOption, Locale } from '@/types';
import type { LegalVersionRef } from '@/types/legal';

const props = withDefaults(
    defineProps<{
        timezones?: string[];
        currencies?: string[];
        dpa?: LegalVersionRef | null;
        countries?: CountryOption[];
        defaultCountry?: string | null;
    }>(),
    {
        timezones: () => [],
        currencies: () => ['USD', 'EUR', 'RUB'],
        dpa: null,
        countries: () => [],
        defaultCountry: null,
    },
);

const page = usePage();
const { locale, locales } = useLocale();

const browserZone = detectTimeZone();
const initialCountry =
    props.countries.find((country) => country.code === props.defaultCountry) ??
    props.countries[0] ??
    null;
const fallbackCurrency =
    locale.value === 'ru' && props.currencies.includes('RUB')
        ? 'RUB'
        : props.currencies.includes('USD')
          ? 'USD'
          : (props.currencies[0] ?? 'USD');
const defaultCurrency =
    initialCountry && props.currencies.includes(initialCountry.currency)
        ? initialCountry.currency
        : fallbackCurrency;

const form = useForm({
    name: '',
    timezone: props.timezones.includes(browserZone)
        ? browserZone
        : (props.timezones[0] ?? 'UTC'),
    country: initialCountry?.code ?? '',
    currency: defaultCurrency,
    locale: locale.value as Locale,
    dpa: false,
});

const countryOptions = computed(() =>
    props.countries.map((country) => ({
        value: country.code,
        label: country.name,
        hint: country.available
            ? country.currency
            : `${country.currency} · ${t('ui.onboarding.country_payments_soon')}`,
    })),
);
const selectedCountry = computed(
    () =>
        props.countries.find((country) => country.code === form.country) ??
        null,
);

watch(
    () => form.country,
    (code, previous) => {
        const next = props.countries.find((country) => country.code === code);
        const before = props.countries.find(
            (country) => country.code === previous,
        );

        if (
            next &&
            props.currencies.includes(next.currency) &&
            (!before || form.currency === before.currency)
        ) {
            form.currency = next.currency;
        }
    },
);

const step = ref(0);
const steps = computed(() => [
    {
        key: 'name',
        title: t('ui.onboarding.steps.name'),
        description: t('ui.onboarding.steps.name_hint'),
    },
    {
        key: 'region',
        title: t('ui.onboarding.steps.region'),
        description: t('ui.onboarding.steps.region_hint'),
    },
    ...(props.dpa
        ? [
              {
                  key: 'dpa',
                  title: t('ui.onboarding.steps.dpa'),
                  description: t('ui.onboarding.steps.dpa_hint'),
              },
          ]
        : []),
    {
        key: 'review',
        title: t('ui.onboarding.steps.review'),
        description: t('ui.onboarding.steps.review_hint'),
    },
]);

const tzOptions = computed(() =>
    timezoneOptions(props.timezones.length ? props.timezones : [browserZone]),
);
const curOptions = computed(() =>
    currencyOptions(props.currencies, locale.value),
);

const nameError = ref<string | null>(null);
const dpaError = ref<string | null>(null);
const stepKey = computed(() => steps.value[step.value]?.key ?? 'name');
const dpaStep = computed(() =>
    steps.value.findIndex((item) => item.key === 'dpa'),
);

function dpaAccepted(): boolean {
    if (!props.dpa || form.dpa) {
        dpaError.value = null;

        return true;
    }

    dpaError.value = t('ui.onboarding.dpa_required');

    return false;
}

function next(): void {
    if (step.value === 0) {
        if (form.name.trim().length < 2) {
            nameError.value = t('ui.onboarding.name_required');

            return;
        }

        nameError.value = null;
    }

    if (stepKey.value === 'dpa' && !dpaAccepted()) {
        return;
    }

    step.value = Math.min(step.value + 1, steps.value.length - 1);
}

function back(): void {
    step.value = Math.max(0, step.value - 1);
}

function validName(): boolean {
    if (form.name.trim().length < 2) {
        nameError.value = t('ui.onboarding.name_required');
        step.value = 0;

        return false;
    }

    nameError.value = null;

    return true;
}

function submit(): void {
    if (!validName()) {
        return;
    }

    if (!dpaAccepted()) {
        step.value = dpaStep.value;

        return;
    }

    form.post(endpoints.onboarding.store().url, {
        onError: (errors) => {
            if (errors.name) {
                step.value = 0;
            } else if (
                errors.timezone ||
                errors.currency ||
                errors.locale ||
                errors.country
            ) {
                step.value = 1;
            } else if (errors.dpa && dpaStep.value >= 0) {
                dpaError.value = errors.dpa;
                step.value = dpaStep.value;
            }
        },
    });
}

const now = useNow({ interval: 30_000 });

const preview = computed(() => {
    const intl = INTL_LOCALE[form.locale] ?? 'en-US';

    try {
        return {
            money: new Intl.NumberFormat(intl, {
                style: 'currency',
                currency: form.currency,
            }).format(4820),
            time: new Intl.DateTimeFormat(intl, {
                timeStyle: 'short',
                timeZone: form.timezone,
            }).format(now.value),
            date: new Intl.DateTimeFormat(intl, {
                dateStyle: 'full',
                timeZone: form.timezone,
            }).format(now.value),
        };
    } catch {
        return { money: `4820 ${form.currency}`, time: '—', date: '—' };
    }
});

const initial = computed(() =>
    (form.name.trim().charAt(0) || '?').toUpperCase(),
);
const hasWorkspaces = computed(() => (page.props.workspaces ?? []).length > 0);
</script>

<template>
    <Head :title="t('ui.onboarding.title')" />

    <div
        class="mx-auto grid w-full max-w-5xl flex-1 grid-cols-1 items-center gap-10 py-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]"
    >
        <div
            class="rounded-3xl border bg-card/90 p-6 shadow-lifted backdrop-blur sm:p-8"
        >
            <p
                class="text-xs font-semibold tracking-wider text-primary uppercase"
            >
                {{ t('ui.onboarding.eyebrow') }}
            </p>
            <h1
                class="mt-2 font-display text-2xl font-bold tracking-tight sm:text-3xl"
            >
                {{ t('ui.onboarding.title') }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ t('ui.onboarding.subtitle') }}
            </p>

            <div class="mt-8 grid grid-cols-1 gap-8 md:grid-cols-[13rem_1fr]">
                <StepIndicator
                    :steps="steps"
                    :current="step"
                    @go="(index) => (step = index)"
                />

                <form class="min-w-0 space-y-6" @submit.prevent="submit">
                    <input
                        type="hidden"
                        name="timezone"
                        :value="form.timezone"
                    />
                    <input
                        type="hidden"
                        name="currency"
                        :value="form.currency"
                    />
                    <input type="hidden" name="locale" :value="form.locale" />
                    <div v-if="step === 0" class="space-y-2">
                        <Label for="workspace-name">{{
                            t('ui.onboarding.name_label')
                        }}</Label>
                        <Input
                            id="workspace-name"
                            v-model="form.name"
                            name="name"
                            v-focus
                            maxlength="80"
                            autocomplete="organization"
                            :placeholder="t('ui.onboarding.name_placeholder')"
                            :aria-invalid="
                                Boolean(nameError || form.errors.name)
                            "
                            class="h-11 text-base"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('ui.onboarding.name_help') }}
                        </p>
                        <InputError :message="nameError ?? form.errors.name" />
                    </div>

                    <div v-else-if="step === 1" class="space-y-5">
                        <div v-if="countries.length" class="grid gap-2">
                            <Label for="workspace-country">{{
                                t('ui.onboarding.country_label')
                            }}</Label>
                            <SearchableSelect
                                id="workspace-country"
                                v-model="form.country"
                                :options="countryOptions"
                                :invalid="Boolean(form.errors.country)"
                                data-test="workspace-country"
                            />
                            <p
                                v-if="selectedCountry"
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Globe
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {{
                                    t('ui.onboarding.country_help', {
                                        currency: selectedCountry.currency,
                                        gateway: t(
                                            `billing.gateways_inline.${selectedCountry.gateway}`,
                                        ),
                                    })
                                }}
                            </p>
                            <p
                                v-if="
                                    selectedCountry &&
                                    !selectedCountry.available
                                "
                                class="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400"
                                role="status"
                                data-test="country-payments-unavailable"
                            >
                                <Info
                                    class="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ t('ui.onboarding.country_unavailable') }}
                            </p>
                            <InputError :message="form.errors.country" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="workspace-timezone">{{
                                t('ui.fields.timezone')
                            }}</Label>
                            <SearchableSelect
                                id="workspace-timezone"
                                v-model="form.timezone"
                                :options="tzOptions"
                                :search-placeholder="
                                    t('ui.fields.timezone_search')
                                "
                                :invalid="Boolean(form.errors.timezone)"
                            />
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t('ui.onboarding.timezone_detected', {
                                        zone: browserZone,
                                    })
                                }}
                            </p>
                            <InputError :message="form.errors.timezone" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="workspace-currency">{{
                                t('ui.fields.currency')
                            }}</Label>
                            <SearchableSelect
                                id="workspace-currency"
                                v-model="form.currency"
                                :options="curOptions"
                                :invalid="Boolean(form.errors.currency)"
                            />
                            <InputError :message="form.errors.currency" />
                        </div>
                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-sm font-medium">
                                {{ t('ui.settings.workspace.locale') }}
                            </legend>
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    v-for="option in locales"
                                    :key="option.code"
                                    :class="
                                        cn(
                                            'flex cursor-pointer items-center gap-2 rounded-lg border-2 px-3 py-2.5 text-sm font-medium transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                                            form.locale === option.code
                                                ? 'border-primary bg-primary-soft/40'
                                                : 'border-border hover:border-primary/40',
                                        )
                                    "
                                >
                                    <input
                                        v-model="form.locale"
                                        type="radio"
                                        name="locale"
                                        :value="option.code"
                                        class="sr-only"
                                    />
                                    <span
                                        class="font-mono text-xs text-muted-foreground uppercase"
                                        >{{ option.code }}</span
                                    >
                                    {{ option.label }}
                                </label>
                            </div>
                            <InputError :message="form.errors.locale" />
                        </fieldset>
                    </div>

                    <div
                        v-else-if="stepKey === 'dpa' && dpa"
                        class="space-y-4"
                        data-test="onboarding-dpa"
                    >
                        <div
                            class="flex items-start gap-3 rounded-xl border p-4"
                        >
                            <Handshake
                                class="mt-0.5 size-5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <div class="min-w-0 space-y-1">
                                <p class="text-sm font-semibold">
                                    {{ t('ui.legal.docs.dpa') }}
                                    <span
                                        class="ml-1 font-mono text-xs font-normal text-muted-foreground"
                                        >{{
                                            t('ui.legal.accept.version', {
                                                version: dpa.version,
                                            })
                                        }}</span
                                    >
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ t('ui.onboarding.dpa_body') }}
                                </p>
                                <a
                                    :href="dpa.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-1 text-sm font-medium text-primary underline-offset-2 hover:underline"
                                >
                                    {{ t('ui.onboarding.dpa_read') }}
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
                        <Label
                            for="accept-dpa"
                            class="flex items-start gap-3 rounded-xl border bg-muted/40 p-3 leading-snug font-normal"
                        >
                            <Checkbox
                                id="accept-dpa"
                                v-model="form.dpa"
                                class="mt-0.5"
                                :aria-invalid="Boolean(dpaError)"
                                data-test="onboarding-accept-dpa"
                                @update:model-value="dpaError = null"
                            />
                            <span>{{ t('ui.onboarding.dpa_accept') }}</span>
                        </Label>
                        <InputError :message="dpaError" />
                    </div>

                    <div v-else class="space-y-3">
                        <dl class="divide-y rounded-xl border">
                            <div
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <dt class="text-sm text-muted-foreground">
                                    {{ t('ui.onboarding.name_label') }}
                                </dt>
                                <dd class="truncate text-sm font-semibold">
                                    {{ form.name }}
                                </dd>
                            </div>
                            <div
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <dt class="text-sm text-muted-foreground">
                                    {{ t('ui.fields.timezone') }}
                                </dt>
                                <dd class="truncate text-sm font-semibold">
                                    {{ form.timezone }}
                                </dd>
                            </div>
                            <div
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <dt class="text-sm text-muted-foreground">
                                    {{ t('ui.fields.currency') }}
                                </dt>
                                <dd class="text-sm font-semibold">
                                    {{ form.currency }}
                                </dd>
                            </div>
                            <div
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <dt class="text-sm text-muted-foreground">
                                    {{ t('ui.settings.workspace.locale') }}
                                </dt>
                                <dd class="text-sm font-semibold">
                                    {{
                                        locales.find(
                                            (l) => l.code === form.locale,
                                        )?.label
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p class="text-xs text-muted-foreground">
                            {{ t('ui.onboarding.review_note') }}
                        </p>
                    </div>

                    <div class="flex items-center justify-between gap-3 pt-2">
                        <Button
                            v-if="step > 0"
                            type="button"
                            variant="ghost"
                            @click="back"
                        >
                            <ArrowLeft class="size-4" aria-hidden="true" />
                            {{ t('ui.common.back') }}
                        </Button>
                        <span v-else />
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                v-if="step < steps.length - 1"
                                type="button"
                                variant="outline"
                                data-test="onboarding-next"
                                @click="next"
                            >
                                {{
                                    step === 0
                                        ? t('ui.onboarding.customize')
                                        : t('ui.common.continue')
                                }}
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Button>
                            <Button
                                v-if="
                                    step === steps.length - 1 ||
                                    (step === 0 && dpaStep < 0)
                                "
                                type="submit"
                                :disabled="form.processing"
                                data-test="onboarding-submit"
                            >
                                <Spinner v-if="form.processing" />
                                <Rocket
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ t('ui.onboarding.create') }}
                            </Button>
                        </div>
                    </div>
                    <p v-if="step === 0" class="text-xs text-muted-foreground">
                        {{
                            t('ui.onboarding.defaults_hint', {
                                zone: form.timezone,
                                currency: form.currency,
                            })
                        }}
                    </p>
                </form>
            </div>
        </div>

        <aside class="hidden lg:block" :aria-label="t('ui.onboarding.preview')">
            <div class="relative mx-auto max-w-sm">
                <div
                    class="bg-brand-gradient absolute -inset-6 rounded-[2rem] opacity-20 blur-2xl"
                    aria-hidden="true"
                />
                <div
                    class="relative space-y-4 rounded-3xl border bg-card p-6 shadow-lifted"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="bg-brand-gradient inline-flex size-12 items-center justify-center rounded-2xl font-display text-xl font-bold text-white shadow-soft"
                        >
                            {{ initial }}
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-display text-lg font-bold">
                                {{
                                    form.name ||
                                    t('ui.onboarding.name_placeholder')
                                }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ t('ui.onboarding.preview_caption') }}
                            </p>
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-3">
                        <div class="rounded-2xl bg-muted/60 p-3">
                            <dt
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Coins class="size-3.5" aria-hidden="true" />{{
                                    t('ui.fields.currency')
                                }}
                            </dt>
                            <dd
                                class="mt-1 font-display text-lg font-bold tabular-nums"
                            >
                                {{ preview.money }}
                            </dd>
                        </div>
                        <div class="rounded-2xl bg-muted/60 p-3">
                            <dt
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Clock class="size-3.5" aria-hidden="true" />{{
                                    t('ui.onboarding.local_time')
                                }}
                            </dt>
                            <dd
                                class="mt-1 font-display text-lg font-bold tabular-nums"
                            >
                                {{ preview.time }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Building2 class="size-4" aria-hidden="true" />
                        {{ preview.date }}
                    </p>
                </div>
                <p
                    v-if="!hasWorkspaces"
                    class="mt-6 text-center text-sm text-muted-foreground"
                >
                    {{ t('ui.onboarding.first_workspace') }}
                </p>
            </div>
        </aside>
    </div>
</template>
