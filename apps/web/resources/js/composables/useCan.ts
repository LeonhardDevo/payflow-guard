import { usePage } from '@inertiajs/vue3';
import type { Ability } from '@/types/shared';

export function useCan() {
    const page = usePage();

    function can(ability: Ability, ...fallbacks: Ability[]): boolean {
        const map = page.props.can ?? {};

        for (const key of [ability, ...fallbacks]) {
            const value = map[key];

            if (typeof value === 'boolean') {
                return value;
            }
        }

        return false;
    }

    return { can };
}
