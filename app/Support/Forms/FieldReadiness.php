<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\AnalyticsFieldEligibility;
use App\Enums\FieldType;
use App\Enums\OcrFieldEligibility;
use App\Enums\OperandKind;
use App\Enums\PdfFieldRole;
use App\Enums\PipingEligibility;
use App\Enums\PrintAnswerArea;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SchemaValueFormatter;

/**
 * M187 (`R-3e6358ed`) — what each question type can do on each surface: the table in Help → Builder reference that
 * answers the testers' *"are the adv questions ready to use?"* (relayed `R1-6`).
 *
 * ⛔ EVERY CELL IS READ FROM THE RULE THAT DECIDES IT, NEVER TYPED. The answer lived only in code — eight per-type rules
 * in eight files — and a hand-written table would be a ninth copy, right on the day it was written and wrong on the day
 * any of the eight changed. So the table is derived here, committed as `resources/js/components/help/field-readiness.json`
 * for the Help page (`php artisan help:readiness` writes it), and `FieldReadinessTableTest` holds the committed file
 * equal to this derivation: change a rule without regenerating, and the build goes red.
 *
 * The words for each verdict are this file's, chosen for an author (DSR §1.5); the verdicts are the rules':
 *   Builder                        {@see FieldType::isRetired()}
 *   Web form and manual entry      {@see EncodeFormPresenter::supports()} — pinned to the guest runtime's set by
 *                                  `FieldTypeMirrorDriftTest`, so one column answers both channels
 *   Paper form                     {@see PrintAnswerArea::for()}
 *   Scan                           {@see OcrFieldEligibility::for()}
 *   Response PDF                   {@see PdfFieldRole::for()}
 *   Spreadsheet, Sheets, Airtable  {@see SchemaValueFormatter::isDataField()}, with a grid's column per cell (M183)
 *   Analytics                      {@see AnalyticsFieldEligibility::for()}
 *   Conditions                     {@see OperandKind::offered()}
 * A type that holds no answer at all ({@see PipingEligibility::NoAnswer}: a note, a page break) shows a dash where an
 * answer would go. The builder's Preview is not a column: it draws each question as the web form does, and its few
 * differences are listed beside the Preview itself (`previewLimitations()`) and under the table.
 */
final class FieldReadiness
{
    /** @var array<string, string> column key ⇒ heading, in the order the table shows them */
    public const COLUMNS = [
        'builder' => 'Builder',
        'answering' => 'Web form and manual entry',
        'paper' => 'Paper form',
        'scan' => 'Scan',
        'pdf' => 'Response PDF',
        'spreadsheet' => 'Spreadsheet, Sheets, Airtable',
        'analytics' => 'Analytics',
        'conditions' => 'Conditions',
    ];

    /** Where the table is committed for the Help page, relative to the repository root. */
    public const PATH = 'resources/js/components/help/field-readiness.json';

    public function __construct(private readonly SchemaValueFormatter $formatter) {}

    /**
     * One row per question type, in the enum's order (the palette's grouping).
     *
     * @return list<array{type: string, label: string, advanced: bool, retired: bool, cells: array<string, string>}>
     */
    public function rows(): array
    {
        return array_map(fn (FieldType $type): array => [
            'type' => $type->value,
            'label' => $type->label(),
            'advanced' => $type->isAdvanced(),
            'retired' => $type->isRetired(),
            'cells' => $this->cells($type),
        ], FieldType::cases());
    }

    /** The committed file's exact bytes: what `help:readiness` writes and the test compares. */
    public function json(): string
    {
        $columns = [];
        foreach (self::COLUMNS as $key => $heading) {
            $columns[] = ['key' => $key, 'heading' => $heading];
        }

        return json_encode(
            ['generated_by' => 'php artisan help:readiness', 'columns' => $columns, 'rows' => $this->rows()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /** @return array<string, string> */
    private function cells(FieldType $type): array
    {
        $noAnswer = PipingEligibility::for($type) === PipingEligibility::NoAnswer;

        return [
            'builder' => $type->isRetired() ? 'Retired' : 'Yes',
            'answering' => $this->answering($type),
            'paper' => $this->paper(PrintAnswerArea::for($type)),
            'scan' => match (OcrFieldEligibility::for($type)) {
                OcrFieldEligibility::Extractable => 'Read',
                OcrFieldEligibility::Neutral => 'Not read',
                OcrFieldEligibility::Excluded => 'Form cannot be scanned',
            },
            'pdf' => match (PdfFieldRole::for($type)) {
                PdfFieldRole::Answer => 'Yes',
                PdfFieldRole::Prose => 'As text',
                PdfFieldRole::Omitted => 'No',
            },
            'spreadsheet' => $this->spreadsheet($type),
            'analytics' => match (AnalyticsFieldEligibility::for($type)) {
                AnalyticsFieldEligibility::Indexable => 'Yes',
                AnalyticsFieldEligibility::Structured, AnalyticsFieldEligibility::Geospatial => 'No',
                AnalyticsFieldEligibility::NoAnswer => '—',
            },
            'conditions' => match (true) {
                $noAnswer => '—',
                OperandKind::for($type)->offered() => 'Yes',
                default => 'No',
            },
        ];
    }

    /** Whether a respondent or a keyer answers it; the four types nobody answers say what happens instead. */
    private function answering(FieldType $type): string
    {
        if (EncodeFormPresenter::supports($type)) {
            return 'Yes';
        }

        return match ($type) {
            FieldType::Note => 'Shown as text',
            FieldType::PageBreak => 'Starts a new page',
            FieldType::Hidden => 'Not shown',
            FieldType::Calculated => 'Worked out',
            default => 'No',
        };
    }

    private function paper(PrintAnswerArea $area): string
    {
        return match ($area) {
            PrintAnswerArea::Comb => 'Boxes',
            PrintAnswerArea::Line => 'A box',
            PrintAnswerArea::Ruled => 'Lines',
            PrintAnswerArea::Choices => 'Boxes to mark',
            PrintAnswerArea::Grid => 'A table',
            PrintAnswerArea::SignatureLine => 'A line to sign on',
            PrintAnswerArea::Prose => 'Printed text',
            PrintAnswerArea::Unavailable => 'Named, not written',
            PrintAnswerArea::PageBreak => 'A new page',
            PrintAnswerArea::Omitted => 'Not printed',
        };
    }

    private function spreadsheet(FieldType $type): string
    {
        if (! $this->formatter->isDataField($type)) {
            return '—';
        }

        return $type === FieldType::Matrix || $type === FieldType::LikertMatrix ? 'A column, and one per cell' : 'A column';
    }
}
