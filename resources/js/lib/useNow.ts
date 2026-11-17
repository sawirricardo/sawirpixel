import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The current time, refreshed every minute, so relative times like "expires in 3 hours" stay current.
 */
export function useNow(intervalMs = 60_000) {
    const now = ref(Date.now());
    let timer: ReturnType<typeof setInterval> | undefined;

    onMounted(() => {
        timer = setInterval(() => (now.value = Date.now()), intervalMs);
    });
    onBeforeUnmount(() => clearInterval(timer));

    return now;
}
