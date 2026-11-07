"""Offline release gate. Inputs are a pinned GeoJSON and a permitted geography extract.
Never changes codes by padding/truncation: an explicit reviewed crosswalk is required.
Reports contain geography identifiers only, never dataset observations.
"""
import argparse
import hashlib
import json
from collections import Counter
from pathlib import Path


def valid_polygon(geometry):
    if not isinstance(geometry, dict) or geometry.get('type') not in ('Polygon', 'MultiPolygon'):
        return False
    polygons = [geometry.get('coordinates')] if geometry['type'] == 'Polygon' else geometry.get('coordinates')
    if not isinstance(polygons, list) or not polygons:
        return False
    for polygon in polygons:
        if not isinstance(polygon, list) or not polygon:
            return False
        for ring in polygon:
            if not isinstance(ring, list) or len(ring) < 4 or ring[0] != ring[-1]:
                return False
            for position in ring:
                if not isinstance(position, list) or len(position) < 2:
                    return False
                x, y = position[:2]
                if type(x) not in (int, float) or type(y) not in (int, float) or not (-180 <= x <= 180 and -90 <= y <= 90):
                    return False
    return True


def reconcile(collection, reference, crosswalk, property_name):
    if collection.get('type') != 'FeatureCollection' or not isinstance(collection.get('features'), list):
        raise ValueError('Expected FeatureCollection')
    if not isinstance(reference, list) or not all(isinstance(code, str) and code for code in reference):
        raise ValueError('Reference must be an array of string codes; preserve leading zeros')
    if not isinstance(crosswalk, dict) or not all(isinstance(k, str) and isinstance(v, list) and all(isinstance(c, str) and c for c in v) for k, v in crosswalk.items()):
        raise ValueError('Crosswalk must map exact source strings to arrays of reference strings')
    expected = set(reference)
    codes = []
    unmatched, ambiguous, nulls, invalid = [], [], [], []
    for index, feature in enumerate(collection['features']):
        raw = feature.get('properties', {}).get(property_name)
        # Numeric supplier identifiers must first be normalized by an evidenced adapter.
        targets = crosswalk.get(raw, []) if isinstance(raw, str) else []
        label = raw if isinstance(raw, str) else f'feature:{index}'
        if len(targets) != 1:
            (ambiguous if len(targets) > 1 else unmatched).append(label)
        elif targets[0] not in expected:
            unmatched.append(label)
        else:
            codes.append(targets[0])
        if feature.get('geometry') is None:
            nulls.append(label)
        elif not valid_polygon(feature['geometry']):
            invalid.append(label)
    duplicates = sorted(c for c, n in Counter(codes).items() if n > 1)
    return {
        'reference_count': len(reference), 'feature_count': len(collection['features']),
        'matched': sorted(set(codes)), 'missing': sorted(expected - set(codes)),
        'unmatched': unmatched, 'ambiguous': ambiguous, 'duplicate_codes': duplicates,
        'duplicate_reference_codes': sorted(c for c, n in Counter(reference).items() if n > 1),
        'null_geometry': nulls, 'invalid_geometry': invalid,
        'topology': 'not-tested', 'historical_compatibility': 'requires-reviewed-crosswalk',
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('geojson', 'reference', 'crosswalk', 'sha256', 'release', 'level', 'source', 'license', 'output'):
        parser.add_argument('--' + name, required=True)
    parser.add_argument('--property', default='geo_code')
    args = parser.parse_args()
    raw = Path(args.geojson).read_bytes()
    digest = hashlib.sha256(raw).hexdigest()
    if digest != args.sha256.lower():
        parser.error('Pinned SHA256 mismatch')
    if 'latest' in args.release.lower() or '/latest/' in args.source.lower():
        parser.error('Pin an immutable supplier release, not latest')
    report = reconcile(json.loads(raw), json.loads(Path(args.reference).read_text()), json.loads(Path(args.crosswalk).read_text()), args.property)
    report.update(release=args.release, level=args.level, source=args.source, license=args.license, sha256=digest)
    Path(args.output).write_text(json.dumps(report, indent=2) + '\n')
    if any(report[k] for k in ('missing', 'unmatched', 'ambiguous', 'duplicate_codes', 'duplicate_reference_codes', 'null_geometry', 'invalid_geometry')):
        raise SystemExit(1)


if __name__ == '__main__':
    main()
