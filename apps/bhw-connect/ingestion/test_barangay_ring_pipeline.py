import copy
import hashlib
import json
from pathlib import Path
import tempfile
import subprocess
import sys
import unittest
import zipfile
import shapefile
from shapely.geometry import shape
from barangay_ring_pipeline import assemble, prepare, ring_plan


def square(x=120,size=1):return [[x,10],[x+size,10],[x+size,10+size],[x,10+size],[x,10]]

def fixture():
    code='0100101001'
    hole=[[120.2,10.2],[120.3,10.2],[120.3,10.3],[120.2,10.3],[120.2,10.2]]
    geometry={'type':'MultiPolygon','coordinates':[[square(),hole],[square(123)]]}
    kind,plan,rings=ring_plan(code,geometry)
    manifest={'schema_version':1,'ring_count':len(rings),'units':{code:{'properties':{'adm4_psgc':100101001,'geo_level':'Bgy'},'geometry_type':kind,'polygons':plan}}}
    features=[{'properties':{'ring_id':key},'geometry':{'type':'Polygon','coordinates':[coords]}} for key,coords in rings]
    return manifest,features


class BarangayRingPipelineTest(unittest.TestCase):
    def test_preserves_parts_and_holes_without_treating_holes_as_land(self):
        manifest,features=fixture();out,report=assemble(manifest,features)
        geom=shape(out['features'][0]['geometry'])
        self.assertEqual(len(geom.geoms),2)
        self.assertEqual(sum(len(g.interiors) for g in geom.geoms),1)
        self.assertAlmostEqual(geom.area,1.99)
        self.assertEqual(report['blocked'],[])
        self.assertFalse(report['publication_ready'])

    def test_missing_hole_or_island_blocks_entire_barangay(self):
        for index in (1,2):
            manifest,features=fixture();features.pop(index)
            out,report=assemble(manifest,features)
            self.assertIsNone(out['features'][0]['geometry'])
            self.assertEqual(report['blocked'][0]['reasons'],['missing-or-duplicate-ring'])

    def test_duplicate_ring_and_changed_ring_structure_block(self):
        manifest,features=fixture();features.append(copy.deepcopy(features[0]))
        self.assertIsNone(assemble(manifest,features)[0]['features'][0]['geometry'])
        manifest,features=fixture();features[0]['geometry']['coordinates'].append(square(124))
        self.assertIn('invalid-or-changed-ring',assemble(manifest,features)[1]['blocked'][0]['reasons'])

    def test_hole_outside_shell_is_not_filled_or_repaired(self):
        manifest,features=fixture();features[1]['geometry']['coordinates']=[square(125)]
        out,report=assemble(manifest,features)
        self.assertIsNone(out['features'][0]['geometry'])
        self.assertIn('invalid-reconstruction',report['blocked'][0]['reasons'])

    def test_unexpected_ring_and_corrupt_manifest_fail(self):
        manifest,features=fixture();features[0]['properties']['ring_id']='unknown'
        with self.assertRaises(ValueError):assemble(manifest,features)
        manifest,features=fixture();manifest['units']['0100101001']['polygons'][0].append('0100101001:0:0')
        with self.assertRaises(ValueError):assemble(manifest,features)

    def test_source_null_is_retained_as_unresolved(self):
        manifest={'schema_version':1,'ring_count':0,'units':{'0100101001':{'properties':{'adm4_psgc':100101001},'geometry_type':None,'polygons':[]}}}
        out,report=assemble(manifest,[])
        self.assertIsNone(out['features'][0]['geometry'])
        self.assertEqual(report['blocked'][0]['reasons'],['source-null'])

    def test_assembly_cli_checks_pins_and_reports_withheld_geometry(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);manifest,features=fixture();features.pop(1)
            inputs={'manifest':manifest,'rings':{'type':'FeatureCollection','features':features}}
            cmd=[sys.executable,str(Path(__file__).with_name('barangay_ring_pipeline.py')),'assemble']
            for name,data in inputs.items():
                p=root/(name+'.json');p.write_text(json.dumps(data))
                cmd+=['--'+name,str(p),'--'+name+'-sha256',hashlib.sha256(p.read_bytes()).hexdigest()]
            cmd+=['--output',str(root/'output.json'),'--report',str(root/'report.json')]
            result=subprocess.run(cmd,capture_output=True,text=True)
            self.assertEqual(result.returncode,1,result.stderr)
            self.assertIsNone(json.loads((root/'output.json').read_text())['features'][0]['geometry'])
            self.assertFalse(json.loads((root/'report.json').read_text())['publication_ready'])
            self.assertEqual(subprocess.run(cmd,capture_output=True).returncode,2)
            (root/'rings.json').write_text('{}')
            self.assertIn('checksum mismatch',subprocess.run(cmd,capture_output=True,text=True).stderr)

    def test_prepare_pins_source_and_exports_hole_as_ring_without_coordinate_changes(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            outer=list(reversed(square()));hole=[[120.2,10.2],[120.3,10.2],[120.3,10.3],[120.2,10.3],[120.2,10.2]]
            with shapefile.Writer(str(root/'source')) as w:
                w.field('adm4_psgc','N',18,0);w.field('geo_level','C');w.poly([outer,hole]);w.record(100101001,'Bgy')
            (root/'source.prj').write_text('Synthetic test CRS')
            archive=root/'source.zip'
            with zipfile.ZipFile(archive,'w') as z:
                for ext in ('shp','shx','dbf','prj'):z.write(root/f'source.{ext}',f'source.{ext}')
            checksum=hashlib.sha256(archive.read_bytes()).hexdigest()
            self.assertEqual(prepare(archive,checksum,root/'prepared')['rings'],2)
            with shapefile.Reader(str(root/'prepared'/'rings')) as reader:
                self.assertEqual(len(reader),2)
                self.assertEqual(set(map(tuple,reader.shape(1).points)),set(map(tuple,hole)))
                self.assertTrue(shape(reader.shape(1).__geo_interface__).is_valid)
            self.assertEqual((root/'prepared'/'rings.prj').read_text(),'Synthetic test CRS')
            with self.assertRaises(ValueError):prepare(archive,checksum,root/'prepared')
            with self.assertRaises(ValueError):prepare(archive,'wrong',root/'bad')


if __name__=='__main__':unittest.main()
