<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\FormVersionGlossaryEntry;
use App\Support\Forms\Glossary;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A form's glossary (M245, `R-d0bbb7bb`): terms and their definitions on the form's DRAFT, edited under Form settings →
 * Glossary, frozen into each published version's snapshot, and copied forward by `SchemaTreeCloner`.
 *
 * ⛔ EVERY WRITE TAKES THE `forms` ROW LOCK FIRST. The table's draft-child policy reads the COMMITTED status, so a write
 * racing a publish could otherwise land on a version that is being published — the reason `FormChoiceListService` does
 * the same.
 *
 * ⛔ AN UPDATE OR A DELETE THAT TOUCHES NO ROW IS AN ERROR, NEVER A SUCCESS. Under RLS a write the policy filters out
 * affects zero rows silently, exactly as a stale id does; both are answered 404 here instead of a 200 that changed
 * nothing. Each write names the draft AND the entry, so neither a published version's row nor another form's is reachable.
 */
final class FormGlossaryService
{
    /**
     * The draft's glossary, in the one order every surface shows it ({@see Glossary::sorted()}).
     *
     * @return list<array{id: string, term: string, definition: string}>
     */
    public function forAuthor(Form $form): array
    {
        if ($form->draft_version_id === null) {
            return [];
        }

        return Glossary::sorted(array_values(FormVersionGlossaryEntry::query()
            ->where('form_version_id', $form->draft_version_id)
            ->get(['id', 'term', 'definition'])
            ->map(static fn (FormVersionGlossaryEntry $entry): array => self::authorRow($entry))
            ->all()));
    }

    /**
     * A version's glossary as its snapshot carries it. A plain read, never a locking one: the publish snapshot and the
     * template blueprint read under REPEATABLE READ, and a lock there would serialise publishing against reading.
     *
     * @return list<array{term: string, definition: string}>
     */
    public static function snapshotOf(string $versionId): array
    {
        return Glossary::sorted(array_values(FormVersionGlossaryEntry::query()
            ->where('form_version_id', $versionId)
            ->get(['term', 'definition'])
            ->map(static fn (FormVersionGlossaryEntry $entry): array => $entry->toSnapshot())
            ->all()));
    }

    /**
     * Add a term to the draft.
     *
     * @return array{id: string, term: string, definition: string}
     */
    public function add(Form $form, string $term, string $definition): array
    {
        [$term, $definition] = self::clean($term, $definition);

        return DB::transaction(function () use ($form, $term, $definition): array {
            $draft = $this->lockDraft($form);
            $entries = FormVersionGlossaryEntry::query()->where('form_version_id', $draft->id);

            if ((clone $entries)->count() >= Glossary::MAX_ENTRIES) {
                throw ValidationException::withMessages(['term' => ['A glossary can hold '.Glossary::MAX_ENTRIES.' terms.']]);
            }
            $this->refuseRepeat($draft, $term, null);

            $entry = new FormVersionGlossaryEntry(['form_version_id' => $draft->id, 'term' => $term, 'definition' => $definition]);
            $entry->save();

            return self::authorRow($entry);
        });
    }

    /**
     * Change a term or its definition on the draft. The words marked with the old term are not rewritten: publish names
     * each one that no longer finds its term, so a rename never silently re-points a word.
     *
     * @return array{id: string, term: string, definition: string}
     */
    public function update(Form $form, string $entryId, string $term, string $definition): array
    {
        [$term, $definition] = self::clean($term, $definition);

        return DB::transaction(function () use ($form, $entryId, $term, $definition): array {
            $draft = $this->lockDraft($form);
            // A published version's entry, another form's, or a removed one: 404 before any other answer.
            if (! FormVersionGlossaryEntry::query()->where('form_version_id', $draft->id)->whereKey($entryId)->exists()) {
                throw new NotFoundHttpException('That term is no longer in this form’s draft glossary.');
            }
            $this->refuseRepeat($draft, $term, $entryId);

            $touched = FormVersionGlossaryEntry::query()
                ->where('form_version_id', $draft->id)
                ->whereKey($entryId)
                ->update(['term' => $term, 'term_key' => Glossary::key($term), 'definition' => $definition, 'updated_at' => now()]);

            if ($touched !== 1) {
                throw new NotFoundHttpException('That term is no longer in this form’s draft glossary.');
            }

            return ['id' => $entryId, 'term' => $term, 'definition' => $definition];
        });
    }

    /** Remove a term from the draft. A marked word that still names it is refused at publish, by name. */
    public function remove(Form $form, string $entryId): void
    {
        DB::transaction(function () use ($form, $entryId): void {
            $draft = $this->lockDraft($form);
            $touched = FormVersionGlossaryEntry::query()->where('form_version_id', $draft->id)->whereKey($entryId)->delete();

            if ($touched !== 1) {
                throw new NotFoundHttpException('That term is no longer in this form’s draft glossary.');
            }
        });
    }

    /**
     * Write a whole glossary onto a draft, as a template's blueprint carries it. The caller holds the draft (the
     * materializer runs inside the form's creation); the blueprint validator has already checked the shape.
     *
     * @param  list<array{term: string, definition: string}>  $entries
     */
    public static function writeAll(FormVersion $draft, array $entries): void
    {
        foreach ($entries as $entry) {
            (new FormVersionGlossaryEntry([
                'form_version_id' => $draft->id,
                'term' => $entry['term'],
                'definition' => $entry['definition'],
            ]))->save();
        }
    }

    /**
     * @return array{0: string, 1: string} the term and definition as stored: spacing collapsed, one paragraph
     */
    private static function clean(string $term, string $definition): array
    {
        $term = Glossary::cleanTerm($term);
        $problem = Glossary::termProblem($term);
        if ($problem !== null) {
            throw ValidationException::withMessages(['term' => ["The term {$problem}."]]);
        }

        $definition = Glossary::cleanDefinition($definition);
        if ($definition === '') {
            throw ValidationException::withMessages(['definition' => ['Write what the term means.']]);
        }
        if (mb_strlen($definition) > Glossary::MAX_DEFINITION_LENGTH) {
            throw ValidationException::withMessages(['definition' => ['A definition can be at most '.Glossary::MAX_DEFINITION_LENGTH.' characters.']]);
        }

        return [$term, $definition];
    }

    private function refuseRepeat(FormVersion $draft, string $term, ?string $exceptId): void
    {
        $repeat = FormVersionGlossaryEntry::query()
            ->where('form_version_id', $draft->id)
            ->where('term_key', Glossary::key($term))
            ->when($exceptId !== null, static fn ($query) => $query->whereKeyNot($exceptId))
            ->value('term');

        if (is_string($repeat)) {
            throw ValidationException::withMessages(['term' => ["This glossary already has “{$repeat}”."]]);
        }
    }

    /** @return array{id: string, term: string, definition: string} */
    private static function authorRow(FormVersionGlossaryEntry $entry): array
    {
        return ['id' => $entry->id, 'term' => $entry->term, 'definition' => $entry->definition];
    }

    private function lockDraft(Form $form): FormVersion
    {
        $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

        if ($locked->draft_version_id === null) {
            throw ValidationException::withMessages([
                'term' => ['This form has no editable draft. Publish or restore a version first.'],
            ]);
        }

        return FormVersion::query()->whereKey($locked->draft_version_id)->firstOrFail();
    }
}
