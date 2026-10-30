import { useFormat } from '@/composables/useFormat';
import { humanize, tOr } from '@/lib/i18n';

export function useLimits() {
    const fmt = useFormat();

    function label(key: string): string {
        return tOr(
            `ui.billing.usage.${key}`,
            tOr(`billing.limits.${key}`, humanize(key)),
        );
    }

    function value(key: string, amount: number): string {
        if (key.endsWith('_mb')) {
            return fmt.value.bytes(amount * 1024 * 1024);
        }

        return fmt.value.number(amount);
    }

    return { label, value };
}
