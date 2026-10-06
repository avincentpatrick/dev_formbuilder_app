<?php

declare(strict_types=1);

namespace App\Services\Ocr\Bakeoff;

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\CapabilityFlags;
use App\Services\Ocr\PrintedFormMatcher;
use InvalidArgumentException;

/**
 * The printed layout of one form, carried in a file so the bake-off can run where the form does not live (M136).
 *
 * ── WHY A FILE AND NOT THE DATABASE ────────────────────────────────────────────────────────────────────────
 * The samples may be printed from the testing server while the reading happens on a laptop, or the other way round, and
 * only one of those machines holds the form's versions. Everything the matcher needs from a version is in the version
 * row itself: {@see BlankFormPrintPresenter::present()} and {@see CapabilityFlags::isOcrCompatible()}
 * hold no query (measured in the M136 claim: 0 queries over present, match and resolveVersion on unsaved models). So the
 * export writes those rows out, and the bake-off rebuilds them as UNSAVED models and hands them to the same matcher the
 * reading job uses. Nothing is restated: the layout is still read from the render model the paper was typeset from.
 *
 * The versions are the ones the reading job considers — published and superseded, newest first — because paper in the
 * field outlives a republish (`OcrScanReader::match()`).
 */
final class OcrBakeoffLayout
{
    public const string FORMAT = 'meridian-ocr-bakeoff-layout/1';

    /**
     * @param  list<FormVersion>  $versions  newest first
     */
    private function __construct(
        public readonly Form $form,
        public readonly array $versions,
    ) {}

    /**
     * The file's content for a form and its published and superseded versions.
     *
     * @param  iterable<FormVersion>  $versions
     * @return array<string, mixed>
     */
    public static function export(Form $form, iterable $versions): array
    {
        $rows = [];
        foreach ($versions as $version) {
            $rows[] = [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status->value,
                'checksum' => $version->checksum,
                'published_at' => $version->published_at?->toIso8601String(),
                'schema_snapshot' => $version->schema_snapshot,
            ];
        }

        return [
            'format' => self::FORMAT,
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'default_locale' => $form->default_locale,
            ],
            'versions' => $rows,
        ];
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("No layout file at {$path}. Export one with `php artisan ocr:bakeoff-layout` where the form lives.");
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new InvalidArgumentException("{$path} is not JSON.");
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('This is not a layout file written by `ocr:bakeoff-layout` (format '.self::FORMAT.').');
        }

        $form = is_array($data['form'] ?? null) ? $data['form'] : [];
        $model = (new Form)->forceFill([
            'title' => is_string($form['title'] ?? null) ? $form['title'] : '',
            'description' => is_string($form['description'] ?? null) ? $form['description'] : null,
            'default_locale' => is_string($form['default_locale'] ?? null) ? $form['default_locale'] : 'en',
        ]);
        if (is_string($form['id'] ?? null)) {
            $model->id = $form['id'];
        }

        $versions = [];
        foreach ((array) ($data['versions'] ?? []) as $row) {
            if (! is_array($row) || ! is_array($row['schema_snapshot'] ?? null) || ! is_int($row['version_number'] ?? null)) {
                continue;
            }
            $status = FormVersionStatus::tryFrom(is_string($row['status'] ?? null) ? $row['status'] : '');
            if ($status !== FormVersionStatus::Published && $status !== FormVersionStatus::Superseded) {
                continue;
            }
            $version = (new FormVersion)->forceFill([
                'version_number' => $row['version_number'],
                'status' => $status,
                'checksum' => is_string($row['checksum'] ?? null) ? $row['checksum'] : null,
                'published_at' => is_string($row['published_at'] ?? null) ? $row['published_at'] : null,
                'schema_snapshot' => $row['schema_snapshot'],
            ]);
            if (is_string($row['id'] ?? null)) {
                $version->id = $row['id'];
            }
            $versions[] = $version;
        }

        if ($versions === []) {
            throw new InvalidArgumentException('The layout file holds no published version.');
        }

        usort($versions, static fn (FormVersion $a, FormVersion $b): int => $b->version_number <=> $a->version_number);

        return new self($model, $versions);
    }

    /** The version a scan is read against when its stamp is not read — the reading job's fallback too. */
    public function current(): FormVersion
    {
        foreach ($this->versions as $version) {
            if ($version->status === FormVersionStatus::Published) {
                return $version;
            }
        }

        return $this->versions[0];
    }

    /**
     * The questions the reader answers on one version, in printed order: the presenter's rows less the areas
     * {@see PrintedFormMatcher::NOT_A_QUESTION} names, which is the matcher's own filter.
     *
     * @return list<OcrBakeoffQuestion>
     */
    public function questions(FormVersion $version, BlankFormPrintPresenter $presenter): array
    {
        $snapshot = [];
        foreach ((array) ($version->schema_snapshot['fields'] ?? []) as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $snapshot[$field['key']] = $field;
            }
        }

        $questions = [];
        foreach ((array) ($presenter->present($this->form, $version)['blocks'] ?? []) as $block) {
            foreach (is_array($block) ? (array) ($block['fields'] ?? []) : [] as $row) {
                if (! is_array($row) || ! is_string($row['key'] ?? null) || in_array($row['area'] ?? null, PrintedFormMatcher::NOT_A_QUESTION, true)) {
                    continue;
                }
                $field = $snapshot[$row['key']] ?? [];
                $options = [];
                foreach ((array) ($row['options'] ?? []) as $option) {
                    if (is_array($option) && is_string($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                        $options[] = ['value' => $option['value'], 'label' => $option['label']];
                    }
                }
                /** @var array<string, mixed> $config */
                $config = is_array($field['config'] ?? null) ? $field['config'] : [];
                $questions[] = new OcrBakeoffQuestion(
                    $row['key'],
                    is_string($row['label'] ?? null) ? $row['label'] : $row['key'],
                    FieldType::tryFrom(is_string($field['field_type'] ?? null) ? $field['field_type'] : ''),
                    $config,
                    $options,
                );
            }
        }

        return $questions;
    }
}
