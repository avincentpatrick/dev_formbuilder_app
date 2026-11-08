import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from build_reviewed_district_boundaries import build


def polygon(x=120):
    return {'type': 'Polygon', 'coordinates': [[[x, 10], [x+1, 10], [x+1, 11], [x, 11], [x, 10]]]}


def fixture():
    keys = [{'geo_level': 'citymun', 'geo_code': '0100101'}, {'geo_level': 'citymun', 'geo_code': '0100102'}]
    roster = [{'district_code': 'synthetic-lone', 'district_name': 'Synthetic only', 'congress_no': 20,
               'boundary_vintage': 'test-2026', 'review_ref': 'synthetic-review',
               'membership_complete': True, 'expected_members': keys}]
    members = [dict(key, district_code='synthetic-lone', status='auto', superseded_by=None,
                    source_ref='synthetic-source', corroboration='single_source') for key in keys]
    entry = {'level': 'citymun', 'release': 'test-v1', 'boundary_vintage': 'test-2026',
             'source': 'synthetic fixture', 'license': 'test only', 'sha256': 'test'}
    collection = {'type': 'FeatureCollection', 'features': [
        {'type': 'Feature', 'properties': {'geo_code': key['geo_code']}, 'geometry': polygon(120+i)}
        for i, key in enumerate(keys)]}
    manifest = {'schema_version': 1, 'release': 'test-v1', 'boundary_vintage': 'test-2026',
                'review_ref': 'synthetic-review', 'congress_no': 20,
                'roster': {'sha256': 'test'}, 'members': {'sha256': 'test'}}
    return manifest, roster, members, [(entry, collection)]


