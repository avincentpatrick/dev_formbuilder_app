import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile

import shapefile
from shapely.geometry import Polygon, box
from compare_barangay_topology import compare, overlap_pairs, pair_relationship
from prepare_barangay_topology_trial import prepare


class BarangayTopologyTest(unittest.TestCase):
    def test_source_overlap_touch_and_disjoint_are_distinct(self):
        a=box(0,0,1,1)
        self.assertEqual(pair_relationship(a,box(.5,0,1.5,1)),'overlap-present-upstream')
        self.assertEqual(pair_relationship(a,box(1,0,2,1)),'touching-upstream')
        self.assertEqual(pair_relationship(a,box(2,0,3,1)),'disjoint-upstream')

    def test_missing_and_invalid_geometry_are_unresolved_never_repaired(self):
        a=box(0,0,1,1)
        invalid=Polygon([(0,0),(1,1),(1,0),(0,1),(0,0)])
        self.assertEqual(pair_relationship(a,None),'unresolved-source-geometry')
        self.assertEqual(pair_relationship(a,invalid),'unresolved-source-geometry')

    def test_hole_is_not_treated_as_overlapping_land(self):
        outer=Polygon([(0,0),(3,0),(3,3),(0,3),(0,0)],holes=[[(1,1),(2,1),(2,2),(1,2),(1,1)]])
        self.assertEqual(pair_relationship(outer,box(1.1,1.1,1.9,1.9)),'disjoint-upstream')

    def test_pair_scope_and_missing_or_duplicate_sources_are_preserved(self):
        pairs=[('0100101001','0100101002'),('0100101001','0100102001')]
        records={'0100101001':[{'geometry':box(0,0,1,1),'parent':'0100101000'}],
                 '0100101002':[{'geometry':box(1,0,2,1),'parent':'0100101000'}],
                 '0100102001':[{'geometry':box(1,0,2,1),'parent':'0100102000'}]}
        report=compare(pairs,records)
        self.assertEqual(report['by_parent_scope']['different-source-municipalities']['touching-upstream'],1)
        self.assertFalse(report['publication_ready'])
        records['0100101002']*=2
        del records['0100102001']
        self.assertEqual(compare(pairs,records)['relationships'],{'missing-or-duplicate-upstream-code':2})

    def test_report_requires_exact_codes_and_unique_pairs(self):
        for pairs in ([['100101001','0100101002']],[['0100101001','0100101002'],['0100101002','0100101001']]):
            with self.assertRaises(ValueError): overlap_pairs({'positive_area_overlaps':pairs})

    def test_cli_verifies_pins_preserves_crs_and_refuses_overwrite(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); base=root/'fixture'
            with shapefile.Writer(str(base)) as w:
                w.field('adm4_psgc','N',18,0);w.field('adm3_psgc','N',18,0);w.field('geo_level','C');w.field('geom_src','C')
                for i in range(2):
                    w.poly([[(i,0),(i,1),(i+1,1),(i+1,0),(i,0)]])
                    w.record(100101001+i*1000,100101000+i*1000,'Bgy','synthetic')
            (root/'fixture.prj').write_text('Synthetic common native CRS (test only)')
            archive=root/'source.zip'
            with zipfile.ZipFile(archive,'w') as z:
                for ext in ('shp','shx','dbf','prj'): z.write(root/f'fixture.{ext}',f'fixture.{ext}')
            candidate=root/'candidate.json';candidate.write_text(json.dumps({'positive_area_overlaps':[['0100101001','0100102001']],'provenance':{'fixture':True}}))
            cmd=[sys.executable,str(Path(__file__).with_name('compare_barangay_topology.py')),
                 '--source',str(archive),'--source-sha256',hashlib.sha256(archive.read_bytes()).hexdigest(),
                 '--source-url','synthetic-fixture','--source-release','test-v1',
                 '--candidate-report',str(candidate),'--candidate-report-sha256',hashlib.sha256(candidate.read_bytes()).hexdigest(),
                 '--output',str(root/'report.json')]
            result=subprocess.run(cmd,capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr)
            report=json.loads((root/'report.json').read_text())
            self.assertEqual(report['relationships'],{'touching-upstream':1})
            self.assertIn('Synthetic',report['provenance']['source_crs_wkt'])
            self.assertEqual(subprocess.run(cmd,capture_output=True).returncode,2)
            prepared=prepare(archive,hashlib.sha256(archive.read_bytes()).hexdigest(),['0100101000','0100102000'],root/'trial')
            self.assertEqual(prepared['barangay_count'],2)
            self.assertEqual(prepared['barangay_codes'],['0100101001','0100102001'])
            self.assertEqual(prepared['polygon_components_by_code'],{'0100101001':1,'0100102001':1})
            with shapefile.Reader(str(root/'trial'/'municipality-0100101000')) as r:
                self.assertEqual(len(r),1)
                self.assertEqual(list(r.shape(0).points),[(0,0),(0,1),(1,1),(1,0),(0,0)])
            self.assertEqual((root/'trial'/'trial.prj').read_text(),'Synthetic common native CRS (test only)')
            with self.assertRaises(FileExistsError):
                prepare(archive,hashlib.sha256(archive.read_bytes()).hexdigest(),['0100101000','0100102000'],root/'trial')
            with self.assertRaises(ValueError):
                prepare(archive,'wrong',['0100101000','0100102000'],root/'bad')
            candidate.write_text('{}')
            self.assertIn('checksum mismatch',subprocess.run(cmd,capture_output=True,text=True).stderr)


if __name__ == '__main__': unittest.main()
