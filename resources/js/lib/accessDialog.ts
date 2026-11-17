import { ref } from 'vue';
import type { AccessStep } from '@/components/AccessDialog.vue';

type ShowOptions = {
    /** After logging in or registering, continue to "create organization" if the user has none. */
    onboarding?: boolean;
    /** Why the dialog was opened, so the opener can react when it closes. */
    purpose?: 'generate' | null;
};

const open = ref(false);
const step = ref<AccessStep>('login');
const onboarding = ref(true);
const purpose = ref<'generate' | null>(null);

/**
 * One login / register / create-organization dialog for the whole app. The layout renders it; any
 * page or the header can open it.
 */
export function useAccessDialog() {
    function show(next: AccessStep, options: ShowOptions = {}): void {
        step.value = next;
        onboarding.value = options.onboarding ?? true;
        purpose.value = options.purpose ?? null;
        open.value = true;
    }

    return { open, step, onboarding, purpose, show };
}
