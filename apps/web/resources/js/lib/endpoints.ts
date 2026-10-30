import type { UrlMethodPair } from '@inertiajs/core';
import {
    assistant as assistantIndex,
    dashboard,
    home,
    legal,
    pricing,
    security,
} from '@/routes';
import { ai as adminAiIndex } from '@/routes/admin';
import {
    keys as adminAiKeys,
    models as adminAiModels,
    settings as adminAiSettings,
    test as adminAiTest,
} from '@/routes/admin/ai';
import {
    actions as assistantActions,
    ask as assistantAsk,
    history as assistantHistory,
} from '@/routes/assistant';
import { index as analyticsIndex } from '@/routes/analytics';
import { index as auditIndex } from '@/routes/audit';
import { index as clientsIndex } from '@/routes/clients';
import { index as guardIndex } from '@/routes/guard';
import { fakeCheckout } from '@/routes/dev';
import { pay as fakeCheckoutPay } from '@/routes/dev/fake-checkout';
import {
    accept as invitationAccept,
    show as invitationShow,
} from '@/routes/invitations';
import {
    destroy as knowledgeDestroy,
    download as knowledgeDownload,
    index as knowledgeIndex,
    show as knowledgeShow,
    store as knowledgeStore,
    update as knowledgeUpdate,
} from '@/routes/knowledge';
import { index as importsIndex } from '@/routes/imports';
import { index as invoicesIndex } from '@/routes/invoices';
import { update as localeUpdate } from '@/routes/locale';
import { index as payoutsIndex } from '@/routes/payouts';
import { read as notificationsRead } from '@/routes/notifications';
import { index as remindersIndex } from '@/routes/reminders';
import { index as vendorsIndex } from '@/routes/vendors';
import { workspace as onboardingWorkspace } from '@/routes/onboarding';
import { store as onboardingStore } from '@/routes/onboarding/workspace';
import {
    billing as settingsBilling,
    integrations as settingsIntegrations,
    members as settingsMembers,
    workspace as settingsWorkspace,
} from '@/routes/settings';
import {
    cancel as billingCancel,
    changePlan as billingChangePlan,
    checkout as billingCheckout,
    portal as billingPortal,
    resume as billingResume,
} from '@/routes/settings/billing';
import {
    link as telegramLink,
    unlink as telegramUnlink,
} from '@/routes/settings/integrations/telegram';
import {
    destroy as memberDestroy,
    update as memberUpdate,
} from '@/routes/settings/members';
import {
    destroy as invitationDestroy,
    store as invitationStore,
} from '@/routes/settings/members/invitations';
import {
    destroy as workspaceDestroy,
    update as workspaceUpdate,
} from '@/routes/settings/workspace';
import { switchMethod as workspaceSwitch } from '@/routes/workspaces';
import type { Locale } from '@/types/shared';

export type Endpoint = UrlMethodPair;

export type QueryValue =
    | string
    | number
    | boolean
    | null
    | undefined
    | Array<string | number>;

export function withQuery(
    url: string,
    query: Record<string, QueryValue>,
): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (Array.isArray(value)) {
            value.forEach((item) => params.append(`${key}[]`, String(item)));
            continue;
        }

        params.set(key, String(value));
    }

    const qs = params.toString();

    if (!qs) {
        return url;
    }

    return `${url}${url.includes('?') ? '&' : '?'}${qs}`;
}

function raw(url: string, method: Endpoint['method'] = 'get'): Endpoint {
    return { url, method };
}

export const endpoints = {
    home: () => home(),
    pricing: () => pricing(),
    security: () => security(),
    legal: (page: string) => legal(page),
    legalVersion: (doc: string, version?: string | null) =>
        version ? legal(doc, { query: { version } }) : legal(doc),

    legalPages: {
        index: () => raw('/legal'),
        accept: () => raw('/legal/accept'),
        acceptStore: () => raw('/legal/accept', 'post'),
    },
    privacy: {
        show: () => raw('/settings/privacy'),
        export: () => raw('/settings/privacy/export', 'post'),
        scheduleDeletion: () => raw('/settings/privacy/delete-account', 'post'),
        cancelDeletion: () => raw('/settings/privacy/delete-account', 'delete'),
        updateWorkspace: () => raw('/settings/privacy/workspace', 'patch'),
        exportWorkspace: () => raw('/settings/workspace/export', 'post'),
        scheduleWorkspaceDeletion: () =>
            raw('/settings/workspace/delete', 'post'),
        cancelWorkspaceDeletion: () =>
            raw('/settings/workspace/delete', 'delete'),
    },
    locale: (locale: Locale) => localeUpdate(locale),

    dashboard: () => dashboard(),

    onboarding: {
        show: () => onboardingWorkspace(),
        create: () => onboardingWorkspace({ query: { new: 1 } }),
        store: () => onboardingStore(),
    },

    workspaces: {
        switch: (workspaceId: number) => workspaceSwitch(workspaceId),
    },

    settings: {
        workspace: {
            show: () => settingsWorkspace(),
            update: () => workspaceUpdate(),
            destroy: () => workspaceDestroy(),
        },
        members: {
            index: () => settingsMembers(),
            invite: () => invitationStore(),
            revokeInvitation: (invitationId: number) =>
                invitationDestroy(invitationId),
            updateRole: (userId: number) => memberUpdate(userId),
            remove: (userId: number) => memberDestroy(userId),
        },
        billing: {
            show: () => settingsBilling(),
            checkout: () => billingCheckout(),
            portal: () => billingPortal(),
            changePlan: () => billingChangePlan(),
            cancel: () => billingCancel(),
            resume: () => billingResume(),
        },
        integrations: {
            show: () => settingsIntegrations(),
            telegramLink: () => telegramLink(),
            telegramUnlink: () => telegramUnlink(),
        },
    },

    invitations: {
        show: (token: string) => invitationShow(token),
        accept: (token: string) => invitationAccept(token),
    },

    assistant: {
        index: () => assistantIndex(),
        ask: () => assistantAsk(),
        action: () => assistantActions(),
        history: () => assistantHistory(),
    },

    knowledge: {
        index: () => knowledgeIndex(),
        store: () => knowledgeStore(),
        show: (documentId: number) => knowledgeShow(documentId),
        update: (documentId: number) => knowledgeUpdate(documentId),
        destroy: (documentId: number) => knowledgeDestroy(documentId),
        download: (documentId: number) => knowledgeDownload(documentId),
    },

    audit: {
        index: () => auditIndex(),
    },

    invoices: {
        index: () => invoicesIndex(),
    },

    clients: {
        index: () => clientsIndex(),
    },

    reminders: {
        index: () => remindersIndex(),
    },

    analytics: {
        index: () => analyticsIndex(),
    },

    imports: {
        index: () => importsIndex(),
    },

    guard: {
        index: () => guardIndex(),
    },

    payouts: {
        index: () => payoutsIndex(),
    },

    vendors: {
        index: () => vendorsIndex(),
    },

    notifications: {
        read: () => notificationsRead(),
    },

    admin: {
        ai: {
            index: () => adminAiIndex(),
            settings: () => adminAiSettings(),
            key: (provider: string) => adminAiKeys(provider),
            models: (provider: string) => adminAiModels(provider),
            test: () => adminAiTest(),
        },
    },

    dev: {
        fakeCheckout: (session: string) => fakeCheckout(session),
        fakeCheckoutPay: (session: string) => fakeCheckoutPay(session),
    },
} as const;
