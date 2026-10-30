<script setup lang="ts">
import { KeyRound, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { Passkey } from '@/types/auth';

const props = defineProps<{ passkey: Passkey }>();

const emit = defineEmits<{ remove: [id: number, onError: () => void] }>();

const open = ref(false);
const isDeleting = ref(false);

const handleDelete = () => {
    isDeleting.value = true;
    emit('remove', props.passkey.id, () => {
        isDeleting.value = false;
    });
};
</script>

<template>
    <div
        class="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
    >
        <div class="flex min-w-0 items-center gap-4">
            <span
                class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground"
                aria-hidden="true"
            >
                <KeyRound class="size-5" />
            </span>
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate font-medium tracking-tight">
                        {{ passkey.name }}
                    </p>
                    <span
                        v-if="passkey.authenticator"
                        class="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase ring-1 ring-border ring-inset"
                    >
                        {{ passkey.authenticator }}
                    </span>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{
                        t('ui.settings.passkeys.added', {
                            when: passkey.created_at_diff,
                        })
                    }}
                    <template v-if="passkey.last_used_at_diff">
                        <span
                            class="mx-1 text-muted-foreground/50"
                            aria-hidden="true"
                            >/</span
                        >
                        {{
                            t('ui.settings.passkeys.last_used', {
                                when: passkey.last_used_at_diff,
                            })
                        }}
                    </template>
                </p>
            </div>
        </div>

        <Button
            variant="ghost"
            size="icon"
            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
            :aria-label="
                t('ui.settings.passkeys.remove_named', { name: passkey.name })
            "
            @click="open = true"
        >
            <Trash2 class="size-4" aria-hidden="true" />
        </Button>

        <ConfirmDialog
            v-model:open="open"
            destructive
            :processing="isDeleting"
            :title="t('ui.settings.passkeys.remove_title')"
            :description="
                t('ui.settings.passkeys.remove_body', { name: passkey.name })
            "
            :confirm-label="t('ui.settings.passkeys.remove')"
            @confirm="handleDelete"
        />
    </div>
</template>
