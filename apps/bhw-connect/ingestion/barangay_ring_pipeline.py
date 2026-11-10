"""Private candidate experiment: simplify source rings as features, then reconstruct by manifest.
Never fills a missing hole, drops a missing part, repairs a shape or publishes an asset.
"""
import argparse
from collections import defaultdict
import json
from pathlib import Path
import zipfile

import shapefile
from shapely.errors import GEOSException
from shapely.geometry import shape
from reconcile_barangay_candidate import digest, supplier_code, valid_polygon


def ring_plan(code, geometry):
    if geometry is None:
        return None, [], []
    if geometry['type'] not in ('Polygon','MultiPolygon'):
        raise ValueError('Expected source Polygon/MultiPolygon')
    polygons=[geometry['coordinates']] if geometry['type']=='Polygon' else geometry['coordinates']
    plan=[]; rings=[]
    for p, polygon in enumerate(polygons):
        keys=[]
        for r, coordinates in enumerate(polygon):
            key=f'{code}:{p}:{r}'
            keys.append(key);rings.append((key,coordinates))
        if not keys:raise ValueError('Empty source polygon')
        plan.append(keys)
    return geometry['type'],plan,rings


def prepare(source, checksum, output):
    if digest(source)!=checksum:raise ValueError('Source checksum mismatch')
    output=Path(output)
    if output.exists():raise ValueError('Use a new output directory')
    with zipfile.ZipFile(source) as z:
        names=[n for n in z.namelist() if n.lower().endswith('.prj')]
        if len(names)!=1:raise ValueError('Expected one source CRS')
        projection=z.read(names[0])
    output.mkdir()
    units={};count=0
    with shapefile.Reader(str(source)) as reader, shapefile.Writer(str(output/'rings'),encoding='utf-8') as writer:
        writer.field('ring_id','C',40)
        for index,row in enumerate(reader.iterRecords()):
            props=row.as_dict()
            if props.get('geo_level')!='Bgy':continue
            code=supplier_code(props.get('adm4_psgc'))
            if code in units:raise ValueError('Duplicate source code')
            original=reader.shape(index)
            geometry=None if original.shapeType==shapefile.NULL else original.__geo_interface__
            if geometry is not None:
                source_geometry=shape(geometry)
                if not source_geometry.is_valid or source_geometry.is_empty:
                    raise ValueError('Invalid source polygon; review before preparation')
            kind,plan,rings=ring_plan(code,geometry)
            units[code]={'properties':props,'geometry_type':kind,'polygons':plan}
            for key,coords in rings:
                ring=list(coords)
                # Shapefile shells are clockwise. Reversing a ring changes no coordinates/extent.
                if shapefile.signed_area(ring)>0:ring.reverse()
                writer.poly([ring]);writer.record(key);count+=1
    (output/'rings.prj').write_bytes(projection)
    (output/'rings.cpg').write_text('UTF-8')
    manifest={'schema_version':1,'source_sha256':checksum,'units':units,'ring_count':count,
              'input_files':{p.name:digest(p) for p in sorted(output.glob('rings.*'))},
              'publication_ready':False}
    (output/'manifest.json').write_text(json.dumps(manifest,separators=(',',':'))+'\n')
    return {'barangays':len(units),'rings':count,'source_null':sum(u['geometry_type'] is None for u in units.values())}


