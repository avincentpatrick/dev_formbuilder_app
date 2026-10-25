<?php

declare(strict_types=1);

namespace App\Services\Psgc;

use InvalidArgumentException;

/**
 * The PSA's places normalised to the four levels a Cascading select offers — M186 (`R-70c7673a`, `D131`).
 *
 * ── THE PSA'S LEVELS, AND THE FOUR A RESPONDENT SEES ─────────────────────────────────────────────────────────
 * The PSA codes a place `Reg`, `Prov`, `Dist` (the National Capital Region's districts), `SGU` (the special geographic
 * area), `City`, `Mun`, `SubMun` (the City of Manila's sub-municipalities) or `Bgy` — or leaves the level BLANK. Measured
 * on its 2Q and 3Q 2026 files: no `Dist` and no `SGU` at all, and two blank rows, both coded at the province group (RR PPP
 * 00 000) — `1999900000` Special Geographic Area and `0990100000` City of Isabela (Not a Province), the special province
 * code the City of Isabela sits under. A blank level at the province group is a province-level place, offered as the PSA
 * writes it (`D166` B, the user, 2026-10-26: "City of Isabela (Not a Province)", then "City of Isabela"); a blank level
 * anywhere else is refused by name. A respondent picks four times:
 *
 *   region                  ← Reg
 *   province or district    ← Prov, Dist, SGU — and a city or municipality outside any of them (a highly urbanised or
 *                             independent city, an NCR city), which is ALSO its own one choice at the next level (a
 *                             self-entry: the same code and name), so every barangay has a parent one level up
 *   city or municipality    ← City, Mun
 *   barangay                ← Bgy; one under a sub-municipality is moved to the sub-municipality's city
 *
 * ── PARENTS, FROM THE CODE ───────────────────────────────────────────────────────────────────────────────────
 * A ten-digit code reads RR PPP MM BBB (region, province, city or municipality, barangay). A place's parent is found by
 * zeroing the code from its lowest non-zero group upwards until a row exists — so a barangay finds its sub-municipality
 * or its city, and a city under no province finds its region. Nothing here trusts a level's position in the sheet.
 *
 * Every refusal names the code, because the person reading it is comparing a quarter's workbook with the last one.
 */
final class PsgcTiers
{
    /** The four levels, top down — the order a cascade's levels must follow. */
    public const array TIERS = ['region', 'province', 'city', 'barangay'];

    /** The PSA's level codes this build knows, lower-cased, and what each is. */
    private const array KINDS = [
        'reg' => 'region',
        'prov' => 'province',
        'dist' => 'province',
        'sgu' => 'province',
        'city' => 'city',
        'mun' => 'city',
        'submun' => 'submun',
        'bgy' => 'barangay',
    ];

    /** Where each code group ends: RR PPP MM BBB. */
    private const array GROUP_ENDS = [2, 5, 7, 10];

    /** A code at the province group: RR PPP 00 000, PPP not 000. */
    private const string PROVINCE_GROUP = '/^[0-9]{2}(?!000)[0-9]{3}0{5}$/';

    /** @var array<string, array{code: string, name: string, kind: string}> */
    private array $places = [];

    /**
     * @param  list<array{code: string, name: string, level: string}>  $places  the PSA's rows, its level code as written
     */
    private function __construct(array $places)
    {
        foreach ($places as $place) {
            $level = mb_strtolower(trim($place['level']));
            if ($level === '') {
                if (preg_match(self::PROVINCE_GROUP, $place['code']) !== 1) {
                    throw new InvalidArgumentException("Place {$place['code']} ({$place['name']}) has no level, and its code is not a province's (RR PPP 00 000), so this build cannot place it.");
                }
                $level = 'prov';
            }
            $kind = self::KINDS[$level] ?? null;
            if ($kind === null) {
                throw new InvalidArgumentException("Place {$place['code']} ({$place['name']}) has the level “{$place['level']}”, which this build does not know (it knows Reg, Prov, Dist, SGU, City, Mun, SubMun and Bgy).");
            }
            if (isset($this->places[$place['code']])) {
                throw new InvalidArgumentException("The code {$place['code']} appears twice in the workbook.");
            }
            $this->places[$place['code']] = ['code' => $place['code'], 'name' => $place['name'], 'kind' => $kind];
        }
    }

    /**
     * The four-level rows, region first and each level by code.
     *
     * @param  list<array{code: string, name: string, level: string}>  $places
     * @return list<array{level: string, code: string, name: string, parent_code: ?string}>
     *
     * @throws InvalidArgumentException naming the first place that does not fit
     */
    public static function build(array $places): array
    {
        return (new self($places))->rows();
    }

