#!/usr/bin/env python3
"""Prepare a PRIVATE FHSIS 2025 load bundle. Never put its output in git.

Requires openpyxl. Inputs: seven DOH workbooks and a dim_geo JSON export (object
keyed by geo_code). See docs/FHSIS_LOAD.md. Published rates are never recalculated.
"""
import argparse
import hashlib
import json
import re
import unicodedata
from collections import Counter, defaultdict
from decimal import Decimal
from pathlib import Path

import openpyxl

FILES = {
    'demographics': 'Demographic_2025_EB_Final.xlsx',
    'fic': '9 10 FIC _ CIC_MunCity EB 2025_FInal Annual 2025_NF.xlsx',
    'anc4': '1. 4ANC_2025_EB_1_nofml.xlsx',
    'anc8': '1. 8ANC_2025_PH_nofml.xlsx',
    'tb': 'FINAL Tuberculosis_EB_2025.xlsx',
    'water': 'water_nofml.xlsx',
    'sanitation': 'sanitation_nofml.xlsx',
}
CADRES = ['physician', 'nurse', 'midwife', 'dentist', 'med_tech',
          'nutritionist', 'sanitary_engineer', 'sanitary_inspector']
ALIASES = {'ORIENTALMINIDORO': 'ORIENTALMINDORO',
           'DAVAODELORO': 'DAVAODEORO',
           'MAGUINDANAONORTE': 'MAGUINDANAODELNORTE',
           'MAGUINDANAOSUR': 'MAGUINDANAODELSUR'}


def normalise(value):
    value = unicodedata.normalize('NFKD', str(value).upper()).encode('ascii', 'ignore').decode()
    return re.sub('[^A-Z0-9]', '', value)


def short_code(raw):
    value = str(raw).strip().removesuffix('.0').zfill(10)
    if not re.fullmatch(r'\d{10}', value):
        raise ValueError(f'Invalid PSGC: {raw!r}')
    return value[:2] if value[2:] == '0'*8 else value[:5] if value[5:] == '0'*5 else value[:7]


def numeric(value):
    """Keep zero and decimals; only known missing/error markers become null."""
    if value is None or str(value).strip() in ('', '*', '-', '#DIV/0!', '#VALUE!', '#N/A', '#REF!'):
        return None
    try:
        number = Decimal(str(value).strip().replace(',', ''))
    except Exception as error:
        raise ValueError(f'Unrecognised numeric cell: {value!r}') from error
    if not number.is_finite():
        raise ValueError(f'Invalid nonfinite value: {value!r}')
    return format(number, 'f')


def integer(value):
    value = numeric(value)
    if value is None:
        return None
    if Decimal(value) != Decimal(value).to_integral_value():
        raise ValueError(f'Fractional workforce count: {value}')
    return int(Decimal(value))


