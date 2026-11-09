"""Private per-municipality candidate packages with complete reference coverage metadata.
This partitions already jointly built geometry; it never simplifies, repairs or publishes.
"""
import argparse
from collections import Counter, defaultdict
import json
from pathlib import Path
import re
import statistics

from shapely.geometry import shape
from reconcile_barangay_candidate import digest, reference_index, supplier_code, valid_polygon, assess

ATTRIBUTION = 'James Faeldon (MIT); underlying NAMRIA and PSA boundaries through OCHA COD-AB (CC BY 3.0 IGO). Retain source notices and identify adaptations.'
PRIORITY = ['no-matched-source-code','source-geometry-unavailable','candidate-geometry-unavailable',
            'overlap-conflict','parent-mismatch','duplicate-code','component-change','hole-change','unexpected-source-code']


def exclusion_reasons(report):
    reasons=defaultdict(set)
    retained=report['retention']; reconciled=report['reconciliation']
    recognized={'missing_source_codes','unexpected_candidate_codes','duplicate_candidate_codes','non_barangay_features',
                'source_invalid_codes','candidate_invalid_codes','lost_components','lost_holes','gained_components','gained_holes',
                'missing_reference_codes','unmatched_source_codes','parent_mismatches','positive_area_overlaps','rejected_identifiers'}
    if set(report['blocking_checks'])-recognized:raise ValueError('Unknown validation blocker; do not silently ignore it')
    if retained['non_barangay_features'] or reconciled['rejected_identifiers']:
        raise ValueError('Unassignable feature errors must be resolved before packaging')
    for field,reason in [('missing_reference_codes','no-matched-source-code'),('unmatched_source_codes','unexpected-source-code'),('duplicate_source_codes','duplicate-code')]:
        for code in reconciled[field]:reasons[code].add(reason)
    for field,reason in [('source_invalid_codes','source-geometry-unavailable'),('candidate_invalid_codes','candidate-geometry-unavailable'),('duplicate_candidate_codes','duplicate-code'),('unexpected_candidate_codes','unexpected-source-code')]:
        for code in retained[field]:reasons[code].add(reason)
    for field,reason in [('lost_components','component-change'),('gained_components','component-change'),('lost_holes','hole-change'),('gained_holes','hole-change')]:
        for row in retained[field]:reasons[row['code']].add(reason)
    for row in reconciled['parent_mismatches']:reasons[row['code']].add('parent-mismatch')
    for pair in reconciled['positive_area_overlaps']:
        if len(pair)!=2:raise ValueError('Invalid overlap pair')
        for code in pair:reasons[code].add('overlap-conflict')
    return reasons


def partition(features, reference, report):
    expected=reference_index(reference)
    city_rows=[r for r in reference if r.get('geo_level')=='citymun']
    cities=[r['geo_code'] for r in city_rows]
    if len(cities)!=len(set(cities)) or not cities or any(not isinstance(c,str) or not re.fullmatch(r'[0-9]{7}',c) for c in cities):
        raise ValueError('Reference must include unique seven-digit municipality codes')
    if any(row['citymun_code'] not in cities for row in expected.values()):
        raise ValueError('Reference barangay has no declared municipality')
    reasons=exclusion_reasons(report)
    index=defaultdict(list)
    for feature in features:
        props=feature.get('properties') or {}
        if props.get('geo_level')!='Bgy':raise ValueError('Non-barangay candidate feature')
        index[supplier_code(props.get('adm4_psgc'))].append(feature)
    shards={city:[] for city in sorted(cities)}
    coverage=[]
    for code,row in sorted(expected.items()):
        matches=index.get(code,[])
        why=set(reasons.get(code,()))
        if not matches:why.add('no-matched-source-code')
        elif len(matches)>1:why.add('duplicate-code')
        else:
            feature=matches[0];geometry=feature.get('geometry')
            if not valid_polygon(geometry) or not shape(geometry).is_valid:
                why.add('candidate-geometry-unavailable')
            parent=supplier_code(feature['properties'].get('adm3_psgc'))
            normalized_parent=code[:7] if parent=='1380600000' and code.startswith('13806') else parent[:7]
            if not parent.endswith('000') or normalized_parent!=row['citymun_code']:why.add('parent-mismatch')
        state='withheld' if why else 'candidate-only'
        primary=next((reason for reason in PRIORITY if reason in why),None)
        coverage.append({'geo_code':code,'citymun_code':row['citymun_code'],'state':state,
                         'primary_reason':primary,'reasons':sorted(why)})
        if not why:
            original=matches[0]
            shards[row['citymun_code']].append({'type':'Feature','properties':{
                'geo_code':code,'source_code':code,'geometry_origin':original['properties'].get('geom_src','unspecified')},
                'geometry':original['geometry']})
    unmatched=[{'source_code':code,'reasons':sorted(set(reasons.get(code,()))|{'unexpected-source-code'})}
               for code in sorted((index.keys()|set(report['retention']['missing_source_codes']))-expected.keys())]
    areas=[]
    by_city=defaultdict(list)
    for row in coverage:by_city[row['citymun_code']].append(row)
    for city in sorted(cities):
        members=by_city[city];count=len(shards[city]);missing=len(members)-count
        status='no-reference-members' if not members else 'no-candidate' if count==0 else 'partial-candidate' if missing else 'complete-candidate'
        areas.append({'citymun_code':city,'reference_count':len(members),'candidate_count':count,
                      'withheld_count':missing,'status':status})
    summary={'reference_barangays':len(expected),'candidate_polygons':sum(len(s) for s in shards.values()),
             'withheld_reference_barangays':sum(r['state']=='withheld' for r in coverage),
             'primary_reasons':dict(Counter(r['primary_reason'] for r in coverage if r['primary_reason'])),
             'municipalities':len(cities),'municipality_states':dict(Counter(a['status'] for a in areas)),
             'unmatched_source_records':len(unmatched),
             'quarantined_overlap_source_codes':len({code for code,why in reasons.items() if 'overlap-conflict' in why})}
    return shards,coverage,unmatched,areas,summary


