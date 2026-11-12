// The explorer's three JSON reads (M236). `builderClient` is the repository's fetch client and already
// carries the XSRF cookie, same-origin credentials and the non-ok error shape; this is another consumer.
//
// A declaration the server cannot run is NOT an error here: it arrives as `{ result: null, refusal }` in a
// 200, and the page prints the sentence where the chart would have been. What reaches the `catch` is what
// is actually an error — a lost session, a plan that no longer includes the page, the network — and it is
// turned into the same shape so every caller renders one thing.

import { builderClient, BuilderRequestError } from '@/components/builder/builderClient';
import type { Definition, FieldsPayload, RunPayload } from './types';

function failure(error: unknown): RunPayload {
    if (error instanceof BuilderRequestError) {
        return { result: null, refusal: { reason: 'request_failed', message: error.message } };
    }

    return {
        result: null,
        refusal: { reason: 'request_failed', message: 'The chart could not be loaded. Check your connection and try again.' },
    };
}

/** Run a declaration the builder is editing. */
export async function runQuery(definition: Definition): Promise<RunPayload> {
    try {
        const response = await builderClient.post<RunPayload>('/explore/query', { definition });

        return response.conflict ? failure(null) : response.data;
    } catch (error) {
        return failure(error);
    }
}

/** Run a saved chart, as the member looking at it. */
export async function fetchChartResult(chartId: string): Promise<RunPayload> {
    try {
        const response = await builderClient.get<RunPayload>(`/explore/charts/${chartId}/result`);

        return response.conflict ? failure(null) : response.data;
    } catch (error) {
        return failure(error);
    }
}

/** A form's questions as fields. Null when they could not be loaded; the message says why. */
export async function fetchFields(
    source: string,
    formId: string,
): Promise<{ payload: FieldsPayload | null; message: string | null }> {
    const query = new URLSearchParams({ source, form_id: formId });

    try {
        const response = await builderClient.get<FieldsPayload>(`/explore/fields?${query.toString()}`);

        return response.conflict
            ? { payload: null, message: 'The questions could not be loaded.' }
            : { payload: response.data, message: null };
    } catch (error) {
        return {
            payload: null,
            message: error instanceof BuilderRequestError ? error.message : 'The questions could not be loaded.',
        };
    }
}
