import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import { createFormatter } from '@/lib/format';
import type { Formatter } from '@/lib/format';
import { isLocale } from '@/lib/i18n';

export function useFormat(): ComputedRef<Formatter> {
    const page = usePage();

    return computed(() =>
        createFormatter({
            locale: isLocale(page.props.locale) ? page.props.locale : 'en',
            currency: page.props.workspace?.currency ?? 'USD',
            timeZone: page.props.workspace?.timezone ?? null,
        }),
    );
}
