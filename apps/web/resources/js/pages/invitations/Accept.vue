<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CircleAlert,
    CircleCheck,
    MailCheck,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';
import { refName } from '@/lib/utils';
import type { InvitationPreview } from '@/types';

const props = withDefaults(
    defineProps<{
        invitation: InvitationPreview;
        status?: 'pending' | 'expired' | 'accepted' | null;
        userExists?: boolean | null;
        emailMatches?: boolean | null;
    }>(),
    { status: null, userExists: null, emailMatches: null },
);

const page = usePage();
const user = computed(() => page.props.auth.user);
const accepting = ref(false);

const workspaceName = computed(() => refName(props.invitation.workspace));

const state = computed<'pending' | 'expired' | 'accepted'>(() => {
    if (props.status) {
        return props.status;
    }

    return new Date(props.invitation.expires_at).getTime() < Date.now()
        ? 'expired'
        : 'pending';
});

const emailMismatch = computed(() => {
    if (!user.value) {
        return false;
    }

    if (typeof props.emailMatches === 'boolean') {
        return !props.emailMatches;
    }

    return (
        user.value.email.toLowerCase() !== props.invitation.email.toLowerCase()
    );
});

const acceptLabel = computed(() => {
    if (user.value) {
        return t('ui.invitations.accept');
    }

    return props.userExists === false
        ? t('ui.invitations.register_to_accept')
        : t('ui.invitations.login_to_accept');
});

function accept(): void {
    router.post(
        new URL(page.url, window.location.origin).pathname,
        {},
        {
            onStart: () => {
                accepting.value = true;
            },
            onFinish: () => {
                accepting.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="t('ui.invitations.title', { workspace: workspaceName })" />

    <div
        class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-10"
    >
        <div
            class="rounded-3xl border bg-card p-6 text-center shadow-lifted sm:p-8"
        >
            <span
                class="bg-brand-gradient mx-auto inline-flex size-16 items-center justify-center rounded-2xl font-display text-2xl font-bold text-white shadow-soft"
                aria-hidden="true"
            >
                {{ workspaceName.charAt(0).toUpperCase() }}
            </span>
            <p class="mt-5 text-sm text-muted-foreground">
                {{
                    t('ui.invitations.invited_by', {
                        inviter: invitation.inviter,
                    })
                }}
            </p>
            <h1
                class="mt-1 font-display text-2xl font-bold tracking-tight text-balance"
                data-test="invitation-workspace"
            >
                {{ workspaceName }}
            </h1>

            <dl class="mt-6 divide-y rounded-2xl border text-left text-sm">
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <dt
                        class="flex shrink-0 items-center gap-2 text-muted-foreground"
                    >
                        <MailCheck class="size-4" aria-hidden="true" />{{
                            t('ui.fields.email')
                        }}
                    </dt>
                    <dd class="min-w-0 text-right font-medium break-all">
                        {{ invitation.email }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <dt class="flex items-center gap-2 text-muted-foreground">
                        <ShieldCheck class="size-4" aria-hidden="true" />{{
                            t('ui.members.role')
                        }}
                    </dt>
                    <dd class="font-medium">
                        {{ tOr(`roles.${invitation.role}`, invitation.role) }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <dt class="flex items-center gap-2 text-muted-foreground">
                        <CalendarClock class="size-4" aria-hidden="true" />{{
                            t('ui.members.expires')
                        }}
                    </dt>
                    <dd
                        :class="[
                            'font-medium',
                            state === 'expired' ? 'text-destructive' : '',
                        ]"
                    >
                        <span v-if="state === 'expired'">{{
                            t('ui.members.expired')
                        }}</span>
                        <RelativeTime v-else :value="invitation.expires_at" />
                    </dd>
                </div>
            </dl>

            <div
                v-if="state === 'accepted'"
                class="mt-6 space-y-4"
                role="status"
            >
                <p
                    class="flex items-center justify-center gap-2 font-semibold text-success-soft-foreground"
                >
                    <CircleCheck class="size-5" aria-hidden="true" />
                    {{ t('ui.invitations.accepted_title') }}
                </p>
                <Button as-child class="h-11 w-full">
                    <Link :href="endpoints.dashboard()">{{
                        t('ui.invitations.open_workspace')
                    }}</Link>
                </Button>
            </div>

            <div
                v-else-if="state === 'expired'"
                class="mt-6 space-y-2"
                role="alert"
            >
                <p class="font-semibold">
                    {{ t('ui.invitations.expired_title') }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        t('ui.invitations.expired_body', {
                            inviter: invitation.inviter,
                        })
                    }}
                </p>
            </div>

            <div v-else class="mt-6 space-y-3">
                <div
                    v-if="emailMismatch"
                    class="flex gap-2 rounded-xl border border-warning/40 bg-warning-soft p-3 text-left text-sm text-warning-soft-foreground"
                    role="note"
                >
                    <CircleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        {{
                            t('ui.invitations.email_mismatch', {
                                email: user?.email ?? '',
                            })
                        }}
                    </p>
                </div>
                <div
                    v-if="user"
                    class="flex items-center justify-center gap-2 text-sm text-muted-foreground"
                >
                    <UserAvatar :name="user.name" size="xs" />
                    {{ t('ui.invitations.signed_in_as', { name: user.name }) }}
                </div>
                <Button
                    class="h-11 w-full"
                    :disabled="accepting || emailMismatch"
                    data-test="invitation-accept"
                    @click="accept"
                >
                    <Spinner v-if="accepting" />
                    {{ acceptLabel }}
                </Button>
                <p v-if="!user" class="text-xs text-muted-foreground">
                    {{ t('ui.invitations.guest_hint') }}
                </p>
            </div>
        </div>
    </div>
</template>
