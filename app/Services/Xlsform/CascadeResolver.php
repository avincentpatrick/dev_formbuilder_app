<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Enums\FieldType;
use App\Services\Forms\StructuralValidationGate;
use App\Services\Xlsform\Dto\FieldSpec;

/**
 * Reconstructs `cascading_select` fields from an XLSForm during import (Increment G7b), covering BOTH shapes
 * the interop spec recognises:
 *
 *  1. **Our own export** (docs/xlsform-interop-spec.md §8) — a single `select_one <key>` question carrying a
 *     `#meridian: meridian:cascading` marker, whose choices list has `level`/`parent` columns. Rebuilt in
 *     place from that one list.
 *  2. **Foreign multi-question `choice_filter` cascades** (Kobo/ODK-authored) — N `select_one` questions
 *     linked by `choice_filter` (`<col>=${<parent_field>}`). Collapsed into ONE `cascading_select`, keyed by
 *     the root question, with the child questions removed. ODK cascades are linear; a branching or
 *     unresolvable filter chain is left as independent single-selects with a warning (never an error).
 *
 * Produces `config.levels` (`[{key}]`) + `config.options` (`[{level, value, parent, label, label_translations?}]`)
 * exactly as {@see StructuralValidationGate::assertCascadingResolves()} expects, so an
 * imported cascade publishes unchanged.
 */
final class CascadeResolver
{
    /**
     * @param  list<FieldSpec>  $fields
     * @param  array<string, list<array<string, mixed>>>  $choicesByList  normalized choice rows keyed by list_name; each row = {value,label,label_translations,level,parent,raw}
     * @param  list<string>  $warnings
     * @return list<FieldSpec> the resolved field list (collapsed children removed)
     */
    public function resolve(array $fields, array $choicesByList, array &$warnings): array
    {
        $this->resolveOwnExport($fields, $choicesByList, $warnings);

        return $this->resolveForeign($fields, $choicesByList, $warnings);
    }

    /**
     * Phase 1 — our own single-question export (marker or a level/parent-bearing list) → cascading in place.
     *
     * @param  list<FieldSpec>  $fields
     * @param  array<string, list<array<string, mixed>>>  $choicesByList
     * @param  list<string>  $warnings
     */
    private function resolveOwnExport(array $fields, array $choicesByList, array &$warnings): void
    {
        foreach ($fields as $field) {
            if (! $this->isSingleSelect($field) || $field->listName === null) {
                continue;
            }

            $rows = $choicesByList[$field->listName] ?? [];
            $isMarked = $field->marker === XlsformTypeMap::MARKER_CASCADING;
            $hasLevels = $this->anyLeveled($rows);

            if (! $isMarked && ! $hasLevels) {
                continue;
            }

            $field->fieldType = FieldType::CascadingSelect;
            $field->config = $this->configFromLeveledList($rows);
            $field->listName = null;
            $field->choiceFilter = null;
            $field->marker = null;
        }
    }

    /**
     * Phase 2 — foreign `choice_filter` chains → one collapsed cascading field.
     *
     * @param  list<FieldSpec>  $fields
     * @param  array<string, list<array<string, mixed>>>  $choicesByList
     * @param  list<string>  $warnings
     * @return list<FieldSpec>
     */
    private function resolveForeign(array $fields, array $choicesByList, array &$warnings): array
    {
        // Candidates: still-plain select fields (phase 1 already retyped its cascades).
        $candidates = [];
        foreach ($fields as $field) {
            if ($this->isSelect($field)) {
                $candidates[$field->key] = $field;
            }
        }
        if ($candidates === []) {
            return $fields;
        }

        // Parse each candidate's choice_filter into (column, parent-field) pairs that name another candidate.
        /** @var array<string, array<string, string>> $refs  child key => [parentField => filterColumn] */
        $refs = [];
        foreach ($candidates as $key => $field) {
            $refs[$key] = $this->referencedCandidates($field->choiceFilter, $candidates);
        }

        // Immediate parent = the referenced candidate that itself references the most candidates (deepest).
        /** @var array<string, ?string> $parentOf */
        $parentOf = [];
        /** @var array<string, ?string> $parentColOf */
        $parentColOf = [];
        foreach ($candidates as $key => $field) {
            $best = null;
            $bestDepth = -1;
            foreach ($refs[$key] as $parentField => $column) {
                $depth = count($refs[$parentField] ?? []);
                if ($depth > $bestDepth) {
                    $bestDepth = $depth;
                    $best = $parentField;
                }
            }
            $parentOf[$key] = $best;
            $parentColOf[$key] = $best !== null ? $refs[$key][$best] : null;
        }

        // children[parent] = list of child keys.
        /** @var array<string, list<string>> $children */
        $children = [];
        foreach ($parentOf as $key => $parent) {
            if ($parent !== null) {
                $children[$parent][] = $key;
            }
        }

        $removed = [];
        $rebuilt = [];
        foreach ($candidates as $key => $field) {
            // A root is a candidate with children and no parent.
            if ($parentOf[$key] !== null || ! isset($children[$key])) {
                continue;
            }
            if (isset($removed[$key])) {
                continue;
            }

            $chain = $this->linearChain($key, $children);
            if ($chain === null) {
                $warnings[] = "The cascading questions under “{$key}” branch and could not be combined into a single field; they were imported as separate select questions.";

                continue;
            }

            $this->collapseChain($chain, $candidates, $parentColOf, $choicesByList);
            $rebuilt[$key] = $candidates[$key];
            foreach (array_slice($chain, 1) as $childKey) {
                $removed[$childKey] = true;
            }

            $labels = implode(' → ', $chain);
            $warnings[] = 'Collapsed '.count($chain)." cascading questions ({$labels}) into a single cascading field “{$key}”.";
        }

        if ($rebuilt === [] && $removed === []) {
            return $fields;
        }

        $out = [];
        foreach ($fields as $field) {
            if (isset($removed[$field->key])) {
                continue;
            }
            $out[] = $field;
        }

        return $out;
    }

