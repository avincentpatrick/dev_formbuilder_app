"""Offline district boundary candidate builder; never fetches data or publishes assets.

A checksummed review roster declares the complete expected leaf membership. Database
status=auto is not independent verification; the roster review is a separate prerequisite.
Inputs are normalized, exact PSGC identifiers and district slugs, never name matches.
"""
import argparse
from collections import Counter, defaultdict
import hashlib
import json
from pathlib import Path

import shapely
from shapely.errors import GEOSException
from shapely.geometry import mapping, shape
from shapely.ops import unary_union
from validate_map_boundaries import valid_polygon


def required_text(value, name):
    if not isinstance(value, str) or not value.strip():
        raise ValueError(f'{name} must be a nonempty string')
    return value


def pinned_json(root, entry):
    raw = (root / required_text(entry.get('path'), 'path')).read_bytes()
    digest = hashlib.sha256(raw).hexdigest()
    if entry.get('sha256') != digest:
        raise ValueError('Pinned SHA256 mismatch')
    return json.loads(raw)


def member_key(row):
    level = row.get('geo_level')
    code = row.get('geo_code')
    length = {'citymun': 7, 'barangay': 10}.get(level)
    if not length or not isinstance(code, str) or len(code) != length or not code.isascii() or not code.isdigit():
        raise ValueError('Expected exact normalized citymun/barangay string code')
    return level, code


