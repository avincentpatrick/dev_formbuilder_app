<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FormVersionStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\CapabilityFlags;
use App\Services\Ocr\Bakeoff\OcrBakeoffAnswers;
use App\Services\Ocr\Bakeoff\OcrBakeoffLayout;
use App\Support\Export\SpreadsheetCell;
use App\Support\Tenancy\ExtractionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Writes what the OCR bake-off needs to know about one form, so it can run where the form does not live (M136).
 *
 * Two files: `layout.json` — the form and its published and superseded versions, the rows the matcher reads a scan
 * against (see {@see OcrBakeoffLayout} for why a file) — and `answers-template.xlsx`, the sheet of correct answers to
 * fill in: a `file` column, a `condition` column, and one column per question the reader answers, in printed order, with
 * a second row saying how to type each answer.
 *
 * ── READ-ONLY, AND A COMMAND RATHER THAN A ROUTE ───────────────────────────────────────────────────────────
 * It writes nothing to the database. Shell access on the box is the authorization model, as for
 * `forms:audit-published-rules` and `tenant:extract`: the file carries the form's full schema, which no page serves
 * whole. It runs inside the workspace's own tenant context, and {@see ExtractionGuard::assertContextEstablished()}
 * refuses a context that did not take, which would otherwise answer "no such form" for a form that exists.
 */
final class OcrBakeoffLayoutCommand extends Command
{
    protected $signature = 'ocr:bakeoff-layout
        {tenant : The workspace slug}
        {form : The form id (from the form\'s address)}
        {dir : The folder to write layout.json and answers-template.xlsx into}';

    protected $description = 'Write a form\'s printed layout and a blank answer sheet for the OCR bake-off (ocr:bakeoff)';

    public function handle(BlankFormPrintPresenter $presenter): int
    {
        $slug = (string) $this->argument('tenant');
        $formId = (string) $this->argument('form');
        $dir = rtrim((string) $this->argument('dir'), '/\\');

        if (! Str::isUuid($formId)) {
            $this->error("{$formId} is not a form id. Copy it from the form's address: /forms/<id>.");

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("No workspace has the slug {$slug}.");

            return self::FAILURE;
        }
        $tenantId = (string) $tenant->getKey();

        /** @var array{0: Form, 1: list<FormVersion>}|null $found */
        $found = TenantContext::runFor($tenantId, function () use ($tenantId, $formId): ?array {
            ExtractionGuard::assertContextEstablished($tenantId);

            $form = Form::query()->whereKey($formId)->first();
            if ($form === null) {
                return null;
            }

            $versions = FormVersion::query()
                ->where('form_id', $form->id)
                ->whereIn('status', [FormVersionStatus::Published, FormVersionStatus::Superseded])
                ->orderByDesc('version_number')
                ->get()
                ->all();

            return [$form, array_values($versions)];
        });

        if ($found === null) {
            $this->error("No form {$formId} in workspace {$slug}.");

            return self::FAILURE;
        }

        [$form, $versions] = $found;
        if ($versions === []) {
            $this->error("{$form->title} has never been published, so nothing of it was ever printed. Publish it and print the blank from the published version.");

            return self::FAILURE;
        }

        $export = OcrBakeoffLayout::export($form, $versions);
        $layout = OcrBakeoffLayout::fromArray($export);
        $current = $layout->current();

        if (! CapabilityFlags::isOcrCompatible($current)) {
            $this->error("Version {$current->version_number} of {$form->title} cannot be read automatically (its footer says so), so there is nothing to bake off.");

            return self::FAILURE;
        }

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}.");

            return self::FAILURE;
        }

        $layoutPath = $dir.DIRECTORY_SEPARATOR.'layout.json';
        file_put_contents($layoutPath, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $questions = $layout->questions($current, $presenter);
        $templatePath = $dir.DIRECTORY_SEPARATOR.'answers-template.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($templatePath);
        $writer->addRow(Row::fromValues(SpreadsheetCell::row(array_merge(['file', 'condition'], array_map(static fn ($q): string => $q->key, $questions)))));
        $writer->addRow(Row::fromValues(SpreadsheetCell::row(array_merge(
            ['# the scan\'s file or folder name', '# clean, photo or bad (optional)'],
            array_map(static fn ($q): string => '# '.$q->label.' — '.OcrBakeoffAnswers::hint($q), $questions),
        ))));
        $writer->close();

        $numbers = implode(', ', array_map(static fn (FormVersion $v): string => 'v'.$v->version_number.($v->status === FormVersionStatus::Published ? ' (current)' : ''), $layout->versions));
        $this->info("Wrote {$layoutPath} — {$form->title}, {$numbers}.");
        $this->info("Wrote {$templatePath} — ".count($questions)." question(s) of v{$current->version_number}. Fill one row per scan; a row whose file cell starts with # is a note.");
        $this->line('Next: php artisan ocr:bakeoff <folder of scans> --layout='.$layoutPath.' --answers=<the filled sheet>');

        return self::SUCCESS;
    }
}
