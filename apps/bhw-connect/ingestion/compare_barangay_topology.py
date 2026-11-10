"""Compare reported candidate overlaps with a pinned unsimplified shapefile source.

No reprojection, snapping, repair or geometry publication. Both upstream polygons
are evaluated in their common native CRS; results describe pair relationships, not
cross-CRS area differences. Non-overlap upstream does not by itself identify which
export/reprojection/simplification step introduced a downstream overlap.
"""
import argparse
from collections import Counter, defaultdict
import hashlib
import json
import math
from pathlib import Path
import zipfile

import shapefile
import shapely
from shapely.errors import GEOSException
from shapely.geometry import shape
from reconcile_barangay_candidate import digest, supplier_code


def pair_relationship(left, right):
    if left is None or right is None:
        return 'unresolved-source-geometry'
    for geom in (left, right):
        if geom.geom_type not in ('Polygon', 'MultiPolygon') or geom.is_empty or not geom.is_valid or not all(math.isfinite(x) for x in geom.bounds):
            return 'unresolved-source-geometry'
    intersection = left.intersection(right)
    if intersection.area > 0:
        return 'overlap-present-upstream'
    return 'touching-upstream' if not intersection.is_empty else 'disjoint-upstream'


def overlap_pairs(report):
    pairs = report.get('positive_area_overlaps')
    if not isinstance(pairs, list):
        raise ValueError('Expected detailed candidate overlap pairs')
    result = []
    seen = set()
    for pair in pairs:
        if not isinstance(pair, list) or len(pair) != 2 or any(not isinstance(c, str) or supplier_code(c) != c for c in pair):
            raise ValueError('Expected two normalized ten-digit codes per pair')
        key = tuple(sorted(pair))
        if key in seen:
            raise ValueError('Duplicate candidate overlap pair')
        seen.add(key)
        result.append(key)
    return sorted(result)


def read_upstream(path, wanted):
    records = defaultdict(list)
    with zipfile.ZipFile(path) as archive:
        projections = [n for n in archive.namelist() if n.lower().endswith('.prj')]
        if len(projections) != 1:
            raise ValueError('Expected one source CRS definition')
        crs = archive.read(projections[0]).decode('utf-8')
        if not crs.strip():
            raise ValueError('Empty source CRS')
    with shapefile.Reader(str(path)) as reader:
        for i, record in enumerate(reader.iterRecords()):
            props = record.as_dict()
            if props.get('geo_level') != 'Bgy':
                continue
            code = supplier_code(props.get('adm4_psgc'))
            if code not in wanted:
                continue
            source_shape = reader.shape(i)
            geom = None if source_shape.shapeType == shapefile.NULL else shape(source_shape.__geo_interface__)
            records[code].append({'geometry': geom, 'parent': supplier_code(props.get('adm3_psgc')),
                                  'origin': props.get('geom_src')})
    return records, crs


def compare(pairs, records):
    details = []
    counts = Counter()
    by_parent_scope = defaultdict(Counter)
    for left, right in pairs:
        a = records.get(left, [])
        b = records.get(right, [])
        if len(a) != 1 or len(b) != 1:
            status = 'missing-or-duplicate-upstream-code'
            scope = 'unknown'
        else:
            status = pair_relationship(a[0]['geometry'], b[0]['geometry'])
            scope = 'same-source-municipality' if a[0]['parent'] == b[0]['parent'] else 'different-source-municipalities'
        counts[status] += 1
        by_parent_scope[scope][status] += 1
        details.append({'codes': [left, right], 'relationship': status, 'parent_scope': scope})
    return {'candidate_overlap_pairs': len(pairs), 'relationships': dict(counts),
            'by_parent_scope': {k: dict(v) for k, v in sorted(by_parent_scope.items())},
            'pair_details': details, 'publication_ready': False,
            'scope': 'Only previously reported downstream overlap pairs, not a nationwide upstream topology certification.',
            'interpretation': 'Touching/disjoint upstream identifies an export-pipeline issue to investigate, not permission to snap or clip polygons.'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('source', 'source-sha256', 'source-url', 'source-release', 'candidate-report', 'candidate-report-sha256', 'output'):
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    try:
        if 'latest' in args.source_release.lower() or '/latest/' in args.source_url.lower():
            raise ValueError('Pin source release and URL')
        if digest(args.source) != args.source_sha256 or digest(args.candidate_report) != args.candidate_report_sha256:
            raise ValueError('Pinned source/report checksum mismatch')
        if Path(args.output).exists():
            raise ValueError('Use a new report path')
        candidate = json.loads(Path(args.candidate_report).read_text())
        pairs = overlap_pairs(candidate)
        records, crs = read_upstream(args.source, {c for pair in pairs for c in pair})
        report = compare(pairs, records)
        report['provenance'] = {'source_release': args.source_release, 'source_url': args.source_url,
            'source_sha256': args.source_sha256, 'candidate_report_sha256': args.candidate_report_sha256,
            'candidate_provenance': candidate.get('provenance'), 'source_crs_wkt': crs,
            'source_crs_sha256': hashlib.sha256(crs.encode()).hexdigest(),
            'shapely_version': shapely.__version__, 'pyshp_version': shapefile.__version__}
        with Path(args.output).open('x') as out:
            json.dump(report, out, indent=2)
            out.write('\n')
        print(json.dumps({k: report[k] for k in ('candidate_overlap_pairs', 'relationships', 'by_parent_scope')}))
    except (OSError, ValueError, KeyError, TypeError, GEOSException, shapefile.ShapefileException, zipfile.BadZipFile) as error:
        parser.error(str(error))


if __name__ == '__main__':
    main()
