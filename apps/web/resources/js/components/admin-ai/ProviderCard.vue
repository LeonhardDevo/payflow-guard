<script setup lang="ts">
import { CircleCheck, CircleDashed, Info, KeyRound, Zap } from '@lucide/vue';
import { computed } from 'vue';
import ProviderKeyForm from '@/components/admin-ai/ProviderKeyForm.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Provider } from '@/types';

const props = withDefaults(
    defineProps<{ provider: Provider; active: boolean; disabled?: boolean }>(),
    {
        disabled: false,
    },
);
const emit = defineEmits<{ use: [provider: Provider] }>();

const keySource = computed(() => {
    if (!props.provider.needs_key) {
        return {
            tone: 'info' as const,
            label: t('ui.admin_ai.keyless'),
            source: 'none',
        };
    }

    if (props.provider.key_source === 'settings') {
        return {
            tone: 'ai' as const,
            label: t('ui.admin_ai.key_source_settings'),
            source: 'settings',
        };
    }

    if (props.provider.key_source === 'env') {
        return {
            tone: 'neutral' as const,
            label: t('ui.admin_ai.key_source_env'),
            source: 'env',
        };
    }

    return {
        tone: 'warning' as const,
        label: t('ui.admin_ai.key_missing'),
        source: 'missing',
    };
});
</script>

<template>
    <article
        :class="
            cn(
                'flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-soft transition-shadow',
                active
                    ? 'border-primary/50 ring-1 ring-primary/30'
                    : 'hover:shadow-lifted',
            )
        "
        :aria-label="provider.label"
        data-test="ai-provider-row"
        :data-provider="provider.id"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="truncate font-semibold">{{ provider.label }}</h3>
                <p class="truncate font-mono text-xs text-muted-foreground">
                    {{ provider.id }} · {{ provider.default_model }}
                </p>
            </div>
            <StatusBadge
                v-if="active"
                tone="success"
                :icon="Zap"
                :label="t('ui.admin_ai.active')"
            />
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <StatusBadge
                :tone="provider.configured ? 'success' : 'neutral'"
                :icon="provider.configured ? CircleCheck : CircleDashed"
                :label="
                    provider.configured
                        ? t('ui.admin_ai.configured')
                        : t('ui.admin_ai.not_configured')
                "
            />
            <StatusBadge
                :tone="keySource.tone"
                :icon="KeyRound"
                :label="keySource.label"
                :data-test="`ai-key-source-${provider.id}`"
                :data-source="keySource.source"
            />
            <span
                v-if="provider.key_hint"
                class="rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground"
                :data-test="`ai-key-hint-${provider.id}`"
            >
                {{ provider.key_hint }}
            </span>
        </div>

        <p
            v-if="provider.note"
            class="flex gap-1.5 text-xs text-muted-foreground"
        >
            <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            {{ provider.note }}
        </p>

        <ProviderKeyForm
            v-if="provider.needs_key"
            :provider="provider"
            :disabled="disabled"
        />

        <div v-if="!active" class="mt-auto pt-1">
            <Button
                size="sm"
                variant="secondary"
                :disabled="
                    disabled || (provider.needs_key && !provider.configured)
                "
                :data-test="`ai-use-${provider.id}`"
                @click="emit('use', provider)"
            >
                {{ t('ui.admin_ai.use_provider') }}
            </Button>
        </div>
    </article>
</template>
