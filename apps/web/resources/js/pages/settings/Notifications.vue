<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    BellDot,
    Lock,
    Mail,
    MessageCircle,
    Moon,
    RotateCcw,
    Save,
} from '@lucide/vue';
import SectionCard from '@/components/common/SectionCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { t } from '@/lib/i18n';
import { integrations, notifications, workspace } from '@/routes/settings';
import { update } from '@/routes/settings/notifications';
import type {
    NotificationEvent,
    NotificationPreferences,
} from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.settings.title', href: workspace() },
            { title: 'pf.settings.nav.notifications', href: notifications() },
        ],
    },
});

const props = defineProps<{
    preferences: NotificationPreferences;
    available_events: NotificationEvent[];
    forced_events: NotificationEvent[];
    telegram_linked: boolean;
    role_defaults: NotificationPreferences;
}>();

const form = useForm({
    telegram_events: [...props.preferences.telegram_events],
    email_events: [...props.preferences.email_events],
    digest_channel: props.preferences.digest_channel,
    quiet_hours_start: props.preferences.quiet_hours_start ?? '',
    quiet_hours_end: props.preferences.quiet_hours_end ?? '',
});

const forced = (event: NotificationEvent) =>
    props.forced_events.includes(event);

function toggle(
    channel: 'telegram_events' | 'email_events',
    event: NotificationEvent,
    value: boolean | 'indeterminate',
): void {
    const list = form[channel];
    form[channel] =
        value === true
            ? [...new Set([...list, event])]
            : list.filter((item) => item !== event);
}

function useDefaults(): void {
    form.telegram_events = [...props.role_defaults.telegram_events];
    form.email_events = [...props.role_defaults.email_events];
    form.digest_channel = props.role_defaults.digest_channel;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        quiet_hours_start: data.quiet_hours_start || null,
        quiet_hours_end: data.quiet_hours_end || null,
    })).put(update().url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('pf.settings.nav.notifications')" />

    <form
        class="flex flex-col gap-6"
        data-test="notifications-form"
        @submit.prevent="submit"
    >
        <SectionCard
            :icon="BellDot"
            :title="t('pf.settings.notifications.title')"
            :description="t('pf.settings.notifications.description')"
        >
            <template #actions>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="useDefaults"
                >
                    <RotateCcw class="size-4" aria-hidden="true" />{{
                        t('pf.settings.notifications.defaults')
                    }}
                </Button>
            </template>
            <p
                v-if="!telegram_linked"
                class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border bg-muted/40 p-3 text-sm text-muted-foreground"
            >
                <MessageCircle class="size-4" aria-hidden="true" />
                {{ t('pf.settings.notifications.telegram_not_linked') }}
                <Link
                    :href="integrations().url"
                    class="font-medium text-primary hover:underline"
                    >{{ t('pf.settings.notifications.link_telegram') }}</Link
                >
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        {{
                            t('pf.settings.notifications.title')
                        }}
                    </caption>
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-2 font-medium">
                                {{ t('pf.settings.notifications.event') }}
                            </th>
                            <th class="w-24 py-2 text-center font-medium">
                                <MessageCircle
                                    class="mx-auto size-4"
                                    :aria-label="'Telegram'"
                                />
                            </th>
                            <th class="w-24 py-2 text-center font-medium">
                                <Mail
                                    class="mx-auto size-4"
                                    :aria-label="'E-mail'"
                                />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="event in available_events"
                            :key="event"
                            class="border-t"
                            :data-event="event"
                        >
                            <td class="py-2.5">
                                {{
                                    t(
                                        `pf.settings.notifications.events.${event}`,
                                    )
                                }}
                                <span
                                    v-if="forced(event)"
                                    class="ml-1 inline-flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <Lock class="size-3" aria-hidden="true" />{{
                                        t('pf.settings.notifications.forced')
                                    }}
                                </span>
                            </td>
                            <td class="py-2.5 text-center">
                                <Checkbox
                                    :model-value="
                                        form.telegram_events.includes(event) ||
                                        forced(event)
                                    "
                                    :disabled="
                                        !telegram_linked || forced(event)
                                    "
                                    :aria-label="`Telegram: ${t(`pf.settings.notifications.events.${event}`)}`"
                                    @update:model-value="
                                        (v) =>
                                            toggle('telegram_events', event, v)
                                    "
                                />
                            </td>
                            <td class="py-2.5 text-center">
                                <Checkbox
                                    :model-value="
                                        form.email_events.includes(event) ||
                                        forced(event)
                                    "
                                    :disabled="forced(event)"
                                    :aria-label="`E-mail: ${t(`pf.settings.notifications.events.${event}`)}`"
                                    @update:model-value="
                                        (v) => toggle('email_events', event, v)
                                    "
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <InputError
                :message="
                    form.errors.telegram_events ?? form.errors.email_events
                "
            />
        </SectionCard>

        <SectionCard
            :icon="Moon"
            :title="t('pf.settings.notifications.delivery')"
        >
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="nt-digest">{{
                        t('pf.settings.notifications.digest')
                    }}</Label>
                    <Select v-model="form.digest_channel">
                        <SelectTrigger id="nt-digest" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                value="telegram"
                                :disabled="!telegram_linked"
                                >Telegram</SelectItem
                            >
                            <SelectItem value="email">E-mail</SelectItem>
                            <SelectItem value="none">{{
                                t('pf.settings.notifications.no_digest')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="nt-quiet-start">{{
                        t('pf.settings.notifications.quiet_start')
                    }}</Label>
                    <Input
                        id="nt-quiet-start"
                        v-model="form.quiet_hours_start"
                        type="time"
                    />
                    <InputError :message="form.errors.quiet_hours_start" />
                </div>
                <div class="grid gap-2">
                    <Label for="nt-quiet-end">{{
                        t('pf.settings.notifications.quiet_end')
                    }}</Label>
                    <Input
                        id="nt-quiet-end"
                        v-model="form.quiet_hours_end"
                        type="time"
                    />
                    <InputError :message="form.errors.quiet_hours_end" />
                </div>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                {{ t('pf.settings.notifications.quiet_hint') }}
            </p>
        </SectionCard>

        <div class="flex justify-end">
            <Button
                type="submit"
                :disabled="form.processing || !form.isDirty"
                data-test="notifications-save"
            >
                <Spinner v-if="form.processing" class="size-4" />
                <Save v-else class="size-4" aria-hidden="true" />{{
                    t('pf.actions.save_changes')
                }}
            </Button>
        </div>
    </form>
</template>
