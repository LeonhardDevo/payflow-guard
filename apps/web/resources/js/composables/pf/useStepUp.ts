import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const open = ref(false);
const pending = ref<(() => void) | null>(null);

export function useStepUp() {
    const page = usePage();
    const stepup = computed(() => page.props.pf?.stepup ?? null);

    function fresh(): boolean {
        const until = stepup.value?.fresh_until;

        return Boolean(until && new Date(until).getTime() > Date.now() + 5_000);
    }

    function run(action: () => void, required = false): void {
        pending.value = action;

        if (required && !fresh()) {
            open.value = true;

            return;
        }

        action();
    }

    function completed(): void {
        open.value = false;
        const action = pending.value;
        pending.value = null;
        action?.();
    }

    function cancel(): void {
        open.value = false;
        pending.value = null;
    }

    return { open, stepup, fresh, run, completed, cancel };
}
