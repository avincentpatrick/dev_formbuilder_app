/** Test builders for the raw `schema_snapshot` shapes the F5 backend returns. */
import type { RawField, RawSchemaSnapshot, RawSection, RawValidation, SchemaResponse } from '../lib/types';

export function field(partial: Partial<RawField> & { key: string }): RawField {
    return {
        key: partial.key,
        section_key: partial.section_key ?? null,
        field_type: partial.field_type ?? 'short_text',
        config: partial.config ?? null,
        label: partial.label ?? partial.key,
        label_translations: partial.label_translations ?? null,
        hint: partial.hint ?? null,
        hint_translations: partial.hint_translations ?? null,
        placeholder: partial.placeholder ?? null,
        default_value: partial.default_value ?? null,
        default_value_is_expression: partial.default_value_is_expression ?? false,
        is_required: partial.is_required ?? 'optional',
        relevant_expression: partial.relevant_expression ?? null,
        appearance: partial.appearance ?? null,
        sequence: partial.sequence ?? 0,
        section_sequence: partial.section_sequence ?? null,
        validations: partial.validations ?? [],
    };
}

export function section(partial: Partial<RawSection> & { key: string }): RawSection {
    return {
        key: partial.key,
        label: partial.label ?? partial.key,
        label_translations: partial.label_translations ?? null,
        description: partial.description ?? null,
        description_translations: partial.description_translations ?? null,
        sequence: partial.sequence ?? 0,
        is_repeatable: partial.is_repeatable ?? false,
        min_instances: partial.min_instances ?? null,
        max_instances: partial.max_instances ?? null,
        relevant_expression: partial.relevant_expression ?? null,
    };
}

export function validation(partial: Partial<RawValidation> = {}): RawValidation {
    return {
        rule_type: partial.rule_type ?? null,
        operator: partial.operator ?? null,
        rule_value: partial.rule_value ?? null,
        expression: partial.expression ?? null,
        error_message: partial.error_message ?? null,
        error_message_translations: partial.error_message_translations ?? null,
        related_field_key: partial.related_field_key ?? null,
        logic_group_ordinal: partial.logic_group_ordinal ?? null,
        logic_operator: partial.logic_operator ?? null,
        sequence: partial.sequence ?? 0,
    };
}

export function schemaResponse(opts: {
    fields: RawField[];
    sections?: RawSection[];
    form?: Partial<SchemaResponse['form']>;
    checksum?: string;
    /** Increment H21b — the replay version guard compares against `version.id`, so tests must be able to move it. */
    versionId?: string;
}): SchemaResponse {
    const schema: RawSchemaSnapshot = { sections: opts.sections ?? [], fields: opts.fields };
    return {
        form: {
            id: opts.form?.id ?? 'form-1',
            title: opts.form?.title ?? 'Test form',
            description: opts.form?.description ?? null,
            default_locale: opts.form?.default_locale ?? 'en',
            supported_locales: opts.form?.supported_locales ?? ['en'],
            single_page_mode: opts.form?.single_page_mode ?? true,
            save_and_resume: opts.form?.save_and_resume ?? false,
            // Increment H6b — passed through rather than defaulted, so `undefined` (the absent-key case a
            // pre-H6a cached manifest produces) stays reachable in tests.
            confirmation_message: opts.form?.confirmation_message,
            confirmation_message_translations: opts.form?.confirmation_message_translations,
        },
        version: {
            id: opts.versionId ?? 'ver-1',
            version_number: 1,
            checksum: opts.checksum ?? 'checksum-abc',
            schema,
        },
    };
}

/*
|--------------------------------------------------------------------------
| Increment I10d — shared builders for the sync surface.
|--------------------------------------------------------------------------
| HERE rather than inside one spec because two component specs need them, and Vitest specs are EXCLUDED from
| `vue-tsc` (tsconfig.json's `exclude`), so a stale local copy would not fail type-check — it would fail at
| runtime as "sync.retryOne is not a function", or worse render an empty list and pass green. One fake, one
| place for it to be stale.
*/

import { ref } from 'vue';
import { vi } from 'vitest';
import type { OutboxRow } from '../lib/db';
import type { SyncOutbox } from '../composables/useSyncOutbox';

export function outboxRow(partial: Partial<OutboxRow> = {}): OutboxRow {
    return {
        client_submission_uuid: partial.client_submission_uuid ?? 'u1',
        slug: partial.slug ?? 'clinic-intake',
        form_version_id: partial.form_version_id ?? 'ver-1',
        checksum: partial.checksum ?? 'checksum-abc',
        answers: partial.answers ?? {},
        locale: partial.locale ?? 'en',
        // Increment P3a — defaults to null, the ordinary fill that never created a server draft.
        base_content_checksum: partial.base_content_checksum ?? null,
        device_id: partial.device_id ?? 'device-1',
        app_version: partial.app_version ?? '1',
        submitted_at: partial.submitted_at ?? '2026-08-09T00:00:00.000Z',
        status: partial.status ?? 'pending',
        attempts: partial.attempts ?? 0,
        last_error: partial.last_error ?? null,
        conflict_code: partial.conflict_code ?? null,
        server_submission_id: partial.server_submission_id ?? null,
        server_reference: partial.server_reference ?? null,
        synced_at: partial.synced_at ?? null,
        created_at: partial.created_at ?? '2026-08-09T00:00:00.000Z',
        updated_at: partial.updated_at ?? '2026-08-09T00:00:00.000Z',
    };
}

type Counts = Partial<Record<'pending' | 'needsAttention' | 'conflict' | 'conflictHere', number>>;

export function fakeSync(over: Counts = {}, slug = 'clinic-intake'): SyncOutbox {
    return {
        pending: ref(over.pending ?? 0),
        needsAttention: ref(over.needsAttention ?? 0),
        conflict: ref(over.conflict ?? 0),
        conflictHere: ref(over.conflictHere ?? 0),
        syncing: ref(false),
        syncingUuids: ref<ReadonlySet<string>>(new Set()),
        rows: ref<OutboxRow[]>([]),
        reviewingUuid: ref<string | null>(null),
        lastAnnouncement: ref(''),
        quotaWarning: ref<string | null>(null),
        slug,
        refresh: vi.fn(async () => {}),
        syncNow: vi.fn(async () => {}),
        retryNeedsAttention: vi.fn(async () => {}),
        retryOne: vi.fn(async () => {}),
        nextConflict: vi.fn(async () => null),
        conflictRow: vi.fn(async () => null),
        discardSubmission: vi.fn(async () => {}),
        registerBackgroundSync: vi.fn(),
        dispose: vi.fn(),
    };
}
