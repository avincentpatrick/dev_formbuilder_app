<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\FormVersionReferenceFile;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of a draft's reference files (M132, `R-bf49e4c1`, `D61` = B): attach, rename, remove, and the
 * clean-up of a file no version shows any more. Publishing and restoring copy the rows forward through
 * {@see SchemaTreeCloner}; nothing here touches a published version, and the table's draft-child RLS would refuse
 * it if anything tried.
 *
 * ── EVERY WRITE TAKES THE `forms` ROW LOCK FIRST ───────────────────────────────────────────────────────────
 * The draft-child policy reads the version's COMMITTED status, and a version is committed-`draft` until the
 * moment its publish commits. So a row written mid-publish without the lock would land in the version being
 * frozen, after its snapshot. `PublishService` takes the same lock first and says it relies on every writer
 * doing so (its step-0 note); this is the writer that keeps that true for this table.
 *
 * ── A FILE IS ADDRESSED BY ITS ATTACHMENT ID, NEVER BY ITS ROW ─────────────────────────────────────────────
 * Every publish copies the draft's rows into a new draft with new row ids, while the attachment id never
 * changes. An author's open settings panel therefore keeps working across a publish made in another tab.
 *
 * ── A REMOVED FILE IS KEPT WHILE ANY VERSION SHOWS IT ──────────────────────────────────────────────────────
 * Removing a file from the draft deletes the draft's row only: a published version may still show the file, and
 * its respondents must still be able to open it. The attachment is soft-deleted — freeing its storage — only
 * once no version of any status references it ({@see collectOrphans()}).
 *
 * Not audited, by the versioning spec's own rule (§1 "Deliberately NOT audited"): these are draft edits, and the
 * publish that freezes them is the audited event.
 */
final class FormReferenceFileService
{
    public function __construct(private readonly AttachmentStorageService $storage) {}

    /**
     * Attach one uploaded file to the form's draft, under its own name. Identical bytes the form already holds are
     * reused rather than stored again ({@see AttachmentStorageService::storeFormReferenceFile()}).
     *
     * @return array<string, mixed> the file as the settings panel shows it
     *
     * @throws ValidationException when there is no draft, the draft is full, or it already shows this file
     */
    public function attach(Form $form, UploadedFile $file, User $actor): array
    {
        return $this->guardDuplicate(function () use ($form, $file, $actor): array {
            $draft = $this->lockDraft($form);

            $count = FormVersionReferenceFile::query()->where('form_version_id', $draft->id)->count();
            $max = (int) config('attachments.form_reference_file.max_per_version');

            if ($count >= $max) {
                throw ValidationException::withMessages([
                    'file' => ["A form can show at most {$max} reference files. Remove one to add another."],
                ]);
            }

            [$attachment] = $this->storage->storeFormReferenceFile(
                $file,
                (string) $form->tenant_id,
                (string) $form->id,
                (string) $actor->getKey(),
            );

            if ($this->rowFor($draft, $attachment->id) !== null) {
                throw $this->alreadyAttached();
            }

            $row = FormVersionReferenceFile::create([
                'form_version_id' => $draft->id,
                'attachment_id' => $attachment->id,
                'label' => self::defaultLabel($file->getClientOriginalName()),
                'position' => ((int) FormVersionReferenceFile::query()->where('form_version_id', $draft->id)->max('position')) + 1,
            ]);

            return self::authorRow($row, $attachment);
        });
    }

    /**
     * Rename one of the draft's files — what respondents see in its place.
     *
     * @return array<string, mixed>
     */
    public function relabel(Form $form, string $attachmentId, string $label): array
    {
        return DB::transaction(function () use ($form, $attachmentId, $label): array {
            $draft = $this->lockDraft($form);
            $row = $this->rowFor($draft, $attachmentId) ?? abort(404);

            $row->forceFill(['label' => $label])->save();

            return self::authorRow($row, Attachment::query()->findOrFail($attachmentId));
        });
    }

