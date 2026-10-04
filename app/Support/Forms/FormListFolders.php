<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\FormFolder;
use App\Models\User;

/**
 * The forms list's folder filter (M131, `R-9e634897`, `D78`) — `?folder=<id>` or `?folder=none` for Unfiled.
 *
 * ⛔ IT RUNS OVER THE PRESENTED ROWS, IN PHP, FOR {@see FormListFacets}'s REASONS AND ONE MORE. The rows are the
 * ones `FormPresenter::list()` returned AFTER `Form::scopeVisibleTo()`, so a folder's count can only ever
 * count forms the viewer could already see — "Clinics (0)" is all an Editor learns about a folder holding
 * only an Owner's forms. A SQL count over `forms.folder_id` would count the whole workspace and leak exactly
 * what the row that asked for this said must not leak. Folder NAMES are workspace-shared, so every folder is
 * listed whatever it holds.
 *
 * ⚠️ ADDING THIS FILTER WIDENED `ListEmptyReason::for()`'s SECOND ARGUMENT, which `FormListFacets`'s docblock
 * says any new filter must do: a folder with nothing in it says "no matches", never "create your first form".
 */
final class FormListFolders
{
    /** The `?folder=` value meaning "forms in no folder". */
    public const string UNFILED = 'none';

    /** @var list<array{id: string, name: string}> */
    private array $folders;

    private function __construct()
    {
        $this->folders = array_values(FormFolder::query()
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (FormFolder $f): array => ['id' => (string) $f->id, 'name' => $f->name])
            ->all());
    }

    /** The workspace's folders, alphabetically ignoring case — one query, under the workspace's RLS. */
    public static function load(): self
    {
        return new self;
    }

    /**
     * The chosen folder, `none` for Unfiled, or null for every folder. A folder this workspace does not have,
     * or an array, falls back to null rather than filtering to zero — the `FormListFacets::parse()` rule.
     */
    public function parse(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        if ($raw === self::UNFILED) {
            return self::UNFILED;
        }

        foreach ($this->folders as $folder) {
            if ($folder['id'] === $raw) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function apply(array $rows, ?string $folder): array
    {
        if ($folder === null) {
            return $rows;
        }

        $wanted = $folder === self::UNFILED ? null : $folder;

        return array_values(array_filter($rows, static fn (array $row): bool => ($row['folder_id'] ?? null) === $wanted));
    }

    /**
     * The page's `folders` prop. `$rows` must already carry every OTHER filter and not this one, so each
     * option keeps showing its own total while it is the one selected.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{options: list<array{id: string, name: string, count: int}>, unfiled_count: int, can: array{create: bool, manage: bool}}
     */
    public function present(array $rows, User $user): array
    {
        $counts = [];
        $unfiled = 0;

        foreach ($rows as $row) {
            $id = $row['folder_id'] ?? null;

            if (is_string($id)) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            } else {
                $unfiled++;
            }
        }

        return [
            'options' => array_map(
                static fn (array $f): array => ['id' => $f['id'], 'name' => $f['name'], 'count' => $counts[$f['id']] ?? 0],
                $this->folders,
            ),
            'unfiled_count' => $unfiled,
            'can' => [
                'create' => $user->can('create', FormFolder::class),
                // Rename and delete share one gate (`D79`), so the page needs one flag for both.
                'manage' => $user->can('update', new FormFolder),
            ],
        ];
    }
}
