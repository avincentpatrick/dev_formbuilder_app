"""Offline nationwide supplier inventory against a permitted dim_geo extract.

This is candidate assessment, never publication or a historical crosswalk. Numeric
supplier PSGC identifiers are explicitly zero-padded to ten digits (documented supplier
format); no prefix substitution, fuzzy matching, split redistribution or shape repair.
"""
import argparse
from collections import Counter, defaultdict
import hashlib
import json
from pathlib import Path
import re
import zipfile

import shapely
from shapely.errors import GEOSException
from shapely.geometry import shape
from shapely.strtree import STRtree
from shapely.validation import explain_validity
from validate_map_boundaries import valid_polygon


SOURCE_PATTERN = re.compile(r'^geojson/municities/hires/bgysubmuns-municity-\d+\.0\.1\.json$')


def supplier_code(value):
    if type(value) is int:
        value = str(value)
    if not isinstance(value, str) or not re.fullmatch(r'[0-9]{1,10}', value) or int(value) == 0:
        raise ValueError('Supplier PSGC must be an integer or exact digit string, at most ten digits')
    return value.zfill(10)


def reference_index(rows):
    result = {}
    for row in rows:
        if row.get('geo_level') != 'barangay':
            continue
        code = row.get('geo_code')
        if not isinstance(code, str) or not re.fullmatch(r'[0-9]{10}', code):
            raise ValueError('Reference barangay codes must already be exact ten-digit strings')
        if code in result:
            raise ValueError('Duplicate reference barangay code')
        for field, length in [('citymun_code', 7), ('region_code', 2), ('province_code', 5)]:
            if not isinstance(row.get(field), str) or not re.fullmatch(r'[0-9]{%d}' % length, row[field]):
                raise ValueError(f'Invalid reference {field}')
        result[code] = row
    if not result:
        raise ValueError('No reference barangays')
    return result


def assess(features, reference):
    expected = reference_index(reference)
    records = defaultdict(list)
    rejected = []
    other_levels = Counter()
    untyped = []
    hierarchy_adaptations = []
    for location, feature in features:
        props = feature.get('properties') or {}
        if props.get('geo_level') != 'Bgy':
            other_levels[str(props.get('geo_level'))] += 1
            if props.get('geo_level') is None:
                untyped.append(location)
            continue
        try:
            code = supplier_code(props.get('adm4_psgc'))
            parent = supplier_code(props.get('adm3_psgc'))
            if not parent.endswith('000'):
                raise ValueError('Municipal supplier code must end in 000')
        except ValueError as error:
            rejected.append({'location': location, 'reason': str(error)})
            continue
        normalized_parent = parent[:7]
        # Supplier README explicitly models Manila submunicipalities by this code rule.
        # Preserve the raw whole-city parent; this is hierarchy normalization, not a vintage transfer.
        if parent == '1380600000' and code.startswith('13806'):
            normalized_parent = code[:7]
            hierarchy_adaptations.append({'code': code, 'source_parent': parent[:7], 'normalized_parent': normalized_parent})
        geometry = feature.get('geometry')
        reason = None
        geom = None
        if geometry is None:
            reason = 'null-geometry'
        elif not valid_polygon(geometry):
            reason = 'invalid-polygon-structure'
        else:
            candidate = shape(geometry)
            if not candidate.is_valid or candidate.is_empty or candidate.area <= 0:
                reason = explain_validity(candidate) if not candidate.is_valid else 'empty-or-zero-area'
            else:
                geom = candidate
        records[code].append({'parent': normalized_parent, 'reason': reason, 'geometry': geom,
                              'geometry_origin': props.get('geom_src', 'unspecified')})

    duplicates = sorted(code for code, values in records.items() if len(values) != 1)
    missing = sorted(expected.keys() - records.keys())
    unmatched = sorted(records.keys() - expected.keys())
    matched = sorted(records.keys() & expected.keys())
    parent_mismatch = []
    invalid = []
    origins = Counter()
    for code, values in sorted(records.items()):
        for item in values:
            origins[str(item['geometry_origin'])] += 1
            if item['reason']:
                invalid.append({'code': code, 'reason': item['reason']})
        if len(values) != 1:
            continue
        item = values[0]
        if code in expected and item['parent'] != expected[code]['citymun_code']:
            parent_mismatch.append({'code': code, 'source_parent': item['parent'], 'reference_parent': expected[code]['citymun_code']})

    # Check all source polygons, including unmatched codes: a match cannot hide a neighbour's overlap.
    geometry_entries = [(code, item['geometry']) for code in sorted(records)
                        for item in records[code] if item['geometry'] is not None]
    codes = [code for code, _ in geometry_entries]
    geoms = [geom for _, geom in geometry_entries]
    tree = STRtree(geoms)
    overlaps = []
    overlap_fractions = []
    for i, geom in enumerate(geoms):
        for j in tree.query(geom, predicate='intersects'):
            if j > i:
                area = geom.intersection(geoms[j]).area
                if area > 0:
                    overlaps.append([codes[i], codes[j]])
                    overlap_fractions.append(area / min(geom.area, geoms[j].area))
    overlap_codes = {code for pair in overlaps for code in pair}
    invalid_codes = {row['code'] for row in invalid}
    mismatch_codes = {row['code'] for row in parent_mismatch}
    usable = set(matched) - set(duplicates) - invalid_codes - mismatch_codes - overlap_codes

    def counts(subset):
        subset = set(subset)
        return {'reference': len(subset), 'same_code': len(subset & set(matched)),
                'missing': len(subset & set(missing)), 'invalid_geometry': len(subset & invalid_codes),
                'parent_mismatch': len(subset & mismatch_codes), 'overlap': len(subset & overlap_codes),
                'candidate_usable': len(subset & usable)}

    regions = sorted({row['region_code'] for row in expected.values()})
    huc_provinces = {row['geo_code'] for row in reference if row.get('geo_level') == 'province' and '(HUC)' in row.get('geo_name', '')}
    special = {
        'NCR': lambda row: row['region_code'] == '13',
        'Manila': lambda row: row['geo_code'].startswith('13806'),
        'NIR': lambda row: row['region_code'] == '18',
        'Sulu': lambda row: row['province_code'].endswith('066'),
        'BARMM': lambda row: row['region_code'] == '19',
        'SGA': lambda row: row['province_code'] == '19999',
        'HUC_parent': lambda row: row['province_code'] in huc_provinces,
    }
    return {
        'summary': {**counts(expected), 'source_barangay_features': sum(map(len, records.values())),
                    'source_unique_codes': len(records), 'unmatched_source': len(unmatched),
                    'duplicate_source_codes': len(duplicates), 'invalid_source_features': len(invalid),
                    'positive_area_overlap_pairs': len(overlaps)},
        'by_reference_region': {region: counts(code for code, row in expected.items() if row['region_code'] == region) for region in regions},
        'special_cases': {name: counts(code for code, row in expected.items() if predicate(row)) for name, predicate in special.items()},
        'missing_reference_codes': missing, 'unmatched_source_codes': unmatched,
        'duplicate_source_codes': duplicates, 'parent_mismatches': parent_mismatch,
        'invalid_source_geometry': invalid, 'positive_area_overlaps': overlaps,
        'overlap_diagnostics': {'max_fraction_of_smaller_polygon': max(overlap_fractions, default=0),
                                'pairs_above_one_millionth_of_smaller_polygon': sum(f > 0.000001 for f in overlap_fractions),
                                'note': 'Diagnostic only; no positive-area overlap is silently accepted.'},
        'hierarchy_adaptations': hierarchy_adaptations,
        'untyped_source_features': untyped,
        'rejected_identifiers': rejected, 'non_barangay_features': dict(other_levels),
        'source_geometry_origins': dict(origins),
        'candidate_usable_codes': sorted(usable),
        'historical_compatibility': 'not-established; same-code matches are candidates, not accepted historical joins',
        'publication_ready': False,
    }