    /**
     * Follow a strictly-linear parent→child path from the root; null if any node branches (>1 child).
     *
     * @param  array<string, list<string>>  $children
     * @return list<string>|null
     */
    private function linearChain(string $root, array $children): ?array
    {
        $chain = [$root];
        $cursor = $root;

        while (isset($children[$cursor])) {
            if (count($children[$cursor]) !== 1) {
                return null; // branching — not a single linear cascade
            }
            $next = $children[$cursor][0];
            if (in_array($next, $chain, true)) {
                return null; // cycle guard
            }
            $chain[] = $next;
            $cursor = $next;
        }

        return count($chain) >= 2 ? $chain : null;
    }

    /**
     * Rewrite the chain's root into a cascading field (levels = one per chain question) and blank its
     * cascade metadata; the caller removes the child questions.
     *
     * @param  list<string>  $chain
     * @param  array<string, FieldSpec>  $candidates
     * @param  array<string, ?string>  $parentColOf
     * @param  array<string, list<array<string, mixed>>>  $choicesByList
     */
    private function collapseChain(array $chain, array $candidates, array $parentColOf, array $choicesByList): void
    {
        $levels = [];
        $options = [];

        foreach ($chain as $depth => $key) {
            $levels[] = ['key' => $key];
            $question = $candidates[$key];
            $rows = $question->listName !== null ? ($choicesByList[$question->listName] ?? []) : [];
            $parentColumn = $depth === 0 ? null : $parentColOf[$key];

            foreach ($rows as $row) {
                $option = [
                    'level' => $key,
                    'value' => (string) ($row['value'] ?? ''),
                    'parent' => $depth === 0 ? null : $this->rawColumn($row, $parentColumn),
                ];
                $option['label'] = (string) ($row['label'] ?? $option['value']);
                if (! empty($row['label_translations'])) {
                    $option['label_translations'] = $row['label_translations'];
                }
                $options[] = $option;
            }
        }

        $root = $candidates[$chain[0]];
        $root->fieldType = FieldType::CascadingSelect;
        $root->config = ['levels' => $levels, 'options' => $options];
        $root->listName = null;
        $root->choiceFilter = null;
        $root->marker = null;
    }

    /**
     * `config.levels`/`config.options` from a single list carrying `level`/`parent` columns (our own export).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{levels: list<array{key: string}>, options: list<array<string, mixed>>}
     */
    private function configFromLeveledList(array $rows): array
    {
        $levels = [];
        $levelSeen = [];
        $options = [];

        foreach ($rows as $row) {
            $level = trim((string) ($row['level'] ?? ''));
            if ($level !== '' && ! isset($levelSeen[$level])) {
                $levelSeen[$level] = true;
                $levels[] = ['key' => $level];
            }

            $parent = trim((string) ($row['parent'] ?? ''));
            $option = [
                'level' => $level,
                'value' => (string) ($row['value'] ?? ''),
                'parent' => $parent === '' ? null : $parent,
            ];
            $option['label'] = (string) ($row['label'] ?? $option['value']);
            if (! empty($row['label_translations'])) {
                $option['label_translations'] = $row['label_translations'];
            }
            $options[] = $option;
        }

        return ['levels' => $levels, 'options' => $options];
    }

    /**
     * The `(column, parent-field)` pairs in a choice_filter that name another candidate question.
     *
     * @param  array<string, FieldSpec>  $candidates
     * @return array<string, string> parentField => filterColumn
     */
    private function referencedCandidates(?string $choiceFilter, array $candidates): array
    {
        if ($choiceFilter === null || trim($choiceFilter) === '') {
            return [];
        }

        $found = [];
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\}/', $choiceFilter, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $column = $match[1];
                $field = $match[2];
                if (isset($candidates[$field])) {
                    $found[$field] = $column;
                }
            }
        }

        return $found;
    }

    /**
     * Read an arbitrary filter column off a normalized choice row (case-insensitive, via its raw map).
     *
     * @param  array<string, mixed>  $row
     */
    private function rawColumn(array $row, ?string $column): ?string
    {
        if ($column === null) {
            return null;
        }
        /** @var array<string, ?string> $raw */
        $raw = is_array($row['raw'] ?? null) ? $row['raw'] : [];
        $value = $raw[strtolower($column)] ?? null;

        return ($value === null || $value === '') ? null : $value;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function anyLeveled(array $rows): bool
    {
        foreach ($rows as $row) {
            if (trim((string) ($row['level'] ?? '')) !== '' || trim((string) ($row['parent'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function isSelect(FieldSpec $field): bool
    {
        return $field->fieldType === FieldType::SingleSelect
            || $field->fieldType === FieldType::Dropdown
            || $field->fieldType === FieldType::MultiSelect;
    }

    private function isSingleSelect(FieldSpec $field): bool
    {
        return $field->fieldType === FieldType::SingleSelect || $field->fieldType === FieldType::Dropdown;
    }
}
