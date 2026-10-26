<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Support\Navigation\CrumbTrail;

/**
 * M189 (`version-diff-ui`, `D169`) — the Compare page's props: two versions of a form, what differs between them
 * ({@see VersionComparison}), and the builder's words to say it in, so a setting reads here exactly as it does in the
 * settings panel, the conflict dialog and the History tab (`settingWords()` in `conflict-diff.ts`).
 *
 * A published version is read from its frozen `schema_snapshot`; the draft from its rows, serialized as publish would
 * serialize it ({@see SchemaSnapshotSerializer::snapshot()} — three queries, never one per question).
 */
final class VersionComparePresenter
{
    public function __construct(
        private readonly VersionComparison $comparison,
        private readonly SchemaSnapshotSerializer $serializer,
        private readonly BuilderPresenter $builder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Form $form, FormVersion $from, FormVersion $to, User $user): array
    {
        $fromSnapshot = $this->snapshot($from);
        $toSnapshot = $this->snapshot($to);

        return [
            'form' => ['id' => $form->id, 'title' => $form->title],
            'from' => $this->side($form, $from),
            'to' => $this->side($form, $to),
            'versions' => $this->versions($form),
            'comparison' => $this->comparison->compare($fromSnapshot, $toSnapshot),
            // Each side's own question labels, so a condition reads in the words its version used.
            'labels' => ['from' => self::labels($fromSnapshot), 'to' => self::labels($toSnapshot)],
            'words' => ['enums' => $this->builder->enums(), 'type_labels' => self::typeLabels()],
            'compare_base' => "/forms/{$form->id}/compare",
            'hub_href' => "/forms/{$form->id}",
            'crumbs' => CrumbTrail::forms($user)->form($form)->current('Compare versions'),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(FormVersion $version): array
    {
        if ($version->status === FormVersionStatus::Draft) {
            return $this->serializer->snapshot($version);
        }

        $snapshot = $version->schema_snapshot;

        return is_array($snapshot) ? $snapshot : [];
    }

    /** @return array{id: string, version_number: int, status: string, name: string, published_at: ?string} */
    private function side(Form $form, FormVersion $version): array
    {
        return [
            'id' => (string) $version->id,
            'version_number' => (int) $version->version_number,
            'status' => $version->status->value,
            'name' => self::name($form, $version),
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    /**
     * Every version either picker may offer: the published ones and the form's own draft, newest first. Their snapshots
     * are never loaded for this — a published version holding the Philippine places is megabytes.
     *
     * @return list<array{id: string, name: string}>
     */
    private function versions(Form $form): array
    {
        return $form->versions()
            ->orderByDesc('version_number')
            ->get(['id', 'form_id', 'version_number', 'status', 'published_at'])
            ->filter(fn (FormVersion $v): bool => $v->status !== FormVersionStatus::Draft || $v->id === $form->draft_version_id)
            ->map(fn (FormVersion $v): array => ['id' => (string) $v->id, 'name' => self::name($form, $v)])
            ->values()
            ->all();
    }

    private static function name(Form $form, FormVersion $version): string
    {
        if ($version->status === FormVersionStatus::Draft) {
            return 'Draft';
        }

        return $version->id === $form->current_published_version_id
            ? "Version {$version->version_number} (live)"
            : "Version {$version->version_number}";
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string> question key => its label
     */
    private static function labels(array $snapshot): array
    {
        $labels = [];
        foreach (is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $labels[$field['key']] = is_string($field['label'] ?? null) && trim($field['label']) !== '' ? $field['label'] : $field['key'];
            }
        }

        return $labels;
    }

    /** @return array<string, string> a question type's value => its name in the palette */
    private static function typeLabels(): array
    {
        $labels = [];
        foreach (FieldType::cases() as $type) {
            $labels[$type->value] = $type->label();
        }

        return $labels;
    }
}