class Builder:
    def __init__(self, directory, geos):
        self.directory, self.geos = directory, geos
        self.canonical = defaultdict(set)
        self.indicators, self.workforce = [], []
        self.corrections, self.excluded, self.missing = [], [], Counter()
        self.sheet_counts = {}
        for _, row in self.raw_rows('tb', 'Annual', 7):
            code = 'PH' if normalise(row[1]) == 'PHILIPPINES' else short_code(row[0])
            if code not in geos:
                raise ValueError(f'TB reference geography absent: {code}')
            self.canonical[normalise(row[1])].add(code)

    def raw_rows(self, source, sheet, start):
        workbook = openpyxl.load_workbook(self.directory / FILES[source], read_only=True, data_only=True)
        try:
            for number, row in enumerate(workbook[sheet].iter_rows(min_row=start, values_only=True), start):
                raw = row[0] if row else None
                if raw is None and not (len(row) > 1 and normalise(row[1]) == 'PHILIPPINES'):
                    if len(row) > 2 and any(x is not None for x in row[2:]):
                        raise ValueError(f'Unkeyed data row: {source}/{sheet}/{number}')
                    continue
                if str(raw).startswith(('Source:', 'Legend:', 'Asterisk', 'Zero (')):
                    continue
                if str(raw).endswith('H'):
                    self.excluded.append({'source': source, 'sheet': sheet, 'row': number,
                                          'psgc': str(raw), 'reason': 'additional hospital, not an LGU'})
                    continue
                yield number, row
        finally:
            workbook.close()

    def rows(self, source, sheet, start):
        region, province, seen = None, None, set()
        for number, row in self.raw_rows(source, sheet, start):
            name = normalise(row[1])
            name = ALIASES.get(name, name)
            try:
                code = 'PH' if name == 'PHILIPPINES' else short_code(row[0])
            except ValueError:
                code = None
            candidates = self.canonical[name]
            if code not in candidates:
                scoped = [c for c in candidates if self.geos[c]['province_code'] == province]
                if len(scoped) != 1:
                    scoped = [c for c in candidates if self.geos[c]['region_code'] == region]
                if len(scoped) != 1 and len(candidates) == 1:
                    scoped = list(candidates)
                if len(scoped) != 1:
                    raise ValueError(f'Ambiguous geography: {source}/{sheet}/{number} {row[:2]} {candidates}')
                corrected = scoped[0]
                self.corrections.append({'source': source, 'sheet': sheet, 'row': number,
                                         'raw_psgc': str(row[0]), 'name': row[1],
                                         'from': code, 'to': corrected})
                code = corrected
            geo = self.geos[code]
            if geo['geo_level'] == 'region':
                region, province = code, None
            elif geo['geo_level'] == 'province':
                province = code
            if code in seen:
                raise ValueError(f'Duplicate geography: {source}/{sheet}/{code}')
            seen.add(code)
            yield number, row, {'geo_code': code, 'geo_level': geo['geo_level'],
                                'source_psgc': None if row[0] is None else str(row[0]).removesuffix('.0'),
                                'source_area_name': str(row[1]),
                                'source_file': FILES[source], 'source_sheet': sheet, 'source_row': number}
        self.sheet_counts[f'{source}/{sheet}'] = len(seen)

    def indicator(self, base, row, key, n, d=None, rate=None, breakdown='total'):
        def cell(column):
            value = row[column-1] if column and column <= len(row) else None
            if column and (value is None or isinstance(value, str)):
                self.missing[str(value)] += 1
            return numeric(value)
        numerator, denominator, percent = cell(n), cell(d), cell(rate)
        # A projected denominator alone is not evidence that services were reported.
        if numerator is None and percent is None:
            return
        self.indicators.append({**base, 'indicator_key': key, 'breakdown': breakdown,
                                'numerator_exact': numerator, 'denominator_exact': denominator,
                                'rate_pct': percent,
                                'over_100': key not in ('tb_notified', 'tb_dr_notified')
                                and percent is not None and Decimal(percent) > 100})

    def build(self):
        for _, row, base in self.rows('fic', 'Annual', 7):
            for key, n, d, rate in [('fic', 6, 3, 7), ('cic', 11, 8, 12)]:
                self.indicator(base, row, key, n, d, rate)
                for sex, col in [('male', n-2), ('female', n-1)]:
                    self.indicator(base, row, key, col, breakdown=sex)
        for source, dens, nums in [('anc4', [3, 4, 5, 6], [8, 10, 12, 14]),
                                   ('anc8', [15, 16, 17, 18], [28, 30, 32, 34])]:
            for _, row, base in self.rows(source, 'Annual', 7):
                for age, d, n in zip(['10-14', '15-19', '20-49', 'total'], dens, nums):
                    self.indicator(base, row, source, n, d, n+1, age)
        for source, keys, start in [('water', ['level1', 'level2', 'level3', 'basic', 'safely_managed'], 8),
                                    ('sanitation', ['septic', 'sewer', 'vip', 'basic', 'safely_managed'], 9)]:
            for _, row, base in self.rows(source, 'Qtr4', start):
                for index, key in enumerate(keys):
                    self.indicator(base, row, f'{source}_{key}', 4+2*index, 10 if index < 3 else 3, 5+2*index)
        for _, row, base in self.rows('tb', 'Annual', 7):
            for key, n, rate in [('tb_notified', 6, 7), ('tb_dr_notified', 10, 11), ('tb_presumptive_tested', 14, 15)]:
                self.indicator(base, row, key, n, 3, rate)
                for sex, col in [('male', n-2), ('female', n-1)]:
                    self.indicator(base, row, key, col, breakdown=sex)
        for _, row, base in self.rows('tb', 'TB-TPT', 7):
            self.indicator(base, row, 'tb_tpt', 12, 9, 13)
            for breakdown, n in [('male', 10), ('female', 11), ('0-14', 5), ('15+', 8)]:
                self.indicator(base, row, 'tb_tpt', n, breakdown=breakdown)
        for key, sheet in [('tb_tsr_dstb', 'TSR-DSTB'), ('tb_tsr_mdrtb', 'TSR-MDRTB')]:
            for _, row, base in self.rows('tb', sheet, 7):
                for age, n, d, rate in [('0-14', 7, 4, 8), ('15+', 13, 10, 14),
                                        ('unknown', 18, 15, None), ('total', 23, 20, 24)]:
                    self.indicator(base, row, key, n, d, rate, age)
                for sex, col in [('male', 21), ('female', 22)]:
                    self.indicator(base, row, key, col, breakdown=sex)
        for _, row, base in self.rows('demographics', 'Health Workers', 8):
            for index, cadre in enumerate(CADRES):
                start = 3+4*index
                counts = [integer(v) for v in row[start:start+3]]
                if all(v is None for v in counts):
                    continue
                self.workforce.append({**base, 'cadre': cadre, 'lgu_hired': counts[0],
                                       'doh_hired': counts[1], 'total': counts[2],
                                       'population_2025': integer(row[2]), 'households_2025': integer(row[35])})
        for records, key in [(self.indicators, 'indicator_key'), (self.workforce, 'cadre')]:
            unique = {(r['geo_code'], r[key], r.get('breakdown')) for r in records}
            if len(unique) != len(records):
                raise ValueError('Duplicate output rows')
        return self


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    parser.add_argument('--geography', type=Path, required=True)
    parser.add_argument('--retrieved', required=True)
    args = parser.parse_args()
    geos = json.loads(args.geography.read_text())
    builder = Builder(args.directory, geos).build()
    sources = [{'file': file, 'sha256': hashlib.sha256((args.directory/file).read_bytes()).hexdigest()}
               for file in FILES.values()]
    used = sorted({r['geo_code'] for r in builder.indicators + builder.workforce})
    qa = {'sheet_geographies': builder.sheet_counts, 'corrections': builder.corrections,
          'excluded': builder.excluded, 'missing_tokens': dict(builder.missing),
          'indicator_rows': len(builder.indicators), 'workforce_rows': len(builder.workforce),
          'by_indicator': dict(Counter(r['indicator_key'] for r in builder.indicators)),
          'negative_values': [r for r in builder.indicators if any(r[k] is not None and Decimal(r[k]) < 0
                              for k in ('numerator_exact', 'denominator_exact', 'rate_pct'))],
          'fractional_values': dict(Counter(r['indicator_key'] for r in builder.indicators
                                   if any(r[k] is not None and Decimal(r[k]) != Decimal(r[k]).to_integral_value()
                                          for k in ('numerator_exact', 'denominator_exact')))),
          'national_totals': [r for r in builder.indicators if r['geo_code'] == 'PH' and r['breakdown'] == 'total']}
    bundle = {'format': 'bhw-fhsis-2025-v1', 'dataset_slug': 'fhsis-2025', 'retrieved': args.retrieved,
              'sources': sources, 'geographies': [{k: geos[c][k] for k in ('geo_code', 'geo_level', 'geo_name', 'parent_code')} for c in used],
              'indicators': builder.indicators, 'workforce': builder.workforce, 'qa': qa}
    out = args.directory/'fhsis-load.json'
    out.write_text(json.dumps(bundle, ensure_ascii=False, separators=(',', ':')))
    (args.directory/'qa-report.json').write_text(json.dumps(qa, ensure_ascii=False, indent=2))
    digest = hashlib.sha256(out.read_bytes()).hexdigest()
    (args.directory/'fhsis-load.sha256').write_text(f'{digest}  fhsis-load.json\n')
    print(json.dumps({'indicator_rows': len(builder.indicators), 'workforce_rows': len(builder.workforce),
                      'corrections': len(builder.corrections), 'sha256': digest}))


if __name__ == '__main__':
    main()
