/**
 * What pressing "send" in a prompt box does, as an explicit state machine. Both composers (the home page
 * and the follow-up box under a thread) work out their state here, in this order of precedence:
 *
 *     needs-account       a guest: open the log in / register dialog
 *     needs-organization  signed in without an organization: create one first
 *     no-permission       their role can't generate: explain, nothing to send
 *     parent-unavailable  a follow-up whose version can't be continued: send disabled
 *     blocked             the organization's limits refuse it (estimate says why): send disabled
 *     needs-verification  email not verified yet: go verify
 *     unaffordable        credits don't cover the most it could cost: offer to buy credits
 *     ready               send it
 *
 * An empty prompt or a request already in flight disables sending in any state; that's input, not state.
 */
export type ComposerState =
    | { kind: 'needs-account' }
    | { kind: 'needs-organization' }
    | { kind: 'no-permission' }
    | { kind: 'parent-unavailable' }
    | { kind: 'blocked'; reason: string }
    | { kind: 'needs-verification' }
    | { kind: 'unaffordable'; canBuyCredits: boolean }
    | { kind: 'ready' };

export type ComposerInput = {
    signedIn: boolean;
    hasOrganization: boolean;
    /** Whether their role in the organization may generate. */
    canGenerate: boolean;
    emailVerified: boolean;
    canBuyCredits: boolean;
    /** The latest estimate, or null while there's none (or it failed validation). */
    estimate: { blocked: string | null; affordable: boolean | null } | null;
    /** For a follow-up: whether the version it continues from can be continued. */
    parentContinuable?: boolean;
};

export function composerState(input: ComposerInput): ComposerState {
    if (!input.signedIn) {
        return { kind: 'needs-account' };
    }

    if (!input.hasOrganization) {
        return { kind: 'needs-organization' };
    }

    if (!input.canGenerate) {
        return { kind: 'no-permission' };
    }

    if (input.parentContinuable === false) {
        return { kind: 'parent-unavailable' };
    }

    if (input.estimate?.blocked) {
        return { kind: 'blocked', reason: input.estimate.blocked };
    }

    if (!input.emailVerified) {
        return { kind: 'needs-verification' };
    }

    if (input.estimate?.affordable === false) {
        return { kind: 'unaffordable', canBuyCredits: input.canBuyCredits };
    }

    return { kind: 'ready' };
}

/**
 * States where pressing send can't do anything useful, so the button is disabled.
 */
export function sendIsDisabled(state: ComposerState): boolean {
    return state.kind === 'blocked' || state.kind === 'parent-unavailable';
}

/**
 * For exhaustive switches over ComposerState: a new state that isn't handled fails type-checking here.
 */
export function unhandledState(state: never): never {
    throw new Error(`Unhandled composer state: ${JSON.stringify(state)}`);
}