def package(candidate, reference_path, report_path, candidate_hash, reference_hash, report_hash, release, output):
    for path,expected in [(candidate,candidate_hash),(reference_path,reference_hash),(report_path,report_hash)]:
        if digest(path)!=expected:raise ValueError('Pinned input checksum mismatch')
    report=json.loads(Path(report_path).read_text())
    if report['provenance']['candidate_sha256']!=candidate_hash or report['provenance']['reference_sha256']!=reference_hash:
        raise ValueError('Validation report belongs to different inputs')
    if not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._-]{0,100}',release) or 'latest' in release.lower():
        raise ValueError('Use an immutable release identifier')
    output=Path(output)
    public=Path(__file__).resolve().parent.parent/'public'
    if output.resolve().is_relative_to(public):raise ValueError('Candidate packages cannot be written to public assets')
    if output.exists():raise ValueError('Use a new private output directory')
    collection=json.loads(Path(candidate).read_text())
    if collection.get('type')!='FeatureCollection':raise ValueError('Expected candidate FeatureCollection')
    reference=json.loads(Path(reference_path).read_text())
    shards,coverage,unmatched,areas,summary=partition(collection['features'],reference,report)
    output.mkdir();(output/'citymun').mkdir()
    for area in areas:
        city=area['citymun_code'];features=shards[city]
        if not features:
            area.update(file=None,sha256=None,bytes=0);continue
        relative=f'citymun/{city}.json';path=output/relative
        with path.open('x') as f:json.dump({'type':'FeatureCollection','features':features},f,separators=(',',':'),sort_keys=True)
        area.update(file=relative,sha256=digest(path),bytes=path.stat().st_size)
    with (output/'coverage.json').open('x') as f:
        json.dump({'reference':coverage,'unmatched_source':unmatched},f,separators=(',',':'),sort_keys=True)
    manifest={'schema_version':1,'release':release,'publication_ready':False,'dataset_boundary_versions':{},
              'historical_compatibility':'not-reviewed','attribution':ATTRIBUTION,'summary':summary,'areas':areas,
              'coverage_file':'coverage.json','coverage_sha256':digest(output/'coverage.json'),
              'provenance':{'candidate_sha256':candidate_hash,'reference_sha256':reference_hash,'validation_sha256':report_hash,
                            'source_sha256':report['provenance']['source_sha256']},
              'scope':'Private assessment package. Complete-candidate means reference membership has geometry, not historical or production acceptance.'}
    with (output/'manifest.json').open('x') as f:json.dump(manifest,f,indent=2);f.write('\n')
    return manifest


