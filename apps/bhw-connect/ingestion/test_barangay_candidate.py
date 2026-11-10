import unittest
import hashlib
import json
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path
from reconcile_barangay_candidate import assess, supplier_code, reference_index


def polygon(x=120):
    return {'type': 'Polygon', 'coordinates': [[[x,10],[x+1,10],[x+1,11],[x,11],[x,10]]]}


def reference(code='0100101001'):
    return {'geo_code':code, 'geo_level':'barangay', 'citymun_code':code[:7], 'province_code':code[:5], 'region_code':code[:2]}


def feature(code=100101001, parent=100101000, geom=None):
    return {'properties': {'adm4_psgc':code,'adm3_psgc':parent,'geo_level':'Bgy','geom_src':'source'}, 'geometry':geom or polygon()}


class BarangayCandidateTest(unittest.TestCase):
    def test_supplier_padding_is_explicit_and_reference_codes_never_guessed(self):
        self.assertEqual(supplier_code(100101001),'0100101001')
        for value in (True, 100101001.0, '100101001.0', '1e8', 0, '12345678901'):
            with self.assertRaises(ValueError): supplier_code(value)
        with self.assertRaises(ValueError): reference_index([reference('100101001')])

    def test_same_code_is_not_historical_acceptance(self):
        report=assess([('test',feature())],[reference()])
        self.assertEqual(report['summary']['candidate_usable'],1)
        self.assertFalse(report['publication_ready'])
        self.assertIn('not-established',report['historical_compatibility'])

    def test_missing_unmatched_and_null_shapes_are_distinct(self):
        null=feature(100101002);null['geometry']=None
        report=assess([('a',feature()),('b',null)],[reference(),reference('0100101003')])
        self.assertEqual(report['missing_reference_codes'],['0100101003'])
        self.assertEqual(report['unmatched_source_codes'],['0100101002'])
        self.assertEqual(report['invalid_source_geometry'],[{'code':'0100101002','reason':'null-geometry'}])

    def test_submunicipal_polygons_are_not_counted_as_barangays(self):
        sub=feature();sub['properties']['geo_level']='SubMun'
        report=assess([('b',feature()),('s',sub)],[reference()])
        self.assertEqual(report['summary']['source_barangay_features'],1)
        self.assertEqual(report['non_barangay_features'],{'SubMun':1})

    def test_duplicate_codes_parent_mismatch_and_self_intersection_block_candidates(self):
        bad=feature(100101002,100102000)
        bad['geometry']['coordinates']=[[[120,10],[121,11],[121,10],[120,11],[120,10]]]
        report=assess([('a',feature()),('b',feature()),('c',bad)],[reference(),reference('0100101002')])
        self.assertEqual(report['summary']['candidate_usable'],0)
        self.assertEqual(report['duplicate_source_codes'],['0100101001'])
        self.assertEqual(len(report['parent_mismatches']),1)
        self.assertEqual(len(report['invalid_source_geometry']),1)

    def test_overlap_with_unmatched_neighbour_blocks_matching_candidate(self):
        report=assess([('a',feature()),('b',feature(100101002,geom=polygon(120.5)))],[reference()])
        self.assertEqual(report['positive_area_overlaps'],[['0100101001','0100101002']])
        self.assertEqual(report['summary']['candidate_usable'],0)

    def test_shared_edges_are_not_overlaps(self):
        report=assess([('a',feature()),('b',feature(100101002,geom=polygon(121)))],[reference(),reference('0100101002')])
        self.assertEqual(report['summary']['candidate_usable'],2)

    def test_manila_hierarchy_rule_preserves_raw_parent_and_excludes_submunicipality_shapes(self):
        report=assess([('a',feature(1380601001,1380600000))],[reference('1380601001')])
        self.assertEqual(report['summary']['candidate_usable'],1)
        self.assertEqual(report['parent_mismatches'],[])
        self.assertEqual(report['hierarchy_adaptations'],[{'code':'1380601001','source_parent':'1380600','normalized_parent':'1380601'}])

    def test_cli_hashes_and_never_claims_publication_readiness(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            ref=root/'reference.json';ref.write_text(json.dumps([reference()]))
            archive=root/'source.zip'
            with zipfile.ZipFile(archive,'w') as z:
                z.writestr('geojson/municities/hires/bgysubmuns-municity-100101000.0.1.json',json.dumps({'type':'FeatureCollection','features':[feature()]}))
            cmd=[sys.executable,str(Path(__file__).with_name('reconcile_barangay_candidate.py')),
                 '--archive',str(archive),'--sha256',hashlib.sha256(archive.read_bytes()).hexdigest(),
                 '--reference',str(ref),'--reference-sha256',hashlib.sha256(ref.read_bytes()).hexdigest(),
                 '--release','synthetic-v1','--source','synthetic-fixture','--reference-description','Synthetic only',
                 '--output',str(root/'report.json')]
            self.assertEqual(subprocess.run(cmd,capture_output=True).returncode,0)
            self.assertFalse(json.loads((root/'report.json').read_text())['publication_ready'])
            self.assertEqual(subprocess.run(cmd,capture_output=True).returncode,2)
            ref.write_text('[]')
            failed=subprocess.run(cmd,capture_output=True,text=True)
            self.assertEqual(failed.returncode,2)
            self.assertIn('checksum mismatch',failed.stderr)

    def test_sga_and_nir_changes_remain_missing_without_an_evidenced_crosswalk(self):
        report=assess([],[reference('1999901001'),reference('1804501001')])
        self.assertEqual(report['special_cases']['SGA']['missing'],1)
        self.assertEqual(report['special_cases']['NIR']['missing'],1)


if __name__=='__main__': unittest.main()
