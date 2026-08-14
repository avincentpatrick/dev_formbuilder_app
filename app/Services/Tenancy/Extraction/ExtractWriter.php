<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Extraction;

use App\Exceptions\Tenancy\TenantExtractException;
use JsonException;
use RuntimeException;

/**
 * Writes the artefact: one NDJSON file per table plus one manifest (Phase 4, P2b — ADR-0018 §D5).
 *
 * ── WHY NDJSON AND NOT ONE JSON DOCUMENT ────────────────────────────────────────────────────────────────
 * A single `{"forms": [...], "submissions": [...]}` document has to be complete in memory before its first
 * byte can be written, and complete in memory again before its first byte can be READ. A tenant's
 * submissions table is the unbounded one here — `SubmissionExporter` and `AuditExporter` both chose lazy
 * chunking over materialising for the same reason — and an extract is produced exactly when a tenant is
 * largest, at offboarding. One object per line streams in both directions and is greppable with no tools.
 *
 * ── WHY PLAIN FILE HANDLES AND NOT THE `Storage` FACADE ─────────────────────────────────────────────────
 * `Storage` buys a disk abstraction this has no use for: the artefact is written by an operator on the box
 * (ADR-0018 §D1 — there is no HTTP surface), to a path that operator names, and it is then their job to
 * move it. Going through a configured disk would fix the destination inside `filesystems.php`, which is
 * the one thing the caller most needs to control.
 *
 * ⚠️ EVERY WRITE IS CHECKED. `fwrite` returns the byte count and returns `false` or a SHORT count on a
 * full disk — it does not throw. An extract that silently loses its last 4KB is worse than one that fails,
 * because the manifest still says how many rows there were supposed to be.
 */
final class ExtractWriter
{
    /** @var resource|null */
    private $handle = null;

    private ?string $openTable = null;

    public function __construct(public readonly string $directory) {}

    /**
     * @throws TenantExtractException
     */
    public function prepare(): void
    {
        if (is_dir($this->directory)) {
            $existing = scandir($this->directory);

            if ($existing === false || array_values(array_diff($existing, ['.', '..'])) !== []) {
                throw TenantExtractException::destinationNotEmpty($this->directory);
            }
        } elseif (! mkdir($this->directory, 0o700, recursive: true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Could not create the extract directory {$this->directory}.");
        }

        // 0700, not the default 0755. The directory holds one tenant's entire record; on a shared box the
        // default would make it world-readable, which is a strange way to end a process whose whole subject
        // is who may see what.
        @chmod($this->directory, 0o700);

        if (! mkdir($tables = $this->directory.'/tables', 0o700) && ! is_dir($tables)) {
            throw new RuntimeException("Could not create {$tables}.");
        }
    }

    public function openTable(string $table): void
    {
        $handle = fopen($this->directory."/tables/{$table}.ndjson", 'wb');

        if ($handle === false) {
            throw new RuntimeException("Could not open the extract file for {$table}.");
        }

        $this->handle = $handle;
        $this->openTable = $table;
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws JsonException
     */
    public function writeRow(array $row): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('writeRow() was called with no table open.');
        }

        // JSON_UNESCAPED_UNICODE keeps the tenant's own language readable in the artefact rather than
        // rendering every non-ASCII form label as \uXXXX; JSON_UNESCAPED_SLASHES does the same for the URLs
        // in webhook_endpoints and attachments. Neither changes what a parser reads back.
        $line = json_encode(
            $row,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )."\n";

        $written = fwrite($this->handle, $line);

        if ($written === false || $written !== strlen($line)) {
            throw new RuntimeException(
                "Short write on {$this->openTable}: the destination is probably full. The artefact is "
                .'incomplete and must not be used.'
            );
        }
    }

    public function closeTable(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
            $this->openTable = null;
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws JsonException
     */
    public function writeManifest(array $manifest): void
    {
        $body = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )."\n";

        // The manifest is written LAST and its presence is what makes the directory an extract. A run that
        // dies mid-table leaves NDJSON files and no manifest, which is unambiguously a failed run — the
        // alternative, writing it first, leaves a directory that describes rows it does not contain.
        if (file_put_contents($this->directory.'/manifest.json', $body) !== strlen($body)) {
            throw new RuntimeException('Short write on manifest.json: the destination is probably full.');
        }
    }
}
