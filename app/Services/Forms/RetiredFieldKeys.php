<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVersion;

/**
 * M172 (`R-a44638b6`, `D133`) — the questions a form has retired: a key some published version of the form held and its
 * CURRENT published version does not, with the number of the version that first went without it.
 *
 * Deleting a question works in the draft and touches no response: every answer stays under the version it was given in
 * (`submission_answers.answers` is keyed by field key). What was missing is the note — the response page and the CSV/XLSX
 * headers presented a retired question's answer exactly as a live one's. The user's design: keep it, marked "removed from
 * the form in version N".
 *
 * Read from the published snapshots' KEYS only: Postgres hands back each version's key list, so no snapshot is loaded
 * whole. Section keys are not questions and are not reported.
 */
final class RetiredFieldKeys
{
    /**
     * @return array<string, int> key ⇒ the number of the version it was removed in
     */
    public function for(Form $form): array
    {
        $rows = FormVersion::query()
            ->where('form_id', $form->id)
            ->whereNotNull('published_at')
            ->orderBy('version_number')
            ->selectRaw(
                "version_number, coalesce((select jsonb_agg(f ->> 'key') from jsonb_array_elements("
                ."case when jsonb_typeof(schema_snapshot -> 'fields') = 'array' then schema_snapshot -> 'fields' else '[]'::jsonb end"
                .') as f), \'[]\'::jsonb) as field_keys',
            )
            ->toBase()
            ->get();

        /** @var array<int, list<string>> $keysByVersion */
        $keysByVersion = [];

        foreach ($rows as $row) {
            $keys = json_decode((string) $row->field_keys, true);
            $keysByVersion[(int) $row->version_number] = is_array($keys) ? array_values(array_filter($keys, is_string(...))) : [];
        }

        if ($keysByVersion === []) {
            return [];
        }

        $numbers = array_keys($keysByVersion);
        $current = array_flip($keysByVersion[max($numbers)]);

        // The LAST published version that still held each retired key — versions ascend, so the last write wins.
        $lastHeld = [];
        foreach ($keysByVersion as $number => $keys) {
            foreach ($keys as $key) {
                if (! isset($current[$key])) {
                    $lastHeld[$key] = $number;
                }
            }
        }

        // Removed in the first published version after that one.
        return array_map(static function (int $last) use ($numbers): int {
            foreach ($numbers as $number) {
                if ($number > $last) {
                    return $number;
                }
            }

            return $last + 1;
        }, $lastHeld);
    }

    /**
     * M240 (`builder-copy-section`, `D225`) — every key ANY published version of the form ever held, a question's or a
     * section's: the keys a copy must never take. {@see for()} is the wrong set for that. It leaves out a key the current
     * version still holds (deleted in the draft only, so a copy taking it would mix that question's answers with the
     * copy's) and every section key, though a repeatable section's answers are stored under its key.
     *
     * @return array<string, true>
     */
    public function everHeld(Form $form): array
    {
        $rows = FormVersion::query()
            ->where('form_id', $form->id)
            ->whereNotNull('published_at')
            ->selectRaw($this->keysOf('fields').' as field_keys, '.$this->keysOf('sections').' as section_keys')
            ->toBase()
            ->get();

        $held = [];

        foreach ($rows as $row) {
            foreach ([$row->field_keys, $row->section_keys] as $json) {
                $keys = json_decode((string) $json, true);

                foreach (is_array($keys) ? $keys : [] as $key) {
                    if (is_string($key) && $key !== '') {
                        $held[$key] = true;
                    }
                }
            }
        }

        return $held;
    }

    /** The SQL for one snapshot array's key list, as {@see for()} reads `fields` — a missing or non-array member is []. */
    private function keysOf(string $member): string
    {
        return "coalesce((select jsonb_agg(f ->> 'key') from jsonb_array_elements("
            ."case when jsonb_typeof(schema_snapshot -> '{$member}') = 'array' then schema_snapshot -> '{$member}' else '[]'::jsonb end"
            .') as f), \'[]\'::jsonb)';
    }
}
