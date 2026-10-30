import {
    BellDot,
    BellRing,
    BookOpenText,
    Bot,
    Building2,
    ChartColumn,
    FileText,
    HandCoins,
    CreditCard,
    History,
    LayoutDashboard,
    Palette,
    PlugZap,
    ScrollText,
    Settings,
    ShieldAlert,
    ShieldCheck,
    Store,
    Upload,
    ShieldUser,
    SlidersHorizontal,
    Timer,
    UserRound,
    UsersRound,
    Cpu,
} from '@lucide/vue';
import { endpoints } from '@/lib/endpoints';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import {
    collections as settingsCollections,
    guard as settingsGuard,
    notifications as settingsNotifications,
    payments as settingsPayments,
} from '@/routes/settings';
import type { NavGroup, NavItem } from '@/types/navigation';
import type { PfShared } from '@/types/payflow';
import type { Ability } from '@/types/shared';

export type CanFn = (ability: Ability, ...fallbacks: Ability[]) => boolean;

export type NavCounts = PfShared['nav_counts'] | null | undefined;

export function appNavigation(
    can: CanFn,
    hasWorkspace: boolean,
    counts?: NavCounts,
): NavGroup[] {
    const workspace: NavItem[] = hasWorkspace
        ? [
              {
                  title: 'ui.nav.dashboard',
                  href: endpoints.dashboard(),
                  icon: LayoutDashboard,
              },
              ...(can('assistant.use')
                  ? [
                        {
                            title: 'ui.nav.assistant',
                            href: endpoints.assistant.index(),
                            icon: Bot,
                        },
                    ]
                  : []),
              ...(can('assistant.history')
                  ? [
                        {
                            title: 'ui.nav.history',
                            href: endpoints.assistant.history(),
                            icon: History,
                        },
                    ]
                  : []),
              ...(can('knowledge.view')
                  ? [
                        {
                            title: 'ui.nav.knowledge',
                            href: endpoints.knowledge.index(),
                            icon: BookOpenText,
                            matchPrefix: true,
                        },
                    ]
                  : []),
              ...(can('imports.run')
                  ? [
                        {
                            title: 'pf.nav.imports',
                            href: endpoints.imports.index(),
                            icon: Upload,
                            matchPrefix: true,
                        },
                    ]
                  : []),
              ...(can('audit.view')
                  ? [
                        {
                            title: 'ui.nav.audit',
                            href: endpoints.audit.index(),
                            icon: ScrollText,
                        },
                    ]
                  : []),
          ]
        : [];

    const groups: NavGroup[] = [];

    if (workspace.length) {
        groups.push({ label: 'ui.nav.group_workspace', items: workspace });
    }

    const receivables: NavItem[] = hasWorkspace
        ? [
              ...(can('invoices.view')
                  ? [
                        {
                            title: 'pf.nav.invoices',
                            href: endpoints.invoices.index(),
                            icon: FileText,
                            matchPrefix: true,
                            badge: counts?.overdue_invoices || null,
                        },
                    ]
                  : []),
              ...(can('clients.view')
                  ? [
                        {
                            title: 'pf.nav.clients',
                            href: endpoints.clients.index(),
                            icon: Building2,
                            matchPrefix: true,
                        },
                    ]
                  : []),
              ...(can('invoices.view')
                  ? [
                        {
                            title: 'pf.nav.reminders',
                            href: endpoints.reminders.index(),
                            icon: BellRing,
                            badge: counts?.failed_reminders || null,
                        },
                    ]
                  : []),
              ...(can('analytics.view')
                  ? [
                        {
                            title: 'pf.nav.analytics',
                            href: endpoints.analytics.index(),
                            icon: ChartColumn,
                        },
                    ]
                  : []),
          ]
        : [];

    if (receivables.length) {
        groups.push({ label: 'pf.nav.receivables', items: receivables });
    }

    const guard: NavItem[] = hasWorkspace
        ? [
              ...(can('vendors.view') || can('payouts.view')
                  ? [
                        {
                            title: 'pf.nav.guard',
                            href: endpoints.guard.index(),
                            icon: ShieldCheck,
                            matchPrefix: true,
                            badge: counts?.critical_alerts || null,
                        },
                    ]
                  : []),
              ...(can('payouts.view')
                  ? [
                        {
                            title: 'pf.nav.payouts',
                            href: endpoints.payouts.index(),
                            icon: HandCoins,
                            matchPrefix: true,
                            badge: counts?.my_approvals || null,
                        },
                    ]
                  : []),
              ...(can('vendors.view')
                  ? [
                        {
                            title: 'pf.nav.vendors',
                            href: endpoints.vendors.index(),
                            icon: Store,
                            matchPrefix: true,
                            badge: counts?.pending_bank_changes || null,
                        },
                    ]
                  : []),
          ]
        : [];

    if (guard.length) {
        groups.push({ label: 'pf.nav.payment_guard', items: guard });
    }

    groups.push({
        label: 'ui.nav.group_manage',
        items: [
            {
                title: 'ui.nav.settings',
                href: hasWorkspace
                    ? endpoints.settings.workspace.show()
                    : editProfile(),
                icon: Settings,
                matchPrefix: true,
            },
        ],
    });

    if (can('admin.ai')) {
        groups.push({
            label: 'ui.nav.group_admin',
            items: [
                {
                    title: 'ui.nav.admin_ai',
                    href: endpoints.admin.ai.index(),
                    icon: Cpu,
                    matchPrefix: true,
                },
            ],
        });
    }

    return groups;
}