    /** Remove one of the draft's files; its bytes go only when no version shows it any more. */
    public function detach(Form $form, string $attachmentId): void
    {
        DB::transaction(function () use ($form, $attachmentId): void {
            $draft = $this->lockDraft($form);
            $row = $this->rowFor($draft, $attachmentId) ?? abort(404);

            $row->delete();

            $this->collectOrphans($form);
        });
    }

    /**
     * Soft-delete every reference file of this form that no version references — the only path that ever
     * removes one, so a file a published version shows is never collected.
     *
     * @return int how many were collected
     */
    public function collectOrphans(Form $form): int
    {
        $orphans = Attachment::query()
            ->where('kind', AttachmentKind::FormReferenceFile->value)
            ->where('attachable_type', 'form')
            ->where('attachable_id', $form->id)
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('form_version_reference_files')
                    ->whereColumn('form_version_reference_files.attachment_id', 'attachments.id');
            })
            ->get();

        $orphans->each(static fn (Attachment $attachment) => $attachment->delete());

        return $orphans->count();
    }

    /**
     * A version's files, as the author's settings panel lists them, in display order.
     *
     * @return list<array<string, mixed>>
     */
    public function forAuthor(FormVersion $version): array
    {
        $rows = $version->referenceFiles()->orderBy('position')->orderBy('created_at')->get();
        $attachments = Attachment::query()->whereIn('id', $rows->pluck('attachment_id'))->get()->keyBy('id');

        $files = [];
        foreach ($rows as $row) {
            $attachment = $attachments->get($row->attachment_id);
            if ($attachment instanceof Attachment) {
                $files[] = self::authorRow($row, $attachment);
            }
        }

        return $files;
    }

    /**
     * One file as the settings panel shows it. `scan` is what the panel says: still being checked, ready, or
     * refused by the check (and then never shown to a respondent).
     *
     * @return array{id: string, label: string, file_name: string, mime_type: string, size_bytes: int, scan: string, url: string}
     */
    private static function authorRow(FormVersionReferenceFile $row, Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'label' => $row->label,
            'file_name' => (string) $attachment->original_filename,
            'mime_type' => (string) $attachment->mime_type,
            'size_bytes' => (int) $attachment->size_bytes,
            'scan' => match ($attachment->virus_scan_status) {
                ScanStatus::Pending => 'checking',
                ScanStatus::Infected => 'refused',
                ScanStatus::Clean, ScanStatus::Skipped => 'ready',
            },
            'url' => route('attachments.show', $attachment->id, false),
        ];
    }

    /** The original name, trimmed to the column — the author renames it if it reads badly. */
    private static function defaultLabel(string $fileName): string
    {
        $name = trim($fileName);

        return mb_substr($name === '' ? 'Reference file' : $name, 0, 120);
    }

    private function rowFor(FormVersion $draft, string $attachmentId): ?FormVersionReferenceFile
    {
        return FormVersionReferenceFile::query()
            ->where('form_version_id', $draft->id)
            ->where('attachment_id', $attachmentId)
            ->first();
    }

    /** Lock the form row (see the class docblock) and return its editable draft. */
    private function lockDraft(Form $form): FormVersion
    {
        $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

        if ($locked->draft_version_id === null) {
            throw ValidationException::withMessages([
                'file' => ['This form has no editable draft. Publish or restore a version first.'],
            ]);
        }

        return FormVersion::query()->whereKey($locked->draft_version_id)->firstOrFail();
    }

    private function alreadyAttached(): ValidationException
    {
        return ValidationException::withMessages(['file' => ['This file is already attached to the form.']]);
    }

    /**
     * The unique key is the backstop for a check the lock already makes race-free; matched on the code (23505),
     * never the locale-dependent message, as `FormFolderService::guardName()` does.
     *
     * @param  callable(): array<string, mixed>  $write
     * @return array<string, mixed>
     */
    private function guardDuplicate(callable $write): array
    {
        try {
            return DB::transaction(static fn (): array => $write());
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw $this->alreadyAttached();
            }

            throw $e;
        }
    }
}