def verify_package(directory, candidate, reference_path, manifest_hash):
    root=Path(directory);manifest_path=root/'manifest.json'
    if digest(manifest_path)!=manifest_hash:raise ValueError('Manifest checksum mismatch')
    manifest=json.loads(manifest_path.read_text())
    if manifest['schema_version']!=1 or manifest['publication_ready'] is not False or manifest['dataset_boundary_versions']:
        raise ValueError('Expected an unpublished candidate package')
    if digest(candidate)!=manifest['provenance']['candidate_sha256'] or digest(reference_path)!=manifest['provenance']['reference_sha256']:
        raise ValueError('Package inputs changed')
    if manifest['coverage_file']!='coverage.json' or digest(root/'coverage.json')!=manifest['coverage_sha256']:
        raise ValueError('Coverage checksum/path mismatch')
    coverage=json.loads((root/'coverage.json').read_text())['reference']
    reference=json.loads(Path(reference_path).read_text());expected=reference_index(reference)
    if len(coverage)!=len(expected) or {r['geo_code'] for r in coverage}!=set(expected):
        raise ValueError('Coverage must retain every reference code exactly once')
    for row in coverage:
        if row['citymun_code']!=expected[row['geo_code']]['citymun_code'] or row['state'] not in ('candidate-only','withheld'):
            raise ValueError('Invalid coverage parent/state')
        if (row['state']=='candidate-only') != (not row['reasons']):raise ValueError('Coverage reasons/state mismatch')
    original={supplier_code(f['properties']['adm4_psgc']):f for f in json.loads(Path(candidate).read_text())['features']}
    cities={r['geo_code'] for r in reference if r.get('geo_level')=='citymun'}
    if len(manifest['areas'])!=len(cities) or {a['citymun_code'] for a in manifest['areas']}!=cities:
        raise ValueError('Area manifest does not match reference municipalities')
    seen=set();features=[];sizes=[]
    for area in manifest['areas']:
        city=area['citymun_code'];members=[r for r in coverage if r['citymun_code']==city]
        wanted={r['geo_code'] for r in members if r['state']=='candidate-only'}
        if (area['reference_count'],area['candidate_count'],area['withheld_count'])!=(len(members),len(wanted),len(members)-len(wanted)):
            raise ValueError('Area counts do not match complete coverage')
        if not wanted:
            if area['file'] is not None:raise ValueError('Empty area must not claim geometry')
            continue
        if area['file']!=f'citymun/{city}.json':raise ValueError('Unexpected shard path')
        path=root/area['file']
        if digest(path)!=area['sha256'] or path.stat().st_size!=area['bytes']:raise ValueError('Shard hash/size mismatch')
        shard=json.loads(path.read_text())
        if shard.get('type')!='FeatureCollection':raise ValueError('Invalid shard collection')
        found=set()
        for feature in shard['features']:
            code=feature['properties']['geo_code']
            if code in seen or code not in wanted:raise ValueError('Duplicate/unexpected shard code')
            if feature['geometry']!=original[code]['geometry']:raise ValueError('Shard changed source candidate geometry')
            seen.add(code);found.add(code)
            features.append((code,{'properties':original[code]['properties'],'geometry':feature['geometry']}))
        if found!=wanted:raise ValueError('Shard lost a candidate polygon')
        sizes.append(area['bytes'])
    if seen!={r['geo_code'] for r in coverage if r['state']=='candidate-only'}:raise ValueError('Candidate coverage mismatch')
    checked=assess(features,reference)
    if checked['positive_area_overlaps'] or checked['invalid_source_geometry'] or checked['parent_mismatches']:
        raise ValueError('Cross-shard geometry checks failed')
    return {'all_hashes_match':True,'all_packaged_geometry_unchanged':True,'cross_shard_overlap_pairs':0,
            'files':len(sizes),'total_geometry_bytes':sum(sizes),'largest_file_bytes':max(sizes,default=0),
            'median_file_bytes':statistics.median(sizes) if sizes else 0,'coverage_bytes':(root/'coverage.json').stat().st_size,
            'publication_ready':False}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='command',required=True)
    build=sub.add_parser('build')
    for name in ('candidate','candidate-sha256','reference','reference-sha256','validation','validation-sha256','release','output'):
        build.add_argument('--'+name,required=True)
    verify=sub.add_parser('verify')
    for name in ('package','candidate','reference','manifest-sha256'):verify.add_argument('--'+name,required=True)
    args=parser.parse_args()
    try:
        if args.command=='verify':
            result=verify_package(args.package,args.candidate,args.reference,args.manifest_sha256)
        else:
            result=package(args.candidate,args.reference,args.validation,args.candidate_sha256,args.reference_sha256,args.validation_sha256,args.release,args.output)['summary']
        print(json.dumps(result))
    except (ValueError,KeyError,TypeError,AttributeError,OSError) as error:parser.error(str(error))


if __name__=='__main__':main()