def build(manifest, roster, members, sources):
    if not isinstance(manifest, dict) or manifest.get('schema_version') != 1:
        raise ValueError('Unsupported manifest schema')
    for field in ('release', 'boundary_vintage', 'review_ref'):
        required_text(manifest.get(field), field)
    if 'latest' in manifest['release'].lower():
        raise ValueError('Release must be immutable')
    congress = manifest.get('congress_no')
    if type(congress) is not int or congress <= 0:
        raise ValueError('Invalid Congress number')
    if not isinstance(roster, list) or not roster or not isinstance(members, list):
        raise ValueError('Expected nonempty district roster and membership array')
    districts = {}
    expected = {}
    for district in roster:
        code = required_text(district.get('district_code'), 'district_code')
        if code in districts:
            raise ValueError('Duplicate district roster entry')
        if district.get('congress_no') != congress or district.get('boundary_vintage') != manifest['boundary_vintage']:
            raise ValueError('Roster Congress/vintage mismatch')
        required_text(district.get('review_ref'), 'district review_ref')
        required_text(district.get('district_name'), 'district_name')
        if district.get('membership_complete') is not True:
            raise ValueError('Roster must explicitly attest complete expected membership')
        leaves = district.get('expected_members')
        if not isinstance(leaves, list) or not leaves:
            raise ValueError('Expected nonempty reviewed leaf roster')
        keys = [member_key(row) for row in leaves]
        if len(keys) != len(set(keys)):
            raise ValueError('Duplicate expected member')
        districts[code] = district
        expected[code] = set(keys)

    reasons = {code: set() for code in districts}
    actual = defaultdict(list)
    for row in members:
        code = row.get('district_code')
        if code not in districts:
            raise ValueError('Membership names a district outside the reviewed roster')
        key = member_key(row)
        actual[code].append(key)
        if row.get('status') not in ('auto', 'approved') or 'superseded_by' not in row or row['superseded_by'] is not None:
            reasons[code].add('inactive-or-unaccepted-membership')
        if not isinstance(row.get('source_ref'), str) or not row['source_ref'].strip():
            reasons[code].add('missing-membership-provenance')
        # Do not describe single_source rows as independently verified.
        if row.get('corroboration') not in ('single_source', 'corroborated'):
            reasons[code].add('unresolved-corroboration')
    owners = defaultdict(set)
    for code, keys in expected.items():
        for key in keys:
            owners[key].add(code)
    for codes in owners.values():
        if len(codes) > 1:
            for code in codes:
                reasons[code].add('member-assigned-to-multiple-districts')

    geometries = {}
    invalid = set()
    provenance = []
    for entry, collection in sources:
        level = entry.get('level')
        if level not in ('citymun', 'barangay'):
            raise ValueError('District sources must be leaf polygons')
        for field in ('release', 'source', 'license', 'sha256'):
            required_text(entry.get(field), f'source {field}')
        if 'latest' in entry['release'].lower() or '/latest/' in entry['source'].lower():
            raise ValueError('Source must be pinned, not latest')
        if entry.get('boundary_vintage') != manifest['boundary_vintage']:
            raise ValueError('Source boundary vintage mismatch')
        if collection.get('type') != 'FeatureCollection' or not isinstance(collection.get('features'), list):
            raise ValueError('Expected source FeatureCollection')
        provenance.append({k: entry[k] for k in ('level', 'release', 'source', 'license', 'sha256', 'boundary_vintage')})
        for feature in collection['features']:
            key = member_key({'geo_level': level, 'geo_code': feature.get('properties', {}).get('geo_code')})
            if key in geometries or key in invalid:
                invalid.add(key)
                geometries.pop(key, None)
                continue
            geometry = feature.get('geometry')
            if not valid_polygon(geometry):
                invalid.add(key)
                continue
            geom = shape(geometry)
            if geom.is_empty or not geom.is_valid or geom.area <= 0:
                invalid.add(key)
                continue
            geometries[key] = geom

    candidates = {}
    for code, keys in expected.items():
        if Counter(actual[code]) != Counter(keys):
            reasons[code].add('membership-does-not-match-reviewed-roster')
        if keys & invalid:
            reasons[code].add('invalid-or-duplicate-member-geometry')
        if keys - geometries.keys() - invalid:
            reasons[code].add('missing-member-geometry')
        if reasons[code]:
            continue
        geoms = [geometries[key] for key in sorted(keys)]
        # Strict: overlapping municipal/barangay grains must not double-count land.
        if any(a.intersection(b).area > 0 for i, a in enumerate(geoms) for b in geoms[i + 1:]):
            reasons[code].add('overlapping-member-geometries')
            continue
        dissolved = unary_union(geoms)
        if dissolved.is_empty or not dissolved.is_valid or dissolved.geom_type not in ('Polygon', 'MultiPolygon'):
            reasons[code].add('invalid-dissolve')
            continue
        candidates[code] = dissolved
    items = sorted(candidates.items())
    for i, (code, geom) in enumerate(items):
        for other, other_geom in items[i + 1:]:
            if geom.intersection(other_geom).area > 0:
                reasons[code].add('overlapping-district-geometries')
                reasons[other].add('overlapping-district-geometries')

    features = []
    outcomes = []
    for code in sorted(districts):
        complete = not reasons[code]
        outcomes.append({'district_code': code, 'geometry_complete': complete,
                         'expected_members': len(expected[code]), 'observed_members': len(actual[code]),
                         'missing_members': [list(k) for k in sorted(expected[code] - set(actual[code]))],
                         'missing_geometry': [list(k) for k in sorted(expected[code] - geometries.keys())],
                         'reasons': sorted(reasons[code])})
        if complete:
            features.append({'type': 'Feature', 'properties': {
                'geo_code': code, 'district_name': districts[code]['district_name'],
                'congress_no': congress, 'geometry_complete': True,
                'member_count': len(expected[code]), 'boundary_release': manifest['release'],
                'corroboration': sorted({row['corroboration'] for row in members if row['district_code'] == code}),
            }, 'geometry': mapping(candidates[code])})
    report = {'release': manifest['release'], 'boundary_vintage': manifest['boundary_vintage'],
              'congress_no': congress, 'review_ref': manifest['review_ref'],
              'shapely_version': shapely.__version__, 'sources': provenance,
              'input_hashes': {key: manifest[key]['sha256'] for key in ('roster', 'members')},
              'complete': all(not value for value in reasons.values()), 'districts': outcomes,
              'scope': 'Supplied reviewed roster only; not a nationwide coverage certificate.',
              'membership_verification': 'Review roster required; single-source assignments are not independently verified.',
              'publication': 'Candidate only: historical crosswalk, cartographic review and hosting acceptance remain required.'}
    return {'type': 'FeatureCollection', 'features': features}, report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--report', required=True)
    args = parser.parse_args()
    try:
        path = Path(args.manifest)
        manifest_raw = path.read_bytes()
        manifest = json.loads(manifest_raw)
        roster = pinned_json(path.parent, manifest['roster'])
        members = pinned_json(path.parent, manifest['members'])
        sources = [(entry, pinned_json(path.parent, entry)) for entry in manifest['sources']]
        collection, report = build(manifest, roster, members, sources)
        outputs = [Path(args.output).resolve(), Path(args.report).resolve()]
        if outputs[0] == outputs[1] or any(p.exists() for p in outputs):
            raise ValueError('Use distinct new output paths; existing artifacts are never overwritten')
        report['manifest_sha256'] = hashlib.sha256(manifest_raw).hexdigest()
        report['output_sha256'] = hashlib.sha256(json.dumps(collection, sort_keys=True).encode()).hexdigest()
        with outputs[0].open('x') as output:
            output.write(json.dumps(collection, sort_keys=True))
        with outputs[1].open('x') as output:
            output.write(json.dumps(report, indent=2) + '\n')
    except (ValueError, KeyError, TypeError, AttributeError, OSError, GEOSException) as error:
        parser.error(str(error))
    if not report['complete']:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
