<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use App\Support\Tenancy\TenantExtractColumns;
use RuntimeException;

/**
 * The extractor refused to produce an artefact (Phase 4, P2b — ADR-0018).
 *
 * Same posture as {@see ExtractionGuardException} and for the same reason: every one of these fires on an
 * operator-run console process, none is reachable from a request, and there is deliberately NO handler
 * entry in `bootstrap/app.php`. An extract is read by somebody who will act on it, so the only acceptable
 * failure is a loud stop with nothing written — never a partial directory that looks like a whole one.
 *
 * The messages name the condition AND what to do about it. A refusal that costs the reader the
 * investigation it was meant to save them is a refusal that gets suppressed the second time.
 */
final class TenantExtractException extends RuntimeException
{
    /** @param  list<string>  $columns */
    public static function withheldColumnAbsent(string $table, array $columns): self
    {
        $names = implode(', ', $columns);

        return new self(
            "Refusing to extract `{$table}`: TenantExtractColumns::WITHHELD names {$names}, which the live "
            .'catalog does not have. A withheld column that matches nothing is not a harmless leftover — it '
            .'is a withholding that has SILENTLY STOPPED HAPPENING, and the usual cause is a migration that '
            .'renamed the column while leaving the entry behind. Point the entry at the new name (and decide '
            .'whether the new name is the same secret) before extracting anything.'
        );
    }

    public static function tableAbsent(string $table): self
    {
        return new self(
            "Refusing to extract: `{$table}` is classified for extraction but does not exist in this database. "
            .'Either a migration dropped it and TenantScopedTables was not updated, or this database is behind '
            .'the code. Run `php artisan migrate` and re-check TenantTableClassificationDriftTest.'
        );
    }

    public static function destinationNotEmpty(string $path): self
    {
        return new self(
            "Refusing to extract into {$path}: it already contains files. An extract is a point-in-time "
            .'artefact and merging one into another produces a directory whose manifest describes neither. '
            .'Choose an empty destination or remove the existing one deliberately.'
        );
    }

    public static function notInTransaction(): self
    {
        return new self(
            'Refusing to extract outside a transaction. The tenant GUC is set with SET LOCAL, which is a '
            .'silent no-op with no transaction open — every policy would then match zero rows and the run '
            .'would succeed over an empty database. The snapshot also has to be REPEATABLE READ for the '
            .'tables to describe one consistent instant rather than 41 different ones.'
        );
    }
}
