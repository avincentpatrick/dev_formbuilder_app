<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\AuditEvent;
use App\Models\Form;
use App\Models\FormFolder;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of `form_folders` (M131, `R-9e634897`, `D78`, `D79`). Every change is audited under the
 * `form_folder` alias, inside the same transaction as the write — `AuditLogger` requires that.
 *
 * Filing a form is not here: it is an edit of the FORM, written by `FormService::assignFolder()` beside the
 * form's other guarded setters, and audited on the form.
 */
final class FormFolderService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $name, User $actor): FormFolder
    {
        return $this->guardName(function () use ($name, $actor): FormFolder {
            $folder = new FormFolder(['name' => $name]);
            $folder->forceFill(['created_by' => $actor->getKey()])->save();

            $this->record(AuditEvent::Created, $folder, null, ['name' => $name], $actor);

            return $folder;
        });
    }

    public function rename(FormFolder $folder, string $name, User $actor): FormFolder
    {
        return $this->guardName(function () use ($folder, $name, $actor): FormFolder {
            $old = $folder->name;
            $folder->forceFill(['name' => $name])->save();

            $this->record(AuditEvent::Updated, $folder, ['name' => $old], ['name' => $name], $actor);

            return $folder;
        });
    }

    /**
     * Delete a folder; its forms become Unfiled and none is deleted (`D79`). The database unfiles them —
     * `forms_folder_fk` is `ON DELETE SET NULL (folder_id)` — so archived and soft-deleted forms move too, and
     * nothing here can forget one. The audit records how many forms that unfiled, counted under the
     * workspace's RLS before the delete, because afterwards nothing remembers.
     *
     * @return int the number of forms the delete unfiled
     */
    public function delete(FormFolder $folder, User $actor): int
    {
        return DB::transaction(function () use ($folder, $actor): int {
            $unfiled = Form::withTrashed()->where('folder_id', $folder->getKey())->count();

            $folder->delete();

            $this->record(AuditEvent::Deleted, $folder, ['name' => $folder->name, 'unfiled_forms' => $unfiled], null, $actor);

            return $unfiled;
        });
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function record(AuditEvent $event, FormFolder $folder, ?array $old, ?array $new, User $actor): void
    {
        $this->audit->record($event, 'form_folder', (string) $folder->getKey(), old: $old, new: $new, actorId: (string) $actor->getKey());
    }

    /**
     * Turn the case-insensitive unique index into a field error rather than a 500 — the
     * `SavedReportViewService::guardName()` precedent, for its reasons: the duplicate is a real race between
     * two tabs, the code (23505) is matched rather than the locale-dependent message, and the write runs in
     * `DB::transaction()` so a nested caller's transaction survives the violation through a SAVEPOINT.
     *
     * @param  callable(): FormFolder  $write
     */
    private function guardName(callable $write): FormFolder
    {
        try {
            return DB::transaction(static fn (): FormFolder => $write());
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages([
                    'name' => ['A folder with that name already exists.'],
                ]);
            }

            throw $e;
        }
    }
}