class ReviewedDistrictTest(unittest.TestCase):
    def test_complete_adjacent_members_dissolve_without_losing_provenance(self):
        output, report = build(*fixture())
        self.assertTrue(report['complete'])
        self.assertEqual(len(output['features']), 1)
        props = output['features'][0]['properties']
        self.assertEqual(props['member_count'], 2)
        self.assertEqual(props['corroboration'], ['single_source'])
        self.assertTrue(props['geometry_complete'])

    def test_missing_member_cannot_pass_even_when_remaining_shapes_exist(self):
        args = fixture()
        args[2].pop()
        output, report = build(*args)
        self.assertFalse(output['features'])
        self.assertEqual(report['districts'][0]['missing_members'], [['citymun', '0100102']])

    def test_missing_null_duplicate_and_self_intersecting_shapes_block_entire_district(self):
        for mode in ('missing', 'null', 'duplicate', 'bowtie'):
            args = fixture()
            features = args[3][0][1]['features']
            if mode == 'missing':
                features.pop()
            elif mode == 'null':
                features[1]['geometry'] = None
            elif mode == 'duplicate':
                features.append(copy.deepcopy(features[0]))
            else:
                features[1]['geometry']['coordinates'] = [[[121,10],[122,11],[122,10],[121,11],[121,10]]]
            output, report = build(*args)
            self.assertFalse(output['features'], mode)
            self.assertFalse(report['complete'], mode)

    def test_unaccepted_duplicate_conflicting_and_superseded_members_block(self):
        for field, value in [('status', 'rejected'), ('superseded_by', 123), ('source_ref', ''), ('corroboration', 'conflict')]:
            args = fixture()
            args[2][0][field] = value
            self.assertFalse(build(*args)[0]['features'])
        args = fixture()
        args[2].append(copy.deepcopy(args[2][0]))
        self.assertFalse(build(*args)[0]['features'])

    def test_barangay_polygons_supported_but_points_are_not(self):
        args = fixture()
        args[3][0][0]['level'] = 'barangay'
        for i in range(2):
            for row in (args[1][0]['expected_members'][i], args[2][i]):
                row.update(geo_level='barangay', geo_code=f'01001010{i+1}')
            args[3][0][1]['features'][i]['properties']['geo_code'] = f'01001010{i+1}'
        self.assertTrue(build(*args)[1]['complete'])
        args[3][0][1]['features'][0]['geometry'] = {'type': 'Point', 'coordinates': [120,10]}
        self.assertFalse(build(*args)[0]['features'])

    def test_positive_area_overlap_blocks_but_shared_edges_are_allowed(self):
        args = fixture()
        args[3][0][1]['features'][1]['geometry'] = polygon(120.5)
        self.assertIn('overlapping-member-geometries', build(*args)[1]['districts'][0]['reasons'])

    def test_cross_district_duplicate_assignments_and_geometry_overlap_block_both(self):
        for same_code in (True, False):
            args = fixture()
            second = copy.deepcopy(args[1][0])
            second['district_code'] = 'synthetic-other'
            args[1].append(second)
            for i, row in enumerate(copy.deepcopy(args[2])):
                row['district_code'] = 'synthetic-other'
                if not same_code:
                    new = f'020010{i+1}'
                    row['geo_code'] = new
                    second['expected_members'][i]['geo_code'] = new
                    feature = copy.deepcopy(args[3][0][1]['features'][i])
                    feature['properties']['geo_code'] = new
                    args[3][0][1]['features'].append(feature)
                args[2].append(row)
            output, report = build(*args)
            self.assertFalse(output['features'])
            self.assertTrue(all(d['reasons'] for d in report['districts']))

    def test_small_islands_remain_in_multipolygon_output(self):
        args = fixture()
        args[3][0][1]['features'][1]['geometry'] = polygon(123)
        output, report = build(*args)
        self.assertTrue(report['complete'])
        self.assertEqual(output['features'][0]['geometry']['type'], 'MultiPolygon')
        self.assertEqual(len(output['features'][0]['geometry']['coordinates']), 2)

    def test_vintage_unreviewed_roster_and_moving_release_fail(self):
        for target, key, value in [(0,'release','latest'),(1,'membership_complete',False),(1,'congress_no',19),(1,'boundary_vintage','older')]:
            args = fixture()
            (args[0] if target == 0 else args[1][0])[key] = value
            with self.assertRaises(ValueError):
                build(*args)

    def test_cli_checks_hashes_emits_deterministic_candidates_and_refuses_overwrite(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            manifest, roster, members, sources = fixture()
            def save(name, value):
                raw = json.dumps(value).encode()
                (root/name).write_bytes(raw)
                return {'path': name, 'sha256': hashlib.sha256(raw).hexdigest()}
            manifest['roster'] = save('roster.json', roster)
            manifest['members'] = save('members.json', members)
            manifest['sources'] = [{**sources[0][0], **save('source.json', sources[0][1])}]
            (root/'manifest.json').write_text(json.dumps(manifest))
            cmd = [sys.executable, str(Path(__file__).with_name('build_reviewed_district_boundaries.py')), '--manifest',str(root/'manifest.json'),'--output',str(root/'out.json'),'--report',str(root/'report.json')]
            self.assertEqual(subprocess.run(cmd, capture_output=True).returncode, 0)
            report = json.loads((root/'report.json').read_text())
            self.assertEqual(report['output_sha256'], hashlib.sha256((root/'out.json').read_bytes()).hexdigest())
            self.assertEqual(subprocess.run(cmd, capture_output=True).returncode, 2)
            members.pop()
            manifest['members'] = save('members.json', members)
            (root/'manifest.json').write_text(json.dumps(manifest))
            incomplete = cmd.copy()
            incomplete[-3] = str(root/'incomplete.json')
            incomplete[-1] = str(root/'incomplete-report.json')
            self.assertEqual(subprocess.run(incomplete, capture_output=True).returncode, 1)
            self.assertEqual(json.loads((root/'incomplete.json').read_text())['features'], [])
            (root/'members.json').write_text('[]')
            cmd[-3] = str(root/'second.json')
            result = subprocess.run(cmd, capture_output=True, text=True)
            self.assertEqual(result.returncode, 2)
            self.assertIn('SHA256 mismatch', result.stderr)


if __name__ == '__main__':
    unittest.main()
