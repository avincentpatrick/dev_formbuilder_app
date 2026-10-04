/**
 * The Automations section's requests (M132, `R-b7bc5149`): add, change, delete and test a form's automations. JSON
 * `fetch` through the Reference files section's helper, for its reason — the section is mounted in the builder too —
 * and every refusal rejects with the server's own sentence.
 *
 * The new secret of a web-address automation comes back exactly once, on the create; nothing else ever returns it.
 */
import { sendSettingsRequest } from '@/components/forms/reference-files';
import type { AutomationAction, AutomationRow } from '@/components/forms/types';

function base(formId: string): string {
    return `/forms/${encodeURIComponent(formId)}/automations`;
}

function rowOf(json: unknown): AutomationRow {
    const data = (json as { data?: AutomationRow } | null)?.data;
    if (data === undefined || typeof data.id !== 'string') {
        throw new Error('That did not work. Check your connection and try again.');
    }
    return data;
}

export interface NewAutomation {
    name: string;
    action: AutomationAction;
    recipients?: string[];
    url?: string;
}

/** Add an automation; a web address's signing secret is returned here, once. */
export async function createAutomation(formId: string, automation: NewAutomation): Promise<{ row: AutomationRow; secret: string | null }> {
    const json = await sendSettingsRequest(base(formId), { method: 'POST', json: automation }, 'The automation was not saved.');
    const secret = (json as { secret?: unknown } | null)?.secret;
    return { row: rowOf(json), secret: typeof secret === 'string' ? secret : null };
}

export async function updateAutomation(
    formId: string,
    automationId: string,
    changes: { name?: string; enabled?: boolean; recipients?: string[]; url?: string },
): Promise<AutomationRow> {
    return rowOf(await sendSettingsRequest(`${base(formId)}/${encodeURIComponent(automationId)}`, { method: 'PATCH', json: changes }, 'The change was not saved.'));
}

export async function deleteAutomation(formId: string, automationId: string): Promise<void> {
    await sendSettingsRequest(`${base(formId)}/${encodeURIComponent(automationId)}`, { method: 'DELETE' }, 'The automation was not deleted.');
}

/** Send one signed test request to a web-address automation, now. */
export async function testAutomation(formId: string, automationId: string): Promise<{ ok: boolean; message: string }> {
    const json = await sendSettingsRequest(`${base(formId)}/${encodeURIComponent(automationId)}/test`, { method: 'POST' }, 'The test was not sent.');
    const data = (json as { data?: { ok?: unknown; message?: unknown } } | null)?.data;
    return { ok: data?.ok === true, message: typeof data?.message === 'string' ? data.message : 'The test was sent.' };
}

/** "a@x.org, b@y.org" or one per line, as typed — trimmed, blanks dropped. The server checks each address. */
export function parseRecipients(text: string): string[] {
    return text
        .split(/[\n,;]+/)
        .map((part) => part.trim())
        .filter((part) => part !== '');
}

/** Why a run did not succeed, in a sentence. The codes are the run's `error_code`. */
export function runReason(code: string | null, responseStatus: number | null): string | null {
    if (code === null) {
        return null;
    }
    if (code.startsWith('http_')) {
        return `The address answered ${responseStatus ?? code.slice(5)}.`;
    }
    switch (code) {
        case 'transport_error':
            return 'The address did not answer.';
        case 'blocked_url':
            return 'The address points into a private network, so it was not called.';
        case 'quota_exceeded':
            return "This month's delivery allowance is used up.";
        case 'plan_feature':
            return "The workspace's plan does not include webhooks.";
        case 'submission_missing':
            return 'The response no longer exists.';
        case 'disabled':
            return 'The automation was switched off.';
        default:
            return null;
    }
}
