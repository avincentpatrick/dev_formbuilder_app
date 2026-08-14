<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Extraction;

use App\Exceptions\Tenancy\TenantExtractException;
use App\Support\Tenancy\TenantExtractColumns;
use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Support\Facades\DB;

/**
 * Everything the extractor needs to know about the shape of the database it is reading (Phase 4, P2b).
 *
 * All three questions are answered from the CATALOG rather than from a constant, and that split is
 * deliberate: shape is a fact and belongs to the database, while *policy over* that shape — which columns
 * may leave, which tables are the tenant's — is a decision and lives in {@see TenantExtractColumns} and
 * {@see TenantScopedTables}. Deriving a decision from the catalog is the mistake
 * ADR-0017 §D3 rejected by name; deriving a fact from it is the only correct source.
 *
 * ⚠️ Read on the same connection the extraction runs on. `information_schema` is filtered by what the
 * current role can see, so asking a different connection produces an answer about a different session.
 */
final readonly class ExtractSchema
{
    /**
     * @param  array<string, list<string>>  $columns  table => ordinal-ordered column names
     * @param  array<string, array<string, string>>  $types  table => column => `information_schema.data_type`
     * @param  array<string, list<string>>  $primaryKeys  table => key columns, in index order
     * @param  array<string, list<string>>  $userReferences  table => columns holding a FK into `users`
     */
    private function __construct(
        public array $columns,
        public array $types,
        public array $primaryKeys,
        public array $userReferences,
    ) {}

    public static function read(?string $connection = null): self
    {
        $db = DB::connection($connection);

        /** @var list<object{table_name: string, column_name: string, data_type: string}> $columnRows */
        $columnRows = $db->select(
            "select table_name, column_name, data_type
             from information_schema.columns
             where table_schema = 'public'
             order by table_name, ordinal_position"
        );

        $columns = [];
        $types = [];

        foreach ($columnRows as $row) {
            $columns[$row->table_name][] = (string) $row->column_name;
            $types[$row->table_name][(string) $row->column_name] = (string) $row->data_type;
        }

        /** @var list<object{table_name: string, column_name: string}> $keyRows */
        $keyRows = $db->select(
            "select c.relname as table_name, a.attname as column_name
             from pg_class c
             join pg_namespace n on n.oid = c.relnamespace and n.nspname = 'public'
             join pg_index i on i.indrelid = c.oid and i.indisprimary
             join lateral unnest(i.indkey) with ordinality as k(attnum, ord) on true
             join pg_attribute a on a.attrelid = c.oid and a.attnum = k.attnum
             where c.relkind = 'r'
             order by c.relname, k.ord"
        );

        $primaryKeys = [];

        foreach ($keyRows as $row) {
            $primaryKeys[$row->table_name][] = (string) $row->column_name;
        }

        // Every column anywhere in the schema that points at `users`. Derived rather than listed because
        // the LIST is what goes stale: a later migration adding `forms.archived_by` would otherwise leave a
        // reference the reconciliation never looks for, and the extract would report zero unresolved
        // references while carrying one. There are 34 such columns across 27 tables today.
        /** @var list<object{table_name: string, column_name: string}> $fkRows */
        $fkRows = $db->select(
            "select c.conrelid::regclass::text as table_name, a.attname as column_name
             from pg_constraint c
             join lateral unnest(c.conkey) with ordinality as k(attnum, ord) on true
             join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum
             where c.contype = 'f' and c.confrelid = 'users'::regclass
             order by 1, 2"
        );

        $userReferences = [];

        foreach ($fkRows as $row) {
            $userReferences[$row->table_name][] = (string) $row->column_name;
        }

        return new self($columns, $types, $primaryKeys, $userReferences);
    }

    /**
     * @return list<string>
     *
     * @throws TenantExtractException
     */
    public function columnsOf(string $table): array
    {
        return $this->columns[$table] ?? throw TenantExtractException::tableAbsent($table);
    }

    /** @return list<string> */
    public function primaryKeyOf(string $table): array
    {
        return $this->primaryKeys[$table] ?? [];
    }

    /** @return list<string> */
    public function userReferencesIn(string $table): array
    {
        return $this->userReferences[$table] ?? [];
    }

    public function has(string $table): bool
    {
        return isset($this->columns[$table]);
    }

    /**
     * The `information_schema.data_type` of a column, or null when the name is not a stored column —
     * which is the normal answer for a transformed alias, whose type comes from
     * {@see TenantExtractColumns::TRANSFORMED} instead.
     */
    public function typeOf(string $table, string $column): ?string
    {
        return $this->types[$table][$column] ?? null;
    }
}
