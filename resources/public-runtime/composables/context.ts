import { inject, type InjectionKey, type Ref } from 'vue';
import type { Announcer } from './useAnnouncer';
import type { FormRuntime } from './useFormRuntime';

/** The result of a submit attempt, as the flow views interpret it. */
export type SubmitOutcome = 'success' | 'field-errors' | 'blocked';

export interface SubmitFlow {
    submitting: Ref<boolean>;
    submit: () => Promise<SubmitOutcome>;
}

/** Provided by `RuntimeSession.vue`; consumed by every runtime component. */
export const RuntimeKey: InjectionKey<FormRuntime> = Symbol('public-runtime');
export const AnnouncerKey: InjectionKey<Announcer> = Symbol('public-runtime-announcer');
export const SubmitFlowKey: InjectionKey<SubmitFlow> = Symbol('public-runtime-submit');
/** Resolves the guest media-upload URL from the api-client's current share token (Increment G6). */
export const UploadUrlKey: InjectionKey<() => string> = Symbol('public-runtime-upload-url');

export function useRuntime(): FormRuntime {
    const runtime = inject(RuntimeKey);
    if (runtime === undefined) {
        throw new Error('FormRuntime was not provided.');
    }
    return runtime;
}

export function useAnnouncer(): Announcer {
    const announcer = inject(AnnouncerKey);
    if (announcer === undefined) {
        throw new Error('Announcer was not provided.');
    }
    return announcer;
}

export function useSubmitFlow(): SubmitFlow {
    const flow = inject(SubmitFlowKey);
    if (flow === undefined) {
        throw new Error('SubmitFlow was not provided.');
    }
    return flow;
}
