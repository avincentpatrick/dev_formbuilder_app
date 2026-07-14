<?php

declare(strict_types=1);

namespace App\Services\Attachments;

use App\Enums\ScanStatus;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Attachment;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Services\Submissions\SubmissionPipeline;
use Illuminate\Support\Collection;

/**
 * The PHP-only, DB-backed half of media validation (Increment G6), run by the {@see SubmissionPipeline}
 * AFTER the shared Stage-3 engine and BEFORE persist. The shared engine (PHP + its byte-identical TS mirror)
 * checks only the DB-free rules — required / min-count / max-count / shape — so the golden vectors stay
 * parity-able; the checks that need the database live here and here only:
 *
 *   - the referenced attachment EXISTS and is tenant-owned — RLS scopes the lookup, so a cross-tenant id
 *     simply doesn't resolve (`attachment_not_found`);
 *   - it was STAGED FOR THIS FIELD (`attachable` = the form_field it answers) — this both confirms the
 *     staging provenance and blocks referencing one field's file under another (`attachment_not_found`);
 *   - its scan status is not `infected` (`attachment_infected`).
 *
 * The MIME/size allowlist is deliberately NOT re-checked here: the field-owner check above means a file can
 * only be referenced under the very field it was content-sniffed against at upload, and the bytes never
 * change — re-validating against the (mutable) field config would only reject already-valid files on benign
 * author edits.
 */
final class AttachmentReferenceValidator
{
    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $answers  the Stage-3 effective answers (media keys hold AttachmentRef lists)
     *
     * @throws SubmissionValidationException on any missing / mis-owned / infected reference
     */
    public function validate(FormVersion $version, Collection $fields, array $answers): void
    {
        /** @var array<int, array{path: string, id: string, field: FormField}> $refs */
        $refs = [];

        foreach ($fields as $field) {
            if (! $field->field_type->isMedia()) {
                continue;
            }

            $answer = $answers[$field->key] ?? null;
            if (! is_array($answer)) {
                continue; // absent, or dropped as empty by processMedia
            }

            foreach ($answer as $index => $ref) {
                if (! is_array($ref)) {
                    continue;
                }
                $id = $ref['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $refs[] = ['path' => "{$field->key}[{$index}]", 'id' => $id, 'field' => $field];
                }
            }
        }

        if ($refs === []) {
            return;
        }

        $ids = array_values(array_unique(array_map(static fn (array $ref): string => $ref['id'], $refs)));

        /** @var Collection<string, Attachment> $attachments */
        $attachments = Attachment::query()->whereIn('id', $ids)->get()->keyBy('id');

        /** @var list<array{field: string, rule: string, message: string}> $errors */
        $errors = [];

        foreach ($refs as $ref) {
            $attachment = $attachments->get($ref['id']);
            $field = $ref['field'];

            if ($attachment === null
                || $attachment->attachable_type !== 'form_field'
                || $attachment->attachable_id !== $field->id) {
                $errors[] = [
                    'field' => $ref['path'],
                    'rule' => 'attachment_not_found',
                    'message' => 'This uploaded file could not be found for this field.',
                ];

                continue;
            }

            if ($attachment->virus_scan_status === ScanStatus::Infected) {
                $errors[] = [
                    'field' => $ref['path'],
                    'rule' => 'attachment_infected',
                    'message' => 'This uploaded file failed a virus scan and cannot be submitted.',
                ];
            }
        }

        if ($errors !== []) {
            throw SubmissionValidationException::semantic($errors);
        }
    }
}