def assemble(manifest, features):
    if manifest.get('schema_version')!=1:raise ValueError('Unsupported manifest')
    units=manifest.get('units')
    if not isinstance(units,dict) or not units:raise ValueError('Expected nonempty unit manifest')
    all_keys=[]
    for code,unit in units.items():
        if supplier_code(code)!=code or supplier_code(unit['properties'].get('adm4_psgc'))!=code:
            raise ValueError('Manifest code mismatch')
        kind=unit['geometry_type'];polygons=unit['polygons']
        if kind not in (None,'Polygon','MultiPolygon') or (kind is None and polygons) or (kind=='Polygon' and len(polygons)!=1) or (kind=='MultiPolygon' and not polygons):
            raise ValueError('Invalid manifest geometry structure')
        for polygon in polygons:
            if not polygon or any(not isinstance(k,str) for k in polygon):raise ValueError('Invalid ring identifiers')
            all_keys.extend(polygon)
    expected=set(all_keys)
    if len(expected)!=len(all_keys) or manifest.get('ring_count')!=len(expected):
        raise ValueError('Duplicate ring identifiers or inconsistent ring count')
    rows=defaultdict(list)
    for feature in features:
        key=(feature.get('properties') or {}).get('ring_id')
        if key not in expected:raise ValueError('Unexpected output ring identifier')
        rows[key].append(feature.get('geometry'))
    output=[];blocked=[]
    for code,unit in sorted(manifest['units'].items()):
        problems=[];polygons=[]
        if unit['geometry_type'] is None:problems.append('source-null')
        for keys in unit['polygons']:
            rings=[]
            for key in keys:
                matches=rows.get(key,[])
                if len(matches)!=1:
                    problems.append('missing-or-duplicate-ring');continue
                geom=matches[0]
                # Each input is exactly one ring, not a multipolygon or a newly holed polygon.
                if not valid_polygon(geom) or geom['type']!='Polygon' or len(geom['coordinates'])!=1 or not shape(geom).is_valid:
                    problems.append('invalid-or-changed-ring');continue
                rings.append(geom['coordinates'][0])
            polygons.append(rings)
        geometry=None
        if not problems:
            geometry={'type':unit['geometry_type'],'coordinates':polygons[0] if unit['geometry_type']=='Polygon' else polygons}
            geom=shape(geometry)
            if not geom.is_valid or geom.is_empty:
                problems.append('invalid-reconstruction');geometry=None
        if problems:blocked.append({'code':code,'reasons':sorted(set(problems))})
        output.append({'type':'Feature','properties':unit['properties'],'geometry':geometry})
    return {'type':'FeatureCollection','features':output}, {'records':len(output),'blocked':blocked,
             'publication_ready':False,'scope':'Private ring-retention experiment; full national validation and historical review still required.'}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='command',required=True)
    prep=sub.add_parser('prepare')
    for name in ('source','sha256','output'):prep.add_argument('--'+name,required=True)
    join=sub.add_parser('assemble')
    for name in ('manifest','manifest-sha256','rings','rings-sha256','output','report'):join.add_argument('--'+name,required=True)
    args=parser.parse_args()
    try:
        if args.command=='prepare':
            print(json.dumps(prepare(args.source,args.sha256,args.output)));return
        if digest(args.manifest)!=args.manifest_sha256 or digest(args.rings)!=args.rings_sha256:
            raise ValueError('Pinned manifest/rings checksum mismatch')
        output=Path(args.output);report_path=Path(args.report)
        if output.resolve()==report_path.resolve() or output.exists() or report_path.exists():raise ValueError('Use distinct new output/report paths')
        candidate=json.loads(Path(args.rings).read_text())
        if candidate.get('type')!='FeatureCollection':raise ValueError('Expected ring FeatureCollection')
        collection,report=assemble(json.loads(Path(args.manifest).read_text()),candidate['features'])
        with output.open('x') as f:json.dump(collection,f,separators=(',',':'))
        report['provenance']={'manifest_sha256':args.manifest_sha256,'rings_sha256':args.rings_sha256,'output_sha256':digest(output)}
        with report_path.open('x') as f:json.dump(report,f,indent=2);f.write('\n')
        print(json.dumps({'records':report['records'],'blocked':len(report['blocked'])}))
    except (ValueError,TypeError,KeyError,AttributeError,OSError,GEOSException,shapefile.ShapefileException) as error:parser.error(str(error))
    if report['blocked']:raise SystemExit(1)


if __name__=='__main__':main()
