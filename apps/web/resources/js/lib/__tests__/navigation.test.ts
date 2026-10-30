import { describe, expect, it } from 'vitest';
import { appNavigation, settingsNavigation } from '@/lib/navigation';
import { toUrl } from '@/lib/utils';
import type { Ability } from '@/types';

const canWith =
    (granted: Ability[]) =>
    (ability: Ability, ...fallbacks: Ability[]) =>
        [ability, ...fallbacks].some((key) => granted.includes(key));

const urls = (groups: ReturnType<typeof appNavigation>) =>
    groups.flatMap((group) => group.items.map((item) => toUrl(item.href)));

describe('appNavigation', () => {
    it('shows workspace sections according to abilities', () => {
        const member = urls(
            appNavigation(canWith(['assistant.use', 'knowledge.view']), true),
        );

        expect(member).toEqual([
            '/dashboard',
            '/assistant',
            '/knowledge',
            '/settings/workspace',
        ]);
    });

    it('adds history, audit and the AI admin area when allowed', () => {
        const admin = urls(
            appNavigation(
                canWith([
                    'assistant.use',
                    'assistant.history',
                    'knowledge.view',
                    'audit.view',
                    'admin.ai',
                ]),
                true,
            ),
        );

        expect(admin).toEqual([
            '/dashboard',
            '/assistant',
            '/assistant/history',
            '/knowledge',
            '/audit',
            '/settings/workspace',
            '/admin/ai',
        ]);
    });

    it('only offers account settings without a workspace', () => {
        const groups = appNavigation(canWith([]), false);

        expect(groups).toHaveLength(1);
        expect(urls(groups)).toEqual(['/settings/profile']);
    });
});

describe('settingsNavigation', () => {
    it('includes billing only for users who can see it', () => {
        const without = settingsNavigation(canWith([]), true).flatMap((group) =>
            group.items.map((item) => toUrl(item.href)),
        );
        const withBilling = settingsNavigation(
            canWith(['billing.view']),
            true,
        ).flatMap((group) => group.items.map((item) => toUrl(item.href)));

        expect(without).not.toContain('/settings/billing');
        expect(withBilling).toContain('/settings/billing');
        expect(withBilling).toContain('/settings/profile');
    });

    it('hides workspace settings without a workspace', () => {
        const groups = settingsNavigation(canWith(['billing.view']), false);

        expect(groups).toHaveLength(1);
        expect(groups[0].label).toBe('ui.settings.nav.group_account');
    });
});