export function settingsNavigation(
    can: CanFn,
    hasWorkspace: boolean,
): NavGroup[] {
    const account: NavItem[] = [
        {
            title: 'ui.settings.nav.profile',
            href: editProfile(),
            icon: UserRound,
        },
        {
            title: 'ui.settings.nav.security',
            href: editSecurity(),
            icon: ShieldCheck,
        },
        {
            title: 'ui.settings.nav.appearance',
            href: editAppearance(),
            icon: Palette,
        },
        {
            title: 'ui.settings.nav.privacy',
            href: endpoints.privacy.show(),
            icon: ShieldUser,
        },
    ];

    const workspace: NavItem[] = hasWorkspace
        ? [
              {
                  title: 'ui.settings.nav.workspace',
                  href: endpoints.settings.workspace.show(),
                  icon: SlidersHorizontal,
              },
              {
                  title: 'ui.settings.nav.members',
                  href: endpoints.settings.members.index(),
                  icon: UsersRound,
              },
              ...(can('billing.view', 'billing.manage')
                  ? [
                        {
                            title: 'ui.settings.nav.billing',
                            href: endpoints.settings.billing.show(),
                            icon: CreditCard,
                        },
                    ]
                  : []),
              {
                  title: 'ui.settings.nav.integrations',
                  href: endpoints.settings.integrations.show(),
                  icon: PlugZap,
              },
              ...(can('reminders.configure')
                  ? [
                        {
                            title: 'pf.settings.nav.collections',
                            href: settingsCollections(),
                            icon: Timer,
                        },
                    ]
                  : []),
              ...(can('payouts.view') || can('guard.configure')
                  ? [
                        {
                            title: 'pf.settings.nav.guard',
                            href: settingsGuard(),
                            icon: ShieldAlert,
                        },
                    ]
                  : []),
              ...(can('payments.configure')
                  ? [
                        {
                            title: 'pf.settings.nav.payments',
                            href: settingsPayments(),
                            icon: CreditCard,
                        },
                    ]
                  : []),
              {
                  title: 'pf.settings.nav.notifications',
                  href: settingsNotifications(),
                  icon: BellDot,
              },
          ]
        : [];

    return [
        ...(workspace.length
            ? [{ label: 'ui.settings.nav.group_workspace', items: workspace }]
            : []),
        { label: 'ui.settings.nav.group_account', items: account },
    ];
}