def digest(path):
    with Path(path).open('rb') as file:
        return hashlib.file_digest(file, 'sha256').hexdigest()


def archive_features(archive):
    with zipfile.ZipFile(archive) as source:
        names = sorted(name for name in source.namelist() if SOURCE_PATTERN.fullmatch(name))
        if not names or len(names) != len(set(names)):
            raise ValueError('Missing or duplicate high-resolution municipal source files')
        for name in names:
            collection = json.loads(source.read(name))
            if collection.get('type') != 'FeatureCollection' or not isinstance(collection.get('features'), list):
                raise ValueError('Invalid source FeatureCollection')
            for index, feature in enumerate(collection['features']):
                yield f'{name}:{index}', feature


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('archive', 'sha256', 'reference', 'reference-sha256', 'release', 'source', 'reference-description', 'output'):
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    try:
        if 'latest' in args.release.lower() or '/latest/' in args.source.lower():
            raise ValueError('Use a pinned source release URL')
        if digest(args.archive) != args.sha256 or digest(args.reference) != args.reference_sha256:
            raise ValueError('Pinned archive/reference checksum mismatch')
        if Path(args.output).exists():
            raise ValueError('Use a new output path; existing reports are not overwritten')
        report = assess(archive_features(args.archive), json.loads(Path(args.reference).read_text()))
        report['provenance'] = {'release': args.release, 'source': args.source, 'archive_sha256': args.sha256,
                                'reference_sha256': args.reference_sha256, 'reference_description': args.reference_description,
                                'shapely_version': shapely.__version__,
                                'attribution': 'James Faeldon (MIT); underlying NAMRIA and PSA boundaries through OCHA COD-AB (CC BY 3.0 IGO).',
                                'normalization': 'Supplier integer PSGC padded to ten digits; no historical remapping.'}
        with Path(args.output).open('x') as output:
            json.dump(report, output, indent=2)
            output.write('\n')
        print(json.dumps(report['summary']))
    except (OSError, ValueError, KeyError, TypeError, AttributeError, GEOSException, zipfile.BadZipFile) as error:
        parser.error(str(error))
    # Assessment is deliberately distinct from publication acceptance.


if __name__ == '__main__':
    main()