    /**
     * How many places the PSA codes at each of its levels — what its summary sheet counts.
     *
     * @param  list<array{code: string, name: string, level: string}>  $places
     * @return array<string, int> keyed by the PSA's level code as written, e.g. Reg, Bgy; a blank level as "(blank)"
     */
    public static function psaCounts(array $places): array
    {
        $counts = [];
        foreach ($places as $place) {
            $level = trim($place['level']) !== '' ? trim($place['level']) : '(blank)';
            $counts[$level] = ($counts[$level] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @return list<array{level: string, code: string, name: string, parent_code: ?string}>
     */
    private function rows(): array
    {
        $tiers = array_fill_keys(self::TIERS, []);

        // By value, never by key: PHP turns a key like '1300000000' into an integer, and the code must stay text.
        foreach ($this->places as $place) {
            $code = $place['code'];
            switch ($place['kind']) {
                case 'region':
                    $tiers['region'][$code] = $this->row('region', $place, null);
                    break;

                case 'province':
                    $tiers['province'][$code] = $this->row('province', $place, $this->ancestorOfKind($code, ['region'], $place));
                    break;

                case 'city':
                    $up = $this->nearest($code, $place);
                    if ($up['kind'] === 'region') {
                        // Under no province: its own one choice at the province level, then again at the city level.
                        $tiers['province'][$code] = $this->row('province', $place, $up['code']);
                        $tiers['city'][$code] = $this->row('city', $place, $code);
                    } elseif ($up['kind'] === 'province') {
                        $tiers['city'][$code] = $this->row('city', $place, $up['code']);
                    } else {
                        throw new InvalidArgumentException("Place {$code} ({$place['name']}) sits under {$up['code']} ({$up['name']}), which is not a region, a province or a district.");
                    }
                    break;

                case 'barangay':
                    $up = $this->nearest($code, $place);
                    if ($up['kind'] === 'submun') {
                        $up = $this->places[$this->ancestorOfKind($up['code'], ['city'], $up)];
                    }
                    if ($up['kind'] !== 'city') {
                        throw new InvalidArgumentException("Barangay {$code} ({$place['name']}) sits under {$up['code']} ({$up['name']}), which is not a city, a municipality or a sub-municipality.");
                    }
                    $tiers['barangay'][$code] = $this->row('barangay', $place, $up['code']);
                    break;

                default: // a sub-municipality is not a level a respondent picks; its barangays are moved to its city
                    $this->ancestorOfKind($code, ['city'], $place);
            }
        }

        // A district or province no city names as its parent would be a choice with nothing under it (the NCR's districts,
        // when its cities are coded straight under the region), so it is left out rather than offered empty.
        $parents = array_flip(array_map(static fn (array $row): string => (string) $row['parent_code'], $tiers['city']));
        $tiers['province'] = array_intersect_key($tiers['province'], $parents);

        $rows = [];
        foreach (self::TIERS as $tier) {
            ksort($tiers[$tier], SORT_STRING);
            array_push($rows, ...array_values($tiers[$tier]));
        }

        return $rows;
    }

    /**
     * @param  array{code: string, name: string, kind: string}  $place
     * @return array{level: string, code: string, name: string, parent_code: ?string}
     */
    private function row(string $tier, array $place, ?string $parent): array
    {
        return ['level' => $tier, 'code' => $place['code'], 'name' => $place['name'], 'parent_code' => $parent];
    }

    /**
     * The nearest place above this one.
     *
     * @param  array{code: string, name: string, kind: string}  $place
     * @return array{code: string, name: string, kind: string}
     */
    private function nearest(string $code, array $place): array
    {
        foreach ([2, 1, 0] as $group) {
            $end = self::GROUP_ENDS[$group];
            $candidate = str_pad(substr($code, 0, $end), 10, '0');
            if ($candidate !== $code && isset($this->places[$candidate])) {
                return $this->places[$candidate];
            }
        }

        throw new InvalidArgumentException("Place {$code} ({$place['name']}) has no place above it in the workbook.");
    }

    /**
     * The code of the nearest place above this one, which must be one of these kinds.
     *
     * @param  list<string>  $kinds
     * @param  array{code: string, name: string, kind: string}  $place
     */
    private function ancestorOfKind(string $code, array $kinds, array $place): string
    {
        $up = $this->nearest($code, $place);
        if (! in_array($up['kind'], $kinds, true)) {
            throw new InvalidArgumentException("Place {$code} ({$place['name']}) sits under {$up['code']} ({$up['name']}), where a ".implode(' or a ', $kinds).' was expected.');
        }

        return $up['code'];
    }
}
