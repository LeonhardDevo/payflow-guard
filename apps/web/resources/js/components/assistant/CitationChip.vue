<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, FileText } from '@lucide/vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type { ChatCitation } from '@/types';

defineProps<{ citation: ChatCitation; index: number }>();

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inline-flex h-7 max-w-64 items-center gap-1.5 rounded-full border bg-background px-2 text-xs font-medium transition-colors hover:border-ai/40 hover:bg-ai-soft data-[state=open]:border-ai/50 data-[state=open]:bg-ai-soft pointer-coarse:h-9"
                :data-cite-chip="index"
                data-test="assistant-citation"
            >
                <span
                    class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-ai text-[0.625rem] font-bold text-ai-foreground"
                    >{{ index }}</span
                >
                <span class="truncate">{{ citation.title }}</span>
                <span
                    v-if="citation.page"
                    class="shrink-0 text-muted-foreground"
                >
                    ·
                    {{ t('ui.assistant.page_short', { page: citation.page }) }}
                </span>
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="w-[min(22rem,calc(100vw-2rem))] p-0"
        >
            <div class="flex items-start gap-3 border-b p-4">
                <span
                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-ai-soft text-ai-soft-foreground"
                    aria-hidden="true"
                >
                    <FileText class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm leading-snug font-semibold">
                        {{ citation.title }}
                    </p>
                    <p
                        v-if="citation.page"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('ui.assistant.page', { page: citation.page }) }}
                    </p>
                </div>
            </div>
            <blockquote
                class="max-h-48 overflow-y-auto border-l-2 border-ai/50 bg-muted/40 px-4 py-3 text-sm leading-relaxed text-foreground/90"
            >
                {{ citation.snippet }}
            </blockquote>
            <div class="flex justify-end p-2">
                <Link
                    :href="endpoints.knowledge.show(citation.document_id)"
                    class="inline-flex h-8 items-center gap-1 rounded-md px-3 text-sm font-medium text-primary hover:bg-primary-soft pointer-coarse:h-11"
                >
                    {{ t('ui.assistant.open_document') }}
                    <ArrowUpRight class="size-4" aria-hidden="true" />
                </Link>
            </div>
        </PopoverContent>
    </Popover>
</template>
