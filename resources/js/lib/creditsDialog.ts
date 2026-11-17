import { ref } from 'vue';

const open = ref(false);

/**
 * The app-wide "Buy credits" dialog, rendered by the layout and opened from anywhere.
 */
export function useCreditsDialog() {
    return {
        open,
        show(): void {
            open.value = true;
        },
    };
}
