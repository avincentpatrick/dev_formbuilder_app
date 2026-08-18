<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Support\Mapping\ColumnFingerprint;
use App\Support\Mapping\ColumnMapping;

/**
 * One tabular destination as the rule editor needs to see it: which document, which tabs it has, and what is
 * actually written in the row the mapping will be authored against (H16b).
 *
 * Provider-neutral by construction, the same rule {@see ConnectorChannel} follows — a Google `sheets` array and
 * an Airtable `tables` array both reduce to `$tabs`, so nothing downstream learns a provider's field names.
 *
 * ── WHY THE RAW HEADER ROW TRAVELS SEPARATELY FROM THE MAPPING ─────────────────────────────────────────────
 * {@see ColumnMapping::author()} NORMALISES each header before storing it — that is the whole point of
 * {@see ColumnFingerprint}, which has to treat `Full Name` and `full name ` as the same column or every
 * whitespace edit would read as drift. But a UI that echoes the normalised form back tells the tenant their
 * sheet says something it does not, and the mismatch is invisible until they go looking for a column that
 * appears not to exist. So `$headerRow` is verbatim: the strings Google returned, in the order it returned
 * them, blanks included.
 *
 * ── AND WHY A BLANK IS A COLUMN ────────────────────────────────────────────────────────────────────────────
 * An empty cell in row 1 is a real, addressable column — both consumers of this engine write positionally, so
 * dropping it would shift every column after it one place left and file every answer under the wrong heading.
 * It arrives here as `''` and the editor must render it as an unnamed column rather than skip it.
 */
final readonly class TabularDestination
{
    /**
     * @param  list<string>  $tabs  every tab in the document, in document order
     * @param  list<string>  $headerRow  row 1 of `$sheetName`, verbatim and positional
     * @param  ?string  $sheetId  the chosen tab's STABLE id, when the provider has one (H16c)
     */
    public function __construct(
        public string $spreadsheetId,
        public string $title,
        public string $url,
        public array $tabs,
        public string $sheetName,
        public array $headerRow,
        public ?string $sheetId = null,
    ) {}

    /**
     * @return array{spreadsheet_id: string, title: string, url: string, tabs: list<string>, sheet_name: string, header_row: list<string>, sheet_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'spreadsheet_id' => $this->spreadsheetId,
            'title' => $this->title,
            'url' => $this->url,
            'tabs' => $this->tabs,
            'sheet_name' => $this->sheetName,
            'header_row' => $this->headerRow,
            'sheet_id' => $this->sheetId,
        ];
    }
}
