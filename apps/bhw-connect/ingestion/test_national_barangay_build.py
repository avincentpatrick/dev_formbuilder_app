import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile
import shapefile
from validate_national_barangay_build import geometry_inventory, retention, validate


def polygon(x=120):return {'type':'Polygon','coordinates':[[[x,10],[x+1,10],[x+1,11],[x,11],[x,10]]]}
def feature(code='0100101001',geometry=None):return {'properties':{'adm4_psgc':int(code),'adm3_psgc':100101000,'geo_level':'Bgy'},'geometry':geometry or polygon()}
def ref(code='0100101001'):return {'geo_code':code,'geo_level':'barangay','citymun_code':'0100101','province_code':'01001','region_code':'01'}


class NationalBarangayBuildTest(unittest.TestCase):
    def test_passing_automated_checks_is_not_publication_acceptance(self):
        source={'0100101001':geometry_inventory(polygon())}
        r=validate(source,[feature()],[ref()])
        self.assertTrue(r['automated_checks_pass'])
        self.assertFalse(r['publication_ready'])

    def test_lost_island_and_hole_block_even_when_code_is_retained(self):
        island={'type':'MultiPolygon','coordinates':[polygon()['coordinates'],polygon(123)['coordinates']]}
        hole=copy.deepcopy(polygon());hole['coordinates'].append([[120.2,10.2],[120.2,10.3],[120.3,10.3],[120.3,10.2],[120.2,10.2]])
        for source,check in [(island,'lost_components'),(hole,'lost_holes')]:
            r=validate({'0100101001':geometry_inventory(source)},[feature()],[ref()])
            self.assertIn(check,r['blocking_checks'])

    def test_new_islands_are_not_silently_accepted(self):
        island={'type':'MultiPolygon','coordinates':[polygon()['coordinates'],polygon(123)['coordinates']]}
        r=validate({'0100101001':geometry_inventory(polygon())},[feature(geometry=island)],[ref()])
        self.assertIn('gained_components',r['blocking_checks'])

    def test_missing_duplicate_and_unexpected_codes_block(self):
        source={'0100101001':geometry_inventory(polygon()),'0100101002':geometry_inventory(polygon(122))}
        r=retention(source,[feature(),feature(),feature('0100101003',polygon(124))])
        self.assertEqual(r['missing_source_codes'],['0100101002'])
        self.assertEqual(r['duplicate_candidate_codes'],['0100101001'])
        self.assertEqual(r['unexpected_candidate_codes'],['0100101003'])

    def test_source_null_is_never_permission_to_invent_a_polygon(self):
        r=validate({'0100101001':geometry_inventory(None)},[feature()],[ref()])
        self.assertIn('source_invalid_codes',r['blocking_checks'])

    def test_new_self_intersection_is_tracked(self):
        bad={'type':'Polygon','coordinates':[[[120,10],[121,11],[121,10],[120,11],[120,10]]]}
        r=validate({'0100101001':geometry_inventory(polygon())},[feature(geometry=bad)],[ref()])
        self.assertEqual(r['retention']['newly_invalid_codes'],['0100101001'])
        self.assertIn('candidate_invalid_codes',r['blocking_checks'])

    def test_overlaps_and_reference_gaps_remain_blockers(self):
        source={c:geometry_inventory(polygon()) for c in ('0100101001','0100101002')}
        r=validate(source,[feature(),feature('0100101002')],[ref(),ref('0100101002'),ref('0100101003')])
        self.assertIn('positive_area_overlaps',r['blocking_checks'])
        self.assertIn('missing_reference_codes',r['blocking_checks'])

    def test_cli_pins_and_nonzero_failed_gate_with_report(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            with shapefile.Writer(str(root/'source')) as w:
                w.field('adm4_psgc','N',18,0);w.field('geo_level','C')
                w.poly([[(120,10),(120,11),(121,11),(121,10),(120,10)]]);w.record(100101001,'Bgy')
            archive=root/'source.zip'
            with zipfile.ZipFile(archive,'w') as z:
                for ext in ('shp','shx','dbf'):z.write(root/f'source.{ext}',f'source.{ext}')
            candidate=root/'candidate.json';candidate.write_text(json.dumps({'type':'FeatureCollection','features':[feature()]}))
            reference=root/'reference.json';reference.write_text(json.dumps([ref(),ref('0100101002')]))
            cmd=[sys.executable,str(Path(__file__).with_name('validate_national_barangay_build.py'))]
            for name,path in [('source',archive),('candidate',candidate),('reference',reference)]:
                cmd+=['--'+name,str(path),'--'+name+'-sha256',hashlib.sha256(path.read_bytes()).hexdigest()]
            cmd+=['--output',str(root/'report.json')]
            result=subprocess.run(cmd,capture_output=True,text=True)
            self.assertEqual(result.returncode,1,result.stderr)
            self.assertFalse(json.loads((root/'report.json').read_text())['automated_checks_pass'])
            self.assertEqual(subprocess.run(cmd,capture_output=True).returncode,2)
            candidate.write_text('{}')
            self.assertIn('checksum mismatch',subprocess.run(cmd,capture_output=True,text=True).stderr)


if __name__=='__main__':unittest.main()
