import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from package_barangay_candidates import partition, package, verify_package
from validate_national_barangay_build import geometry_inventory,validate


def polygon(x=120):return {'type':'Polygon','coordinates':[[[x,10],[x+1,10],[x+1,11],[x,11],[x,10]]]}
def feature(code='0100101001',x=120):return {'type':'Feature','properties':{'geo_level':'Bgy','adm4_psgc':int(code),'adm3_psgc':100101000,'geom_src':'synthetic'},'geometry':polygon(x)}
def ref(code='0100101001'):return {'geo_code':code,'geo_level':'barangay','citymun_code':'0100101','province_code':'01001','region_code':'01'}
def reference():return [{'geo_code':'0100101','geo_level':'citymun'},{'geo_code':'0100102','geo_level':'citymun'},ref(),ref('0100101002')]
def report(features,refs):
    source={str(f['properties']['adm4_psgc']).zfill(10):geometry_inventory(f['geometry']) for f in features}
    return validate(source,features,refs)


class BarangayPackageTest(unittest.TestCase):
    def test_complete_reference_includes_missing_and_empty_municipality(self):
        features=[feature()];refs=reference();r=report(features,refs)
        shards,coverage,unmatched,areas,summary=partition(features,refs,r)
        self.assertEqual(summary['reference_barangays'],2)
        self.assertEqual(summary['candidate_polygons'],1)
        self.assertEqual(coverage[1]['primary_reason'],'no-matched-source-code')
        self.assertEqual(areas[0]['status'],'partial-candidate')
        self.assertEqual(areas[1]['status'],'no-reference-members')
        self.assertEqual(shards['0100101'][0]['geometry'],features[0]['geometry'])
        self.assertEqual(unmatched,[])

    def test_both_overlap_endpoints_withheld_even_if_one_is_not_in_reference(self):
        features=[feature(),feature('0100101003',120.5)];refs=reference();r=report(features,refs)
        shards,coverage,unmatched,areas,summary=partition(features,refs,r)
        self.assertEqual(shards['0100101'],[])
        self.assertIn('overlap-conflict',coverage[0]['reasons'])
        self.assertIn('overlap-conflict',unmatched[0]['reasons'])
        self.assertEqual(summary['quarantined_overlap_source_codes'],2)
        self.assertEqual(areas[0]['status'],'no-candidate')

    def test_source_invalid_and_ring_changes_are_not_available_despite_valid_candidate(self):
        features=[feature()];refs=reference();r=report(features,refs)
        r['retention']['source_invalid_codes']=['0100101001']
        r['retention']['lost_holes']=[{'code':'0100101001','source':1,'candidate':0}]
        _,coverage,_,_,_=partition(features,refs,r)
        self.assertEqual(coverage[0]['state'],'withheld')
        self.assertIn('hole-change',coverage[0]['reasons'])
        self.assertEqual(coverage[0]['primary_reason'],'source-geometry-unavailable')

    def test_duplicate_and_bad_parent_are_independently_checked(self):
        f=feature();refs=reference();r=report([f],refs)
        self.assertEqual(partition([f,copy.deepcopy(f)],refs,r)[1][0]['primary_reason'],'duplicate-code')
        f['properties']['adm3_psgc']=100102000
        self.assertEqual(partition([f],refs,r)[1][0]['primary_reason'],'parent-mismatch')

    def test_unknown_blocker_fails_closed(self):
        features=[feature()];refs=reference();r=report(features,refs);r['blocking_checks'].append('future-check')
        with self.assertRaises(ValueError):partition(features,refs,r)

    def test_packaging_pins_report_and_shards_never_publishes_or_changes_geometry(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);features=[feature(),feature('0100101002',122)];refs=reference();r=report(features,refs)
            c=root/'candidate.json';c.write_text(json.dumps({'type':'FeatureCollection','features':features}))
            refpath=root/'reference.json';refpath.write_text(json.dumps(refs))
            sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
            r['provenance']={'candidate_sha256':sha(c),'reference_sha256':sha(refpath),'source_sha256':'a'*64}
            rp=root/'validation.json';rp.write_text(json.dumps(r))
            args=(c,refpath,rp,sha(c),sha(refpath),sha(rp),'synthetic-v1',root/'package')
            m=package(*args)
            self.assertFalse(m['publication_ready']);self.assertEqual(m['dataset_boundary_versions'],{})
            self.assertEqual(m['areas'][0]['status'],'complete-candidate')
            shard=root/'package'/m['areas'][0]['file']
            self.assertEqual(sha(shard),m['areas'][0]['sha256'])
            self.assertEqual([f['geometry'] for f in json.loads(shard.read_text())['features']],[f['geometry'] for f in features])
            self.assertEqual(sha(root/'package'/'coverage.json'),m['coverage_sha256'])
            manifest_hash=sha(root/'package'/'manifest.json')
            verified=verify_package(root/'package',c,refpath,manifest_hash)
            self.assertEqual(verified['cross_shard_overlap_pairs'],0)
            self.assertFalse(verified['publication_ready'])
            saved=shard.read_bytes();shard.write_text('{}')
            with self.assertRaises(ValueError):verify_package(root/'package',c,refpath,manifest_hash)
            shard.write_bytes(saved)
            with self.assertRaises(ValueError):package(*args)
            c.write_text('{}')
            with self.assertRaises(ValueError):package(*args)

    def test_validation_must_belong_to_same_candidate_and_reference(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);features=[feature()];refs=reference();r=report(features,refs)
            c=root/'candidate.json';c.write_text(json.dumps({'type':'FeatureCollection','features':features}))
            refpath=root/'reference.json';refpath.write_text(json.dumps(refs))
            r['provenance']={'candidate_sha256':'wrong','reference_sha256':'wrong'}
            rp=root/'validation.json';rp.write_text(json.dumps(r))
            sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
            with self.assertRaisesRegex(ValueError,'different inputs'):package(c,refpath,rp,sha(c),sha(refpath),sha(rp),'test-v1',root/'out')


if __name__=='__main__':unittest.main()
