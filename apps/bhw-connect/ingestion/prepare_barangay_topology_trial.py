"""Extract complete source municipalities without changing coordinates or repairing shapes.
Outputs a private shapefile trial input, never application assets.
"""
import argparse
import json
from pathlib import Path
import zipfile

import shapefile
from shapely.geometry import shape
from reconcile_barangay_candidate import digest, supplier_code


def prepare(source, checksum, municipalities, output):
    if digest(source) != checksum:
        raise ValueError('Pinned source checksum mismatch')
    parents = set(municipalities)
    if len(parents) < 2 or any(supplier_code(p) != p or not p.endswith('000') for p in parents):
        raise ValueError('Provide at least two distinct exact ten-digit municipality codes')
    selected = []
    seen = set()
    component_counts = {}
    found = set()
    with shapefile.Reader(str(source)) as reader:
        fields = reader.fields[1:]
        for index, row in enumerate(reader.iterRecords()):
            props = row.as_dict()
            if props.get('geo_level') != 'Bgy':
                continue
            parent = supplier_code(props.get('adm3_psgc'))
            if parent not in parents:
                continue
            code = supplier_code(props.get('adm4_psgc'))
            if code in seen:
                raise ValueError('Duplicate source barangay code')
            original = reader.shape(index)
            if original.shapeType == shapefile.NULL:
                raise ValueError('Null source polygon')
            geom = shape(original.__geo_interface__)
            if geom.geom_type not in ('Polygon', 'MultiPolygon') or not geom.is_valid or geom.is_empty:
                raise ValueError('Invalid source polygon; no automatic repair')
            component_counts[code] = 1 if geom.geom_type == "Polygon" else len(geom.geoms)
            selected.append((original, row, parent))
            seen.add(code)
            found.add(parent)
    if found != parents:
        raise ValueError('A requested municipality has no source barangays')
    with zipfile.ZipFile(source) as z:
        projections = [name for name in z.namelist() if name.lower().endswith('.prj')]
        if len(projections) != 1:
            raise ValueError('Expected a single source projection')
        projection = z.read(projections[0])
    output = Path(output)
    output.mkdir()  # Never reuse or overwrite an earlier trial.
    # The controls are exported BEFORE topology construction, so each simplification
    # process imports only one municipality rather than sharing the joint topology.
    groups = {'trial': selected}
    groups.update({f'municipality-{p}': [item for item in selected if item[2] == p] for p in sorted(parents)})
    for name, items in groups.items():
        with shapefile.Writer(str(output/name), encoding='utf-8') as writer:
            writer.fields = fields
            for geometry, row, _ in items:
                writer.shape(geometry)
                writer.record(*row)
        (output/f'{name}.prj').write_bytes(projection)
        (output/f'{name}.cpg').write_text('UTF-8')
    manifest = {'source_sha256': checksum, 'municipalities': sorted(parents), 'barangay_count': len(selected),
                'barangay_codes': sorted(seen), 'polygon_components_by_code': component_counts,
                'files': {p.name: digest(p) for p in sorted(output.iterdir())},
                'publication_ready': False, 'scope': 'Complete source members of selected municipalities only; source membership is not historical certification.'}
    (output/'manifest.json').write_text(json.dumps(manifest, indent=2)+'\n')
    return manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--municipality', action='append', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(prepare(args.source, args.sha256, args.municipality, args.output)))
    except (OSError, ValueError, TypeError, shapefile.ShapefileException) as error:
        parser.error(str(error))
