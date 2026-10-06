<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\FormVersionChoiceList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A form's choice lists, uploaded as CSV files in Kobo's format (M141, `R-f69aab42`, `D92` = A, `D95`).
 *
 * A list belongs to the form's DRAFT, named after its file (`provinces.csv` is the list `provinces`), and a cascade
 * level names the list it takes its choices from. Uploading a file whose list already exists REPLACES it — Kobo's own
 * behaviour, and the way an author corrects a file. Publishing freezes the lists with the version
 * (`SchemaTreeCloner`) and turns them into the cascade's options (`ChoiceListMaterializer`).
 *
 * ⛔ EVERY WRITE TAKES THE `forms` ROW LOCK FIRST. The table's draft-child policy reads the COMMITTED status, so a
 * write racing a publish could otherwise land on a version that is being published (`PublishService`'s step-0
 * note) — the reason `FormReferenceFileService` does the same.
 *
 * The file is parsed before the transaction opens: a 42,000-row list takes a moment, and the lock should not.
 */
final class FormChoiceListService
{
    public const int MAX_PER_VERSION = 20;

    public function __construct(private readonly ChoiceListCsvParser $parser) {}

    /**
     * The draft's lists, for the form settings panel and the builder's level editor.
     *
     * @return list<array{name: string, file_name: string, row_count: int, columns: list<string>}>
     */
    public function forAuthor(Form $form): array
    {
        if ($form->draft_version_id === null) {
            return [];
        }

        return array_values(FormVersionChoiceList::query()
            ->where('form_version_id', $form->draft_version_id)
            ->orderBy('name')
            ->get(['id', 'name', 'file_name', 'columns', 'row_count'])
            ->map(static fn (FormVersionChoiceList $list): array => self::authorRow($list))
            ->all());
    }

    /**
     * Add a list from an uploaded file, or replace the list of the same name.
     *
     * @return array{name: string, file_name: string, row_count: int, columns: list<string>}
     */
    public function upload(Form $form, UploadedFile $file): array
    {
        $fileName = $file->getClientOriginalName();
        $name = self::nameFor($fileName);

        if ($name === '') {
            throw ValidationException::withMessages(['file' => ['Name the file after its list, for example “provinces.csv”.']]);
        }

        $parsed = $this->parser->parse((string) $file->getRealPath());

        return DB::transaction(function () use ($form, $name, $fileName, $parsed): array {
            $draft = $this->lockDraft($form);
            $list = FormVersionChoiceList::query()->where('form_version_id', $draft->id)->where('name', $name)->first();

            if ($list === null && FormVersionChoiceList::query()->where('form_version_id', $draft->id)->count() >= self::MAX_PER_VERSION) {
                throw ValidationException::withMessages(['file' => ['A form can hold '.self::MAX_PER_VERSION.' choice lists.']]);
            }

            $list ??= new FormVersionChoiceList(['form_version_id' => $draft->id, 'name' => $name]);
            $list->fill([
                'file_name' => mb_substr($fileName, 0, 255),
                'columns' => $parsed['columns'],
                'rows' => $parsed['rows'],
                'row_count' => count($parsed['rows']),
            ])->save();

            return self::authorRow($list);
        });
    }

    /** Remove a list from the draft. A cascade still naming it is refused at publish, by name. */
    public function remove(Form $form, string $name): void
    {
        DB::transaction(function () use ($form, $name): void {
            $draft = $this->lockDraft($form);
            FormVersionChoiceList::query()->where('form_version_id', $draft->id)->where('name', $name)->delete();
        });
    }

    /**
     * A list's name from its file's: `Provinces 2024.csv` is `provinces_2024`. Lower-case letters, digits, `_` and
     * `-`, at most 64 — the shape a cascade level's `list` may name.
     */
    public static function nameFor(string $fileName): string
    {
        $base = mb_strtolower(pathinfo($fileName, PATHINFO_FILENAME));
        $name = trim((string) preg_replace('/[^a-z0-9_-]+/', '_', $base), '_-');

        return mb_substr($name, 0, 64);
    }

    /** @return array{name: string, file_name: string, row_count: int, columns: list<string>} */
    private static function authorRow(FormVersionChoiceList $list): array
    {
        return [
            'name' => $list->name,
            'file_name' => $list->file_name,
            'row_count' => $list->row_count,
            'columns' => $list->header(),
        ];
    }

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
}
