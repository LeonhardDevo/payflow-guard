<script setup lang="ts">
import { ArrowRight, GitCompareArrows } from '@lucide/vue';
import { computed } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { diffRows, formatAuditValue } from '@/lib/audit';
import { humanize, t, tc, tOr } from '@/lib/i18n';
import type { AuditLog } from '@/types';

const props = defineProps<{ changes: AuditLog['changes'] }>();

const rows = computed(() => diffRows(props.changes));

const labels = computed(() => ({
    empty: t('ui.audit.empty_value'),
    yes: t('ui.common.yes'),
    no: t('ui.common.no'),
}));
</script>

<template>
    <Popover v-if="rows.length">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inline-flex h-7 items-center gap-1.5 rounded-full border bg-background px-2.5 text-xs font-medium text-muted-foreground transition-colors hover:border-info/40 hover:text-foreground data-[state=open]:border-info/50 data-[state=open]:text-foreground pointer-coarse:h-9"
            >
                <GitCompareArrows class="size-3.5" aria-hidden="true" />
                {{ tc('ui.audit.changes', rows.length) }}
            </button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-[min(28rem,calc(100vw-2rem))] p-0">
            <p class="border-b px-4 py-2.5 text-sm font-semibold">
                {{ t('ui.audit.diff_title') }}
            </p>
            <ul class="max-h-80 divide-y overflow-y-auto">
                <li
                    v-for="row in rows"
                    :key="row.field"
                    class="space-y-1.5 px-4 py-3"
                >
                    <p
                        class="font-mono text-xs font-semibold text-muted-foreground"
                    >
                        {{
                            tOr(
                                `ui.audit.fields.${row.field}`,
                                humanize(row.field),
                            )
                        }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <del
                            class="rounded-md bg-destructive-soft px-2 py-0.5 break-all text-destructive-soft-foreground decoration-destructive/40"
                            :aria-label="t('ui.audit.before')"
                            >{{ formatAuditValue(row.before, labels) }}</del
                        >
                        <ArrowRight
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <ins
                            class="rounded-md bg-success-soft px-2 py-0.5 break-all text-success-soft-foreground no-underline"
                            :aria-label="t('ui.audit.after')"
                            >{{ formatAuditValue(row.after, labels) }}</ins
                        >
                    </div>
                </li>
            </ul>
        </PopoverContent>
    </Popover>
    <span v-else class="text-xs text-muted-foreground">—</span>
</template>
