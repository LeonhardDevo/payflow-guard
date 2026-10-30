import { ref } from 'vue';

const open = ref(false);

export function useCommandPalette() {
    return {
        open,
        show: () => {
            open.value = true;
        },
        hide: () => {
            open.value = false;
        },
        toggle: () => {
            open.value = !open.value;
        },
    };
}

export function isApplePlatform(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    return /Mac|iPhone|iPad|iPod/i.test(
        navigator.platform || navigator.userAgent,
    );
}
