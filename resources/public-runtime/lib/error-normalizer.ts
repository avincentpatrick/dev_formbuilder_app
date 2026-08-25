/**
 * Normalizes every F5 error response into one `NormalizedError` the SPA can branch on. Two things make this
 * non-trivial (both mapped against `bootstrap/app.php` + `SubmissionValidationException`):
 *  - There are TWO 422 shapes: `submission_invalid` carries `details.fields` as a LIST of {field,rule,message}
 *    (the pipeline's per-field failures), while `validation_failed` carries a MAP {field:[msgs]} with keys
 *    dot-prefixed `answers.<key>` (Laravel FormRequest). Both must fold onto the same `fieldErrors` map.
 *  - The mint route (`/f/{slug}`) is NOT under `/api/v1/*`, so its errors are NOT enveloped — a re-mint 404
 *    returns a bare `{message}` (or nothing). We tolerate that shape too.
 */

import type { ErrorKind, NormalizedError } from './types';

interface Envelope {
    code: string | null;
    message: string | null;
    details: Record<string, unknown> | null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function extractEnvelope(body: unknown): Envelope {
    if (isRecord(body) && isRecord(body.error)) {
        const error = body.error;
        return {
            code: typeof error.code === 'string' ? error.code : null,
            message: typeof error.message === 'string' ? error.message : null,
            details: isRecord(error.details) ? error.details : null,
        };
    }
    // Non-enveloped (mint route / framework default): `{message}` at the top level.
    if (isRecord(body) && typeof body.message === 'string') {
        return { code: null, message: body.message, details: null };
    }
    return { code: null, message: null, details: null };
}

function stripAnswersPrefix(key: string): string {
    return key.startsWith('answers.') ? key.slice('answers.'.length) : key;
}

function extractFieldErrors(code: string, details: Record<string, unknown> | null): Record<string, string[]> {
    const out: Record<string, string[]> = {};
    if (details === null) {
        return out;
    }
    const fields = details.fields;

    // submission_invalid: a LIST of {field, rule, message}.
    if (Array.isArray(fields)) {
        for (const entry of fields) {
            if (!isRecord(entry) || typeof entry.field !== 'string') {
                continue;
            }
            const key = stripAnswersPrefix(entry.field);
            const message = typeof entry.message === 'string' ? entry.message : 'This value is not valid.';
            (out[key] ??= []).push(message);
        }
        return out;
    }

    // validation_failed: a MAP {field: [messages]} with `answers.`-prefixed keys.
    if (isRecord(fields)) {
        for (const [rawKey, messages] of Object.entries(fields)) {
            const key = stripAnswersPrefix(rawKey);
            const list = Array.isArray(messages) ? messages.filter((m): m is string => typeof m === 'string') : [];
            if (list.length > 0) {
                (out[key] ??= []).push(...list);
            }
        }
    }

    return out;
}

function classify(status: number, code: string): ErrorKind {
    if (status === 422) {
        return code === 'submission_invalid' || code === 'validation_failed' ? 'field' : 'unknown';
    }
    if (status === 401) {
        return code === 'share_token_expired' ? 'remint' : 'terminal';
    }
    if (status === 403) {
        // A form that was open at load can close / fill up before submit — the write path 403s with a
        // schedule reason code. Surface the schedule state rather than a generic terminal error (H12b).
        if (code === 'form_not_open' || code === 'form_closed' || code === 'max_responses_reached') {
            return 'schedule';
        }

        // I8b — the spam check. ⚠️ NOT `terminal`: replay.ts maps terminal to markNeedsAttention, which
        // parks the row for a human. A challenge that was never sent (the client had no hint) or has
        // gone stale is RECOVERABLE by solving a fresh one, which api-client does exactly once.
        // `guest_disabled` deliberately stays terminal — that one really is unrecoverable.
        if (code === 'challenge_required' || code === 'challenge_failed') {
            return 'challenge';
        }

        return 'terminal';
    }
    if (status === 409) {
        // Increment M14 — FIVE server causes share this status and their remedies are mutually exclusive, so
        // reading only the status was not a simplification, it was an answer. Until M14 every one of them
        // became `refresh`, which means "the form was republished" — and a respondent whose OTHER DEVICE had
        // written the draft was told exactly that, then invited into a resubmit that carried no baseline.
        //
        // The same shape as the 403 block above: the status names the class, `error.code` names the cause,
        // and only the cause implies the fix.
        if (code === 'draft_conflict') {
            return 'draft_stale';
        }
        if (code === 'draft_already_finalized') {
            return 'finalized';
        }
        if (code === 'submission_uuid_claimed') {
            return 'uuid_claimed';
        }
        if (code === 'submission_conflict') {
            return 'conflict';
        }

        // `form_updated`, `submission_version_superseded`, and anything unrecognised — including the synthetic
        // `http_409` a bodyless response produces. ⚠️ THE DEFAULT IS DELIBERATE AND IT IS THE SAFE DIRECTION:
        // re-minting and re-fetching the schema is harmless whatever the real cause was, whereas re-reading a
        // draft that may not exist is not. A code this client has never heard of must not become a remedy.
        return 'refresh';
    }
    if (status === 429) {
        return 'rate_limited';
    }
    if (status === 404) {
        return 'terminal';
    }
    return 'unknown';
}

function defaultMessage(status: number): string {
    if (status === 429) {
        return 'Too many requests. Please wait a moment and try again.';
    }
    if (status >= 500) {
        return 'Something went wrong on our side. Please try again.';
    }
    return 'We could not complete that request.';
}

function parseRetryAfter(header: string | null): number | null {
    if (header === null) {
        return null;
    }
    const seconds = Number.parseInt(header, 10);
    return Number.isFinite(seconds) ? seconds : null;
}

export function normalizeError(status: number, body: unknown, retryAfter: string | null = null): NormalizedError {
    const env = extractEnvelope(body);
    const code = env.code ?? `http_${status}`;
    return {
        httpStatus: status,
        code,
        message: env.message ?? defaultMessage(status),
        fieldErrors: extractFieldErrors(code, env.details),
        kind: classify(status, code),
        retryAfterSeconds: parseRetryAfter(retryAfter),
    };
}

/** Thrown by the API client so callers get the normalized shape without re-parsing. */
export class ApiError extends Error {
    readonly normalized: NormalizedError;

    constructor(normalized: NormalizedError) {
        super(normalized.message);
        this.name = 'ApiError';
        this.normalized = normalized;
    }
}
