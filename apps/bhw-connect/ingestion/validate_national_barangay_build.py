"""Fail-closed retention/topology checks for a nationwide barangay build.
Hashes bind the unsimplified source, geographic reference and generated candidate.
No repair, code remapping or geometry publication is performed here.
"""
import argparse
from collections import Counter
import json
from pathlib import Path

import shapefile
from shapely.errors import GEOSException
from shapely.geometry import shape
from shapely.validation import explain_validity
from reconcile_barangay_candidate import assess, digest, supplier_code


def geometry_inventory(geometry):
    if geometry is None:
        return {'components': 0, 'holes': 0, 'valid': False, 'reason': 'null-geometry'}
    geom = shape(geometry)
    if geom.geom_type not in ('Polygon', 'MultiPolygon') or geom.is_empty:
        return {'components': 0, 'holes': 0, 'valid': False, 'reason': 'not-a-nonempty-polygon'}
    polygons = [geom] if geom.geom_type == 'Polygon' else list(geom.geoms)
    return {'components': len(polygons), 'holes': sum(len(p.interiors) for p in polygons),
            'valid': bool(geom.is_valid), 'reason': None if geom.is_valid else explain_validity(geom)}


def source_inventory(path):
    rows = {}
    with shapefile.Reader(str(path)) as reader:
        for index, record in enumerate(reader.iterRecords()):
            props = record.as_dict()
            if props.get('geo_level') != 'Bgy':
                continue
            code = supplier_code(props.get('adm4_psgc'))
            if code in rows:
                raise ValueError('Duplicate upstream code; cannot certify retention')
            raw = reader.shape(index)
            geometry = None if raw.shapeType == shapefile.NULL else raw.__geo_interface__
            rows[code] = geometry_inventory(geometry)
    if not rows:
        raise ValueError('No upstream barangays')
    return rows


def retention(source, features):
    values = {}
    duplicates = set()
    unexpected_levels = Counter()
    for feature in features:
        props = feature.get('properties') or {}
        if props.get('geo_level') != 'Bgy':
            unexpected_levels[str(props.get('geo_level'))] += 1
            continue
        code = supplier_code(props.get('adm4_psgc'))
        if code in values:
            duplicates.add(code)
        values[code] = geometry_inventory(feature.get('geometry'))
    lost_components = []
    lost_holes = []
    gained_components = []
    gained_holes = []
    for code in sorted(source.keys() & values.keys()):
        for field, lost, gained in [('components', lost_components, gained_components), ('holes', lost_holes, gained_holes)]:
            if values[code][field] != source[code][field]:
                output = lost if values[code][field] < source[code][field] else gained
                output.append({'code': code, 'source': source[code][field], 'candidate': values[code][field]})
    return {
        'source_records': len(source), 'candidate_records': len(values),
        'missing_source_codes': sorted(source.keys() - values.keys()),
        'unexpected_candidate_codes': sorted(values.keys() - source.keys()),
        'duplicate_candidate_codes': sorted(duplicates), 'non_barangay_features': dict(unexpected_levels),
        'source_invalid_codes': sorted(c for c,v in source.items() if not v['valid']),
        'candidate_invalid_codes': sorted(c for c,v in values.items() if not v['valid']),
        'newly_invalid_codes': sorted(c for c in source.keys() & values.keys() if source[c]['valid'] and not values[c]['valid']),
        'lost_components': lost_components, 'lost_holes': lost_holes,
        'gained_components': gained_components, 'gained_holes': gained_holes,
        'source_components': sum(v['components'] for v in source.values()),
        'candidate_components': sum(v['components'] for v in values.values()),
        'source_holes': sum(v['holes'] for v in source.values()),
        'candidate_holes': sum(v['holes'] for v in values.values()),
    }


def validate(source, features, reference):
    retained = retention(source, features)
    reconciliation = assess([(str(i),f) for i,f in enumerate(features)],reference)
    blockers = [key for key in ('missing_source_codes','unexpected_candidate_codes','duplicate_candidate_codes',
                                'non_barangay_features','source_invalid_codes','candidate_invalid_codes','lost_components','lost_holes','gained_components','gained_holes') if retained[key]]
    blockers += [key for key in ('missing_reference_codes','unmatched_source_codes','parent_mismatches',
                                 'positive_area_overlaps','rejected_identifiers') if reconciliation[key]]
    return {'retention':retained, 'reconciliation':reconciliation, 'blocking_checks':blockers,
            'automated_checks_pass':not blockers, 'publication_ready':False,
            'scope':'Nationwide candidate gate only. Historical geography, licensing, cartographic review and delivery acceptance remain separate.'}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    for name in ('source','source-sha256','reference','reference-sha256','candidate','candidate-sha256','output'):
        parser.add_argument('--'+name,required=True)
    args=parser.parse_args()
    try:
        for name in ('source','reference','candidate'):
            if digest(getattr(args,name)) != getattr(args,name+'_sha256'):
                raise ValueError(f'Pinned {name} checksum mismatch')
        if Path(args.output).exists():
            raise ValueError('Use a new report path')
        collection=json.loads(Path(args.candidate).read_text())
        if collection.get('type')!='FeatureCollection' or not isinstance(collection.get('features'),list):
            raise ValueError('Expected candidate FeatureCollection')
        report=validate(source_inventory(args.source),collection['features'],json.loads(Path(args.reference).read_text()))
        report['provenance']={name+'_sha256':getattr(args,name+'_sha256') for name in ('source','reference','candidate')}
        with Path(args.output).open('x') as out:
            json.dump(report,out,indent=2);out.write('\n')
        print(json.dumps({'blocking_checks':report['blocking_checks'], 'summary':report['reconciliation']['summary'],
                          'retention':{k:(len(v) if isinstance(v,list) else v) for k,v in report['retention'].items()}}))
    except (ValueError,TypeError,KeyError,AttributeError,OSError,GEOSException,shapefile.ShapefileException) as error:
        parser.error(str(error))
    if not report['automated_checks_pass']:
        raise SystemExit(1)


if __name__=='__main__':main()
